<?php

namespace ClientVerification\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClientVerification\License\LicenseManager;

class LicenseManagerTest extends TestCase
{
    public function testDefaultValues()
    {
        $manager = new LicenseManager('https://lic.hostnibo.com', 'ADVANCED-CLIENT-VERIFICATION');
        $this->assertSame('https://lic.hostnibo.com', $manager->resolveServerUrl());
        $this->assertSame('ADVANCED-CLIENT-VERIFICATION', $manager->resolveProductKey());
    }

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
        $this->assertArrayHasKey('product_key', $details);
        $this->assertArrayHasKey('server_url', $details);
    }
}
