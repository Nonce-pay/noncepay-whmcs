<?php

namespace NoncePay\Whmcs\Tests;

use PHPUnit\Framework\TestCase;

final class EntryFileTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('WHMCS')) {
            define('WHMCS', true);
        }
        require_once __DIR__ . '/../modules/gateways/noncepay.php';
    }

    public function testDeclaresAThirdPartyGateway(): void
    {
        $meta = noncepay_MetaData();
        $this->assertSame('1.1', $meta['APIVersion']);
        $this->assertTrue($meta['DisableLocalCreditCardInput']);

        $config = noncepay_config();
        $this->assertSame('System', $config['FriendlyName']['Type']);
        foreach (['evm', 'tron', 'btc', 'ltc', 'bch', 'doge', 'sol'] as $family) {
            $this->assertSame('text', $config['address_' . $family]['Type']);
        }
        $this->assertSame('https://api.noncepay.app', $config['api_base']['Default']);
    }

    public function testValidationAcceptsAGoodConfiguration(): void
    {
        noncepay_config_validate(['address_evm' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266']);
        $this->addToAssertionCount(1);
    }
}
