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
        }

        // Provider error always escalates to review.
        if ($result->decision === KycResult::DECISION_ERROR) {
            $flags[] = 'provider_error';
            $score = max($score, 80);
        }

        if ($result->decision === KycResult::DECISION_DECLINED) {
            $flags[] = 'provider_declined';
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
