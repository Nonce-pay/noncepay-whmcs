<?php

namespace NoncePay\Whmcs\Tests;

use NoncePay\Whmcs\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testAValidConfiguration(): void
    {
        $s = Settings::fromParams([
            'address_evm' => ' 0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266 ',
            'address_tron' => 'TJzXZQhbhJB3dA2TmxmvyPxk2nKsmZ6yWf',
            'address_btc' => '0.7@bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq|0.3@tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx',
            'coins' => 'TRC20/USDT, bep20/usdt,btc',
            'companyname' => 'Acme Hosting',
            'tolerance' => '0.5',
        ]);
        $this->assertSame([], $s->errors);
        $this->assertSame(['evm', 'tron', 'btc'], array_keys($s->wallets));
        $this->assertSame('trc20/usdt,bep20/usdt,btc', $s->coins);
        $this->assertSame('Acme Hosting', $s->merchantName);
        $this->assertSame('0.5', $s->tolerance);
        $this->assertSame('https://api.noncepay.app', $s->apiBase);
    }

    public function testRejectsBadValues(): void
    {
        $s = Settings::fromParams([
            'address_evm' => '0x123',
            'address_sol' => '7EcDhSYGxXyscszYEp35KHN8vvw3svAuLKTzXwCFLtV',
            'coins' => 'usdt;btc',
            'logo_url' => 'http://acme.test/logo.png',
            'tolerance' => '2',
            'api_base' => 'http://localhost:3333',
        ]);
        $this->assertCount(5, $s->errors);
        $this->assertSame(['sol'], array_keys($s->wallets));
    }

    public function testRequiresAWalletAndAllowsHttpOnlyInTestMode(): void
    {
        $this->assertSame(['Enter at least one receiving wallet.'], Settings::fromParams(['address_doge' => ' '])->errors);
        $s = Settings::fromParams(['address_doge' => 'DH5yaieqoZN36fDVciNyRueRGvGLR3mr7L', 'api_base' => 'http://localhost:3333/', 'test_mode' => 'on']);
        $this->assertSame([], $s->errors);
        $this->assertSame('http://localhost:3333', $s->apiBase);
    }

    public function testMerchantNameIsCutTo64Characters(): void
    {
        $s = Settings::fromParams(['address_evm' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266', 'merchant_name' => str_repeat('é', 70)]);
        $this->assertSame(str_repeat('é', 64), $s->merchantName);
    }

    public function testCurrencies(): void
    {
        $this->assertTrue(Settings::currencySupported('eur'));
        $this->assertFalse(Settings::currencySupported('XAF'));
    }
}
