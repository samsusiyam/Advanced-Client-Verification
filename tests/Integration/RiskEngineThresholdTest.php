<?php

namespace ClientVerification\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ClientVerification\Risk\RiskEngine;
use ClientVerification\Providers\KycResult;

/**
 * Verifies RiskEngine honors the configured risk thresholds (N2 fix) rather
 * than hardcoded 30/70 values. Requires a live WHMCS DB; skipped otherwise.
 */
class RiskEngineThresholdTest extends TestCase
{
    private function dbAvailable(): bool
    {
        return class_exists('Illuminate\\Database\\Capsule\\Manager')
            && function_exists('cv_get_config');
    }

    public function testApprovesBelowThresholdReviewsAndRejectsAroundIt()
    {
        if (!$this->dbAvailable()) {
            $this->markTestSkipped('No WHMCS DB available; skipping.');
        }

        cv_setting_set('risk_threshold_approve', '50');
        cv_setting_set('risk_threshold_review', '70');

        $engine = new RiskEngine();

        $low = new KycResult('s1', 'approved', KycResult::DECISION_APPROVED, 40, 'low', []);
        $this->assertSame('approve', $engine->evaluate(1, 1, $low, [])['action']);

        $mid = new KycResult('s2', 'approved', KycResult::DECISION_APPROVED, 60, 'medium', []);
        $this->assertSame('review', $engine->evaluate(2, 2, $mid, [])['action']);

        $high = new KycResult('s3', 'approved', KycResult::DECISION_APPROVED, 80, 'high', []);
        $this->assertSame('reject', $engine->evaluate(3, 3, $high, [])['action']);
    }

    public function testHonorsLoweredApproveThreshold()
    {
        if (!$this->dbAvailable()) {
            $this->markTestSkipped('No WHMCS DB available; skipping.');
        }

        // Lower approve threshold to 10; a score of 25 must now approve,
        // proving the setting is read instead of the old hardcoded 30.
        cv_setting_set('risk_threshold_approve', '10');
        cv_setting_set('risk_threshold_review', '90');

        $engine = new RiskEngine();
        $result = new KycResult('s4', 'approved', KycResult::DECISION_APPROVED, 25, 'low', []);
        $this->assertSame('approve', $engine->evaluate(4, 4, $result, [])['action']);
    }
}
