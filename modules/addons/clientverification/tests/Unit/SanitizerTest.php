<?php

namespace ClientVerification\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClientVerification\Security\Sanitizer;

class SanitizerTest extends TestCase
{
    public function testEscapePreventsXss()
    {
        $this->assertStringNotContainsString('<script>', Sanitizer::escape('<script>alert(1)</script>'));
        $this->assertStringContainsString('&lt;script&gt;', Sanitizer::escape('<script>'));
    }

    public function testOnlyAllowlistsKeys()
    {
        $input = ['a' => 1, 'b' => 2, 'c' => 3];
        $this->assertSame(['a' => 1, 'c' => 3], Sanitizer::only($input, ['a', 'c']));
    }

    public function testCsvCellEscapesFormulaInjection()
    {
        $this->assertSame("'=cmd", Sanitizer::csvCell('=cmd'));
        $this->assertSame('safe', Sanitizer::csvCell('safe'));
    }

    public function testIntStripsNonNumeric()
    {
        $this->assertSame(12, Sanitizer::int('12abc'));
    }
}
