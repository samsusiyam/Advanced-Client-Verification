<?php

namespace ClientVerification\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClientVerification\License\LicenseManager;

class LicenseManagerTest extends TestCase
{
    public function testDomainAndIpDetection()
    {
        $_SERVER['HTTP_HOST'] = 'demo.hostnibo.com:443';
        $manager = new LicenseManager();
        $this->assertSame('demo.hostnibo.com', $manager->getDomain());
    }

    public function testGetDetailsReturnsStructure()
    {
        $manager = new LicenseManager();
        $details = $manager->getDetails();

        $this->assertArrayHasKey('license_key', $details);
        $this->assertArrayHasKey('status', $details);
        $this->assertArrayHasKey('is_licensed', $details);
        $this->assertArrayHasKey('domain', $details);
        $this->assertArrayHasKey('ip', $details);
        $this->assertArrayHasKey('expiry_date', $details);
    }
}
