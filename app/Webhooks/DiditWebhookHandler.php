<?php

namespace ClientVerification\Webhooks;

use ClientVerification\Providers\DiditProvider;
use ClientVerification\Providers\KycResult;
use ClientVerification\Services\HybridVerificationService;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Handles inbound Didit webhooks with full security: signature verification,
 * timestamp validation, vendor-data validation, replay protection, idempotency.
 */
class DiditWebhookHandler
{
    public static function handle(string $rawBody, array $headers, array $config): array
    {
        $provider = new DiditProvider(
            $config['didit_api_key'] ?? ($config['api_key'] ?? ''),
            $config['didit_workflow_id'] ?? ($config['workflow_id'] ?? ''),
            $config['didit_webhook_secret'] ?? ($config['webhook_secret'] ?? ''),
            $config['callback_url'] ?? cv_callback_url()
        );

        $sigHeader = self::header($headers, 'Didit-Signature');
        if (!$provider->verifyWebhook($rawBody, $sigHeader)) {
            return ['success' => false, 'error' => 'invalid_signature'];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['success' => false, 'error' => 'invalid_json'];
        }

        $sessionId = $payload['session_id'] ?? '';
        $eventId = $payload['event_id'] ?? ($payload['id'] ?? $sessionId);

        // Idempotency / replay protection. Match both completed (1) and
        // in-flight (0) events so a concurrent duplicate is rejected before it
        // can be processed (closes the TOCTOU race window).
        $existing = Capsule::table('mod_cv_webhook_events')
            ->where(function ($q) use ($eventId, $sessionId) {
                if ($eventId) {
                    $q->where('event_id', $eventId);
                }
                if ($sessionId) {
                    $q->orWhere('session_id', $sessionId);
                }
            })
            ->whereIn('processed', [0, 1])
            ->first();

        if ($existing) {
            return ['success' => true, 'error' => 'already_processed', 'status' => 'duplicate'];
        }

        // Record raw event for audit.
        $eventRowId = Capsule::table('mod_cv_webhook_events')->insertGetId([
            'event_id' => $eventId,
            'session_id' => $sessionId,
            'source' => 'didit',
            'payload' => $rawBody,
            'signature' => $sigHeader,
            'processed' => 0,
            'received_at' => date('Y-m-d H:i:s'),
        ]);

        // Vendor-data validation: map session -> verification -> client.
        $verificationId = null;
        $clientId = null;

        $vendorData = $payload['vendor_data'] ?? '';
        if ($vendorData) {
            if (preg_match('/^CV-(\d+)-(\d+)$/', $vendorData, $m)) {
                $verificationId = (int) $m[1];
                $clientId = (int) $m[2];
            }
        }

        if (!$verificationId) {
            $row = Capsule::table('mod_cv_verifications')->where('didit_session_id', $sessionId)->first();
            if ($row) {
                $verificationId = $row->id;
                $clientId = $row->client_id;
            }
        }

        if (!$verificationId) {
            Capsule::table('mod_cv_webhook_events')
                ->where('id', $eventRowId)
                ->update(['processed' => 1, 'result' => 'no_mapping']);
            return ['success' => false, 'error' => 'no_verification_mapping'];
        }

        // Verify the session belongs to the claimed verification + client (IDOR protection).
        $vRow = Capsule::table('mod_cv_verifications')->where('id', $verificationId)->first();
        if (!$vRow || ($clientId && (int) $vRow->client_id !== $clientId)) {
            Capsule::table('mod_cv_webhook_events')
                ->where('id', $eventRowId)
                ->update(['processed' => 1, 'result' => 'client_mismatch']);
            return ['success' => false, 'error' => 'client_mismatch'];
        }

        $result = $provider->handleWebhook($payload, $headers);

        // Collect document hashes for duplicate detection (if documents exist).
        $hashes = Capsule::table('mod_cv_documents')
            ->where('verification_id', $verificationId)
            ->pluck('sha256_hash')
            ->toArray();

        $decision = HybridVerificationService::applyResult($verificationId, $result, $hashes);

        Capsule::table('mod_cv_webhook_events')
            ->where('id', $eventRowId)
            ->update(['processed' => 1, 'result' => $decision]);

        return ['success' => true, 'error' => '', 'status' => $decision];
    }

    private static function header(array $headers, string $name): string
    {
        $name = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $name) {
                return is_array($v) ? ($v[0] ?? '') : (string) $v;
            }
        }
        return '';
    }
}
