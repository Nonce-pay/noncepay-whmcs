<?php

namespace NoncePay\Whmcs\Tests;

use NoncePay\Whmcs\Decimal;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    protected function tearDown(): void
    {
        Decimal::$useBcmath = null;
    }

    public function paths(): array
    {
        return ['string math' => [false], 'bcmath when loaded' => [true]];
    }

    /** @dataProvider paths */
    public function testArithmeticIsExact(bool $bcmath): void
    {
        Decimal::$useBcmath = $bcmath;
        $this->assertSame('0.3', Decimal::sub('0.5', '0.2'));
        $this->assertSame('0', Decimal::sub('1', '2'));
        $this->assertSame('99.5', Decimal::mul('99.50', '1'));
        $this->assertSame('123456789012345678.000000000000000001', Decimal::sub('123456789012345678.000000000000000002', '0.000000000000000001'));
        $this->assertSame(1, Decimal::cmp('10.01', '10.001'));
        $this->assertSame('49.90', Decimal::max('49.90', '49.89'));
    }

    /** @dataProvider paths */
    public function testCoversAppliesTheTolerance(bool $bcmath): void
    {
        Decimal::$useBcmath = $bcmath;
        $this->assertTrue(Decimal::covers('99.50', '100', '0.5'));
        $this->assertFalse(Decimal::covers('99.49', '100', '0.5'));
        $this->assertTrue(Decimal::covers('100', '100', '0'));
        $this->assertFalse(Decimal::covers('99.99', '100', '0'));
    }

    public function testParseRejectsAnythingButAPlainDecimal(): void
    {
        $this->assertSame('49.9', Decimal::parse(' 49.90 '));
        $this->assertSame('12', Decimal::parse(12));
        foreach (['-1', '1e3', '1,5', '', '.5', '1.', 1.5, null, ['1']] as $bad) {
            $this->assertNull(Decimal::parse($bad));
        }
    }
}
