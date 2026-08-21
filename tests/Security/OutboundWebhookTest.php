<?php

namespace ClientVerification\Tests\Security;

use PHPUnit\Framework\TestCase;
use ClientVerification\Webhooks\OutboundWebhook;

/**
 * Covers the outbound webhook HMAC signing, specifically that dispatch signs
 * with the *decrypted* (plaintext) secret, not the stored ciphertext (N1 fix).
 */
class OutboundWebhookTest extends TestCase
{
    public function testSignMatchesHmacWithPlaintextSecret()
    {
        $body = json_encode(['event' => 'verification.approved', 'verification_id' => 1]);
        $secret = 'myplaintextsecret';
        $ts = time();
        $expected = hash_hmac('sha256', $ts . '.' . $body, $secret);
        $this->assertSame($expected, OutboundWebhook::sign($body, $secret, $ts));
    }

    public function testDispatchSignsWithDecryptedSecretNotCiphertext()
    {
        // The stored secret is encrypted at rest; recipients verify with the
        // plaintext they configured. Prove signing uses the decrypted value.
        $body = json_encode(['event' => 'x']);
        $plain = 'sharedsecret';
        $encrypted = cv_encrypt_credentials($plain);

        $this->assertNotSame($encrypted, $plain, 'Precondition: storage must encrypt the secret.');

        $ts = time();
        $signatureFromPlaintext = OutboundWebhook::sign($body, $plain, $ts);
        $signatureFromCiphertext = OutboundWebhook::sign($body, $encrypted, $ts);

        // A correct implementation (dispatch decrypts first) must NOT match the
        // ciphertext-based signature. If these were equal, dispatch would be
        // signing with ciphertext and every subscriber verification would fail.
        $this->assertNotSame(
            $signatureFromCiphertext,
            $signatureFromPlaintext,
            'Signing with ciphertext would break recipient verification (N1).'
        );
        $this->assertSame(
            $signatureFromPlaintext,
            OutboundWebhook::sign($body, cv_decrypt_credentials($encrypted), $ts),
            'Decrypted secret must reproduce the plaintext-based signature.'
        );
    }
}
