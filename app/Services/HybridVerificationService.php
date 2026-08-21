<?php

namespace ClientVerification\Services;

use ClientVerification\Mail\Notifier;
use ClientVerification\Providers\KycResult;
use ClientVerification\Providers\VerificationEntity;
use ClientVerification\Risk\RiskEngine;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Core decision engine. Implements the Manual + Didit hybrid flow:
 *
 * Local validation -> Didit configured? -> create session -> client completes
 * -> webhook -> Didit result -> local risk engine -> APPROVE / REVIEW / REJECT
 *
 * Provider errors NEVER auto-approve.
 */
class HybridVerificationService
{
    /**
     * Start a verification for a client.
     *
     * @param array $config module config values
     * @return array{verification_id:int, redirect_url:string, method:string}
     */
    public static function start(int $clientId, string $mode, array $personalData, array $config): array
    {
        $method = ($mode === 'didit') ? 'didit' : (($mode === 'manual') ? 'manual' : 'hybrid');

        // 1. Look for existing active verification for this client
        $existing = Capsule::table('mod_cv_verifications')
            ->where('client_id', $clientId)
            ->whereNotIn('status', ['rejected', 'expired'])
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            // If already approved, do not allow creating a new verification
            if ($existing->status === 'approved') {
                return [
                    'verification_id' => (int) $existing->id,
                    'redirect_url' => 'index.php?m=clientverification',
                    'method' => $existing->verification_method,
                ];
            }

            $hasDocs = Capsule::table('mod_cv_documents')->where('verification_id', $existing->id)->exists();

            // If under_review with uploaded documents or info_requested, redirect directly to status view
            if (($existing->status === 'under_review' && $hasDocs) || $existing->status === 'info_requested') {
                return [
                    'verification_id' => (int) $existing->id,
                    'redirect_url' => 'index.php?m=clientverification&action=verification&id=' . (int) $existing->id,
                    'method' => $existing->verification_method,
                ];
            }

            // Otherwise, it is an incomplete / in-progress session (no documents uploaded yet):
            // REUSE THIS EXACT EXISTING RECORD - DO NOT CREATE A NEW ID!
            $verificationId = (int) $existing->id;
            Capsule::table('mod_cv_verifications')
                ->where('id', $verificationId)
                ->update([
                    'verification_method' => $method,
                    'status' => 'pending',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $clientRef = $existing->client_ref ?: VerificationService::generateReference($verificationId, $clientId);

            // Clean up any extra orphan empty sessions for this client
            try {
                $duplicateEmptyIds = Capsule::table('mod_cv_verifications')
                    ->where('client_id', $clientId)
                    ->where('id', '!=', $verificationId)
                    ->whereNotIn('status', ['approved', 'rejected'])
                    ->pluck('id')
                    ->toArray();

                if (!empty($duplicateEmptyIds)) {
                    foreach ($duplicateEmptyIds as $dupId) {
                        if (!Capsule::table('mod_cv_documents')->where('verification_id', $dupId)->exists()) {
                            Capsule::table('mod_cv_verifications')->where('id', $dupId)->delete();
                            Capsule::table('mod_cv_personal_data')->where('verification_id', $dupId)->delete();
                        }
                    }
                }
            } catch (\Throwable $e) {}
        } else {
            // ONLY create a new record if client has NO verification record, or previous one was 'rejected' / 'expired' (or deleted)
            $verificationId = Capsule::table('mod_cv_verifications')->insertGetId([
                'client_id' => $clientId,
                'verification_method' => $method,
                'status' => 'pending',
                'submitted_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $clientRef = VerificationService::generateReference($verificationId, $clientId);
            Capsule::table('mod_cv_verifications')
                ->where('id', $verificationId)
                ->update(['client_ref' => $clientRef, 'vendor_data' => $clientRef]);

            // Store personal data separately (sensitive data isolation).
            Capsule::table('mod_cv_personal_data')->insert(array_merge(
                ['verification_id' => $verificationId, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                self::sanitizePersonal($personalData)
            ));

            cv_log_audit($verificationId, 'verification_started', 0, 'method=' . $method);
        }

        $redirectUrl = '';
        $useDidit = ($method === 'didit');
        $apiKey = $config['didit_api_key'] ?? ($config['api_key'] ?? '');
        $workflowId = $config['didit_workflow_id'] ?? ($config['workflow_id'] ?? '');

        if ($useDidit && !empty($apiKey) && !empty($workflowId)) {
            $config['api_key'] = $apiKey;
            $config['workflow_id'] = $workflowId;
            $config['webhook_secret'] = $config['didit_webhook_secret'] ?? ($config['webhook_secret'] ?? '');
            $config['callback_url'] = $config['callback_url'] ?? cv_callback_url();
            $provider = ProviderFactory::make('didit', $config);
            $entity = new VerificationEntity($verificationId, $clientId, $method, $clientRef, $personalData);
            try {
                $session = $provider->createSession($entity);
                Capsule::table('mod_cv_verifications')
                    ->where('id', $verificationId)
                    ->update([
                        'didit_session_id' => $session->sessionId,
                        'didit_vendor_data' => $clientRef,
                        'status' => 'in_progress',
                    ]);
                $redirectUrl = $session->redirectUrl;
                Notifier::started($clientId);
            } catch (\Exception $e) {
                // Provider error -> fallback to manual document upload form
                Capsule::table('mod_cv_verifications')
                    ->where('id', $verificationId)
                    ->update(['status' => 'pending', 'manual_review_required' => 1]);
                cv_log_audit($verificationId, 'provider_error', 0, $e->getMessage());
                $redirectUrl = 'index.php?m=clientverification&action=verification&id=' . $verificationId;
            }
        } else {
            // Manual mode -> direct to document upload page
            Capsule::table('mod_cv_verifications')
                ->where('id', $verificationId)
                ->update(['status' => 'pending', 'manual_review_required' => 1]);
            $redirectUrl = 'index.php?m=clientverification&action=verification&id=' . $verificationId;
        }

        return [
            'verification_id' => $verificationId,
            'redirect_url' => $redirectUrl,
            'method' => $method,
        ];
    }

    /**
     * Apply a provider result through the local risk engine + decision engine.
     */
    public static function applyResult(int $verificationId, KycResult $result, array $documentHashes = []): string
    {
        $row = VerificationService::find($verificationId);
        if (!$row) {
            return 'not_found';
        }

        // Idempotency: If already in a terminal state (approved or rejected), do not re-process or re-notify!
        if (in_array($row->status, ['approved', 'rejected'], true)) {
            return $row->status;
        }

        // Evaluate through local RiskEngine
        $risk = (new RiskEngine())->evaluate($verificationId, (int) $row->client_id, $result, $documentHashes);

        try {
            if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'risk_reasons')) {
                Capsule::schema()->table('mod_cv_verifications', function ($table) {
                    $table->text('risk_reasons')->nullable();
                    $table->text('risk_flags')->nullable();
                });
            }
        } catch (\Throwable $e) {}

        Capsule::table('mod_cv_verifications')
            ->where('id', $verificationId)
            ->update([
                'didit_status' => $result->status,
                'didit_decision' => $result->decision,
                'risk_score' => $risk['score'],
                'risk_level' => $risk['level'],
                'risk_flags' => json_encode($risk['flags'] ?? []),
                'risk_reasons' => json_encode($risk['reasons'] ?? []),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $config = cv_get_config();
        $autoApproveRaw = $config['didit_auto_approve'] ?? '1';
        $autoApprove = !in_array(strtolower((string) $autoApproveRaw), ['off', '0', '', 'no'], true);
        $decision = $risk['action'];

        // Provider error and Didit mode -> manual review, never approve.
        if ($result->decision === KycResult::DECISION_ERROR && $row->verification_method !== 'manual') {
            $decision = 'review';
        }

        // In manual mode, always require admin review (no auto approve).
        if ($row->verification_method === 'manual') {
            $decision = 'review';
        }

        // Alert admin if high risk detected
        if (($risk['level'] ?? '') === 'high') {
            Notifier::adminHighRisk($verificationId, (int) $row->client_id, (float) ($risk['score'] ?? 0), (array) ($risk['reasons'] ?? []));
        }

        switch ($decision) {
            case 'approve':
                if ($autoApprove) {
                    VerificationService::updateStatus($verificationId, 'approved', 0, 'auto_approved');
                    if ($row->verification_method === 'didit') {
                        Notifier::adminDiditCompleted($verificationId, (int) $row->client_id, 'Approved');
                    }
                    return 'approved';
                }
                VerificationService::updateStatus($verificationId, 'under_review', 0, 'pending_admin_review');
                if ($row->verification_method === 'didit') {
                    Notifier::adminDiditCompleted($verificationId, (int) $row->client_id, 'Under Review (Pending Approval)');
                }
                return 'review';

            case 'reject':
                VerificationService::updateStatus($verificationId, 'rejected', 0, implode(',', $risk['flags']));
                if ($row->verification_method === 'didit') {
                    Notifier::adminDiditCompleted($verificationId, (int) $row->client_id, 'Rejected (' . implode(', ', $risk['flags']) . ')');
                }
                return 'rejected';

            case 'review':
            default:
                VerificationService::updateStatus($verificationId, 'under_review', 0, implode(',', $risk['flags'] ?: ['review_required']));
                if ($row->verification_method === 'didit') {
                    $statusLabel = ($result->status === 'error') ? 'Under Review (Manual Inspection Required)' : 'Under Review';
                    Notifier::adminDiditCompleted($verificationId, (int) $row->client_id, $statusLabel);
                }
                OutboundWebhookShim($verificationId);
                return 'review';
        }
    }

    private static function sanitizePersonal(array $data): array
    {
        $allowed = ['first_name', 'last_name', 'date_of_birth', 'phone', 'address', 'city', 'state', 'postal_code', 'country'];
        $out = [];
        foreach ($allowed as $k) {
            $out[$k] = isset($data[$k]) ? substr(strip_tags((string) $data[$k]), 0, 255) : null;
        }
        return $out;
    }
}

/**
 * Small shim to dispatch review_required outbound webhook without import cycle issues.
 */
function OutboundWebhookShim(int $verificationId): void
{
    \ClientVerification\Webhooks\OutboundWebhook::dispatch('verification.review_required', $verificationId);
}
