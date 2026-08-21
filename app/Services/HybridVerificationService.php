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

        $redirectUrl = '';
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
                // Provider error -> manual review fallback (never auto-approve).
                Capsule::table('mod_cv_verifications')
                    ->where('id', $verificationId)
                    ->update(['status' => 'under_review', 'manual_review_required' => 1]);
                cv_log_audit($verificationId, 'provider_error', 0, $e->getMessage());
            }
        } elseif ($method === 'manual') {
            Capsule::table('mod_cv_verifications')
                ->where('id', $verificationId)
                ->update(['status' => 'pending']);
        } else {
            // Hybrid but Didit not configured -> manual review.
            Capsule::table('mod_cv_verifications')
                ->where('id', $verificationId)
                ->update(['status' => 'under_review', 'manual_review_required' => 1]);
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

        Capsule::table('mod_cv_verifications')
            ->where('id', $verificationId)
            ->update([
                'didit_status' => $result->status,
                'didit_decision' => $result->decision,
            ]);

        $risk = (new RiskEngine())->evaluate($verificationId, $row->client_id, $result, $documentHashes);

        Capsule::table('mod_cv_verifications')
            ->where('id', $verificationId)
            ->update([
                'risk_score' => $risk['score'],
                'risk_level' => $risk['level'],
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

        switch ($decision) {
            case 'approve':
                if ($autoApprove) {
                    VerificationService::updateStatus($verificationId, 'approved', 0, 'auto_approved');
                    return 'approved';
                }
                VerificationService::updateStatus($verificationId, 'under_review', 0, 'pending_admin_review');
                return 'review';
            case 'reject':
                VerificationService::updateStatus($verificationId, 'rejected', 0, implode(',', $risk['flags']));
                return 'rejected';
            case 'review':
            default:
                Capsule::table('mod_cv_verifications')
                    ->where('id', $verificationId)
                    ->update(['status' => 'under_review', 'manual_review_required' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
                cv_log_audit($verificationId, 'manual_review_required', 0, implode(',', $risk['flags']));
                Notifier::reviewRequired($row->client_id);
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
