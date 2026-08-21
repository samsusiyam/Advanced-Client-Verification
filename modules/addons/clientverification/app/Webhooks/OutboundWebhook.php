<?php

namespace ClientVerification\Webhooks;

use ClientVerification\Helpers\Http;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Dispatches outbound webhooks to admin-configured endpoints with HMAC signing.
 */
class OutboundWebhook
{
    public static function dispatch(string $eventType, int $verificationId): void
    {
        $configs = Capsule::table('mod_cv_webhook_configs')
            ->where('event_type', $eventType)
            ->where('active', 1)
            ->get();

        if ($configs->isEmpty()) {
            return;
        }

        $payload = self::buildPayload($eventType, $verificationId);
        $body = json_encode($payload);

        foreach ($configs as $cfg) {
            // Secret is stored encrypted; decrypt to the plaintext the
            // subscriber uses to verify the HMAC.
            $secret = cv_decrypt_credentials($cfg->secret);
            $timestamp = time();
            $signature = self::sign($body, $secret, $timestamp);

            $headers = [
                'Content-Type: application/json',
                'X-CV-Event: ' . $eventType,
                'X-CV-Signature: t=' . $timestamp . ',v1=' . $signature,
            ];

            $result = Http::post($cfg->url, $payload, $headers);

            Capsule::table('mod_cv_webhook_configs')
                ->where('id', $cfg->id)
                ->update([
                    'last_attempt_at' => date('Y-m-d H:i:s'),
                    'failure_count' => $result['success'] ? 0 : $cfg->failure_count + 1,
                ]);
        }
    }

    private static function buildPayload(string $eventType, int $verificationId): array
    {
        $v = Capsule::table('mod_cv_verifications')->where('id', $verificationId)->first();
        return [
            'event' => $eventType,
            'verification_id' => $verificationId,
            'client_id' => $v ? $v->client_id : null,
            'status' => $v ? $v->status : null,
            'method' => $v ? $v->verification_method : null,
            'risk_score' => $v ? $v->risk_score : null,
            'risk_level' => $v ? $v->risk_level : null,
            'timestamp' => time(),
        ];
    }

    /**
     * Build the HMAC signature for a payload. Exposed for testing.
     */
    public static function sign(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }
}
