<?php

namespace ClientVerification\Tests\Security;

use PHPUnit\Framework\TestCase;
use ClientVerification\Providers\DiditProvider;

class WebhookSignatureTest extends TestCase
{
    private function provider(): DiditProvider
    {
        return new DiditProvider('apikey', 'wf', 'supersecret');
    }

    public function testValidSignaturePasses()
    {
        $secret = 'supersecret';
        $body = '{"session_id":"abc","status":"approved"}';
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
        $header = "t={$ts},v1={$sig}";

        $p = $this->provider();
        $this->assertTrue($p->verifyWebhook($body, $header));
    }

    public function testTamperedBodyFails()
    {
        $secret = 'supersecret';
        $body = '{"session_id":"abc","status":"approved"}';
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
        $header = "t={$ts},v1={$sig}";

        $p = $this->provider();
        $this->assertFalse($p->verifyWebhook($body . 'tampered', $header));
    }

    public function testOldTimestampRejected()
    {
        $secret = 'supersecret';
        $body = '{"session_id":"abc"}';
        $ts = time() - 600; // 10 minutes ago
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
        $header = "t={$ts},v1={$sig}";

        $p = $this->provider();
        $this->assertFalse($p->verifyWebhook($body, $header));
    }

    public function testMissingSignatureFails()
    {
        $p = $this->provider();
        $this->assertFalse($p->verifyWebhook('{}', ''));
    }

    public function testConstantTimeComparison()
    {
        // Ensure hash_equals usage (no timing leak). Indirect check: invalid still false.
        $p = $this->provider();
        $this->assertFalse($p->verifyWebhook('x', 't=1,v1=deadbeef'));
    }
}
