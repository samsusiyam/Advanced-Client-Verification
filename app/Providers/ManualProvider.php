<?php

namespace ClientVerification\Providers;

/**
 * Manual verification provider. No remote session is created; the verification
 * remains in a manual review queue until an admin acts.
 */
class ManualProvider implements KycProviderInterface
{
    public function createSession(VerificationEntity $verification): KycSession
    {
        // No remote session. The "session" is the local verification record.
        return new KycSession(
            (string) $verification->id,
            '',
            ['provider' => 'manual']
        );
    }

    public function getStatus(string $sessionId): KycResult
    {
        return new KycResult(
            $sessionId,
            'pending',
            KycResult::DECISION_REVIEW,
            0,
            'low',
            ['provider' => 'manual']
        );
    }

    public function handleWebhook(array $payload, array $headers): KycResult
    {
        // Manual provider does not process webhooks.
        return new KycResult(
            '',
            'error',
            KycResult::DECISION_ERROR,
            0,
            'low',
            ['provider' => 'manual', 'reason' => 'no_webhook_support']
        );
    }

    public function getName(): string
    {
        return 'Manual';
    }
}
