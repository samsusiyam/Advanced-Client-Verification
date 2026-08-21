<?php

namespace ClientVerification\Risk;

use ClientVerification\Providers\KycResult;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Local risk engine. Combines the provider result with local security checks.
 * Local rules MUST be able to override an "approved" provider decision
 * (e.g. duplicate document detection forces manual review).
 */
class RiskEngine
{
    /**
     * @return array{score:float,level:string,flags:array,action:string}
     * action: approve | review | reject
     */
    public function evaluate(
        int $verificationId,
        int $clientId,
        KycResult $result,
        array $documentHashes = [],
        ?float $approveThreshold = null,
        ?float $reviewThreshold = null
    ): array {
        // Honor configured thresholds; allow explicit injection for testing.
        $approveThreshold = $approveThreshold ?? (float) cv_setting('risk_threshold_approve', 30);
        $reviewThreshold = $reviewThreshold ?? (float) cv_setting('risk_threshold_review', 70);

        $score = $result->riskScore;
        $flags = [];
        $reasons = [];

        // Check if provider returned risk flags or warnings in decision payload
        $rawDecision = $result->raw['decision'] ?? ($result->raw ?? []);
        if (!empty($rawDecision) && is_array($rawDecision)) {
            // Check ID verification alerts
            if (!empty($rawDecision['id_verifications']) && is_array($rawDecision['id_verifications'])) {
                foreach ($rawDecision['id_verifications'] as $idv) {
                    if (!empty($idv['warnings']) && is_array($idv['warnings'])) {
                        foreach ($idv['warnings'] as $w) {
                            $flags[] = 'id_warning_' . ($w['code'] ?? 'issue');
                            $reasons[] = 'ID Warning: ' . ($w['message'] ?? ($w['code'] ?? 'Document inspection warning'));
                        }
                    }
                }
            }
            // Check Face match
            if (!empty($rawDecision['face_matches']) && is_array($rawDecision['face_matches'])) {
                foreach ($rawDecision['face_matches'] as $fm) {
                    if (isset($fm['matched']) && $fm['matched'] === false) {
                        $flags[] = 'face_mismatch';
                        $reasons[] = 'Facial biometric match failed: selfie did not match photo ID';
                        $score += 30;
                    }
                }
            }
            // Check AML / Sanctions
            if (!empty($rawDecision['aml_screenings']) && is_array($rawDecision['aml_screenings'])) {
                foreach ($rawDecision['aml_screenings'] as $aml) {
                    if (!empty($aml['matches'])) {
                        $flags[] = 'aml_match';
                        $reasons[] = 'AML / PEP / Sanctions watchlist match detected';
                        $score += 50;
                    }
                }
            }
        }

        // Local check: duplicate document detection.
        if (!empty($documentHashes)) {
            foreach ($documentHashes as $hash) {
                $dup = Capsule::table('mod_cv_documents')
                    ->where('sha256_hash', $hash)
                    ->where('verification_id', '!=', $verificationId)
                    ->where('status', '!=', 'rejected')
                    ->first();
                if ($dup) {
                    $flags[] = 'duplicate_document';
                    $reasons[] = 'Duplicate Document: Same file hash (SHA-256) was previously submitted on verification #' . $dup->verification_id;
                    $score += 40;
                }
            }
        }

        // Local check: client already has an approved verification.
        $existingApproved = Capsule::table('mod_cv_verifications')
            ->where('client_id', $clientId)
            ->where('id', '!=', $verificationId)
            ->where('status', 'approved')
            ->first();
        if ($existingApproved) {
            $flags[] = 'previous_verification_exists';
            $reasons[] = 'Existing Approval: Client already has another active approved verification record (#' . $existingApproved->id . ')';
        }

        // Provider error
        if ($result->decision === KycResult::DECISION_ERROR) {
            $flags[] = 'provider_error';
            $errDetail = $result->raw['error'] ?? ($result->raw['reason'] ?? 'Automated KYC provider returned an error or was unreachable');
            $reasons[] = 'Provider Error: ' . $errDetail;
            $score = max($score, 80);
        }

        if ($result->decision === KycResult::DECISION_DECLINED) {
            $flags[] = 'provider_declined';
            $reasons[] = 'Provider Declined: Automated verification criteria were not met';
            $score = max($score, 80);
        }

        $level = $this->level($score);

        $action = 'review';
        if ($result->decision === KycResult::DECISION_APPROVED && $score < $approveThreshold && !in_array('duplicate_document', $flags, true)) {
            $action = 'approve';
        } elseif ($score >= $reviewThreshold || in_array('provider_declined', $flags, true)) {
            $action = 'reject';
        } else {
            $action = 'review';
        }

        return [
            'score' => min(100, $score),
            'level' => $level,
            'flags' => $flags,
            'reasons' => $reasons,
            'action' => $action,
        ];
    }

    private function level(float $score): string
    {
        if ($score >= 70) {
            return 'high';
        }
        if ($score >= 30) {
            return 'medium';
        }
        return 'low';
    }
}
