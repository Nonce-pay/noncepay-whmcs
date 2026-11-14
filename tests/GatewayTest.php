<?php

namespace NoncePay\Whmcs\Tests;

use NoncePay\Whmcs\Gateway;
use NoncePay\Whmcs\Lang;
use NoncePay\Whmcs\Settings;
use NoncePay\Whmcs\Tests\Support\FakeApi;
use NoncePay\Whmcs\Tests\Support\FakeWhmcs;
use NoncePay\Whmcs\Tests\Support\MemoryStore;
use PHPUnit\Framework\TestCase;

final class GatewayTest extends TestCase
{
    const NOW = 1700000000;

    /** @var MemoryStore */
    private $store;
    /** @var FakeApi */
    private $fake;
    /** @var FakeWhmcs */
    private $whmcs;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->fake = new FakeApi();
        $this->whmcs = new FakeWhmcs();
        $this->whmcs->invoices[42] = ['status' => 'Unpaid', 'balance' => '49.90', 'currency' => 'USD'];
    }

    private function params(array $overrides = []): array
    {
        return array_merge([
            'invoiceid' => 42,
            'invoicenum' => '',
            'amount' => '49.90',
            'currency' => 'USD',
            'systemurl' => 'https://billing.acme.test/',
            'returnurl' => 'https://billing.acme.test/viewinvoice.php?id=42',
            'companyname' => 'Acme <Hosting>',
            'paymentmethod' => 'noncepay',
            'clientdetails' => ['language' => ''],
            'address_evm' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
            'address_tron' => 'TJzXZQhbhJB3dA2TmxmvyPxk2nKsmZ6yWf',
            'coins' => 'trc20/usdt,bep20/usdt',
            'logo_url' => 'https://acme.test/logo.png',
            'tolerance' => '0.5',
        ], $overrides);
    }

    private function gateway(array $overrides = [], int $now = self::NOW, string $language = 'english'): Gateway
    {
        $params = $this->params($overrides);
        return new Gateway($params, Settings::fromParams($params), $this->store, $this->fake->api(), $this->whmcs, new Lang($language), $now);
    }

    private function pay(Gateway $gateway): array
    {
        return $gateway->render(['id' => '42', 'noncepay' => 'pay']);
    }

    public function testShowsAButtonWithoutCallingTheApi(): void
    {
        $html = $this->gateway()->render(['id' => '42'])['html'];
        $this->assertStringContainsString('action="https://billing.acme.test/viewinvoice.php"', $html);
        $this->assertStringContainsString('name="noncepay" value="pay"', $html);
        $this->assertStringContainsString('Pay with crypto', $html);
        $this->assertSame([], $this->fake->calls);
    }

    public function testCreatesAPaymentLinkForTheInvoice(): void
    {
        $result = $this->pay($this->gateway());

        $this->assertSame('https://noncepay.app/pay/?id=00000000-0000-4000-8000-000000000001', $result['redirect']);
        $this->assertSame([
            'kind' => 'payment',
            'value' => '49.9',
            'currency' => 'usd',
            'address_evm' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
            'address_tron' => 'TJzXZQhbhJB3dA2TmxmvyPxk2nKsmZ6yWf',
            'coins' => 'trc20/usdt,bep20/usdt',
            'callback' => 'https://billing.acme.test/modules/gateways/callback/noncepay.php?invoice_id=42',
            'json' => '1',
            'redirect_url' => 'https://billing.acme.test/viewinvoice.php?id=42',
            'item_description' => 'Invoice #42',
            'merchant_name' => 'Acme <Hosting>',
            'logo_url' => 'https://acme.test/logo.png',
            'expire_at' => (string) (self::NOW + 7 * 86400),
            'tolerance' => '0.5',
        ], $this->fake->query(0));
        $this->assertSame('/link/create/', parse_url($this->fake->calls[0], PHP_URL_PATH));

        $row = $this->store->links[0];
        $this->assertSame(42, $row['invoice_id']);
        $this->assertSame('token-1', $row['success_token']);
        $this->assertSame(['49.9', 'USD', '0.5', '49.9', 'USD'], [$row['amount'], $row['currency'], $row['tolerance'], $row['invoice_amount'], $row['invoice_currency']]);
    }

    public function testUsesTheInvoiceNumberAndTheClientLanguage(): void
    {
        $this->pay($this->gateway(['invoicenum' => 'INV-2026-0042', 'tolerance' => '0'], self::NOW, 'french'));
        $query = $this->fake->query(0);
        $this->assertSame('Facture n° INV-2026-0042', $query['item_description']);
        $this->assertArrayNotHasKey('tolerance', $query);
    }

    public function testReusesTheOpenLinkOnReload(): void
    {
        $first = $this->pay($this->gateway());
        $second = $this->pay($this->gateway([], self::NOW + 3600));
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->fake->count('/link/create/'));
    }

    public function testCreatesANewLinkWhenTheBalanceChanges(): void
    {
        $this->pay($this->gateway());
        $this->whmcs->invoices[42]['balance'] = '59.90';
        $second = $this->pay($this->gateway(['amount' => '59.90']));
        $this->assertStringEndsWith('000000000002', $second['redirect']);
        $this->assertSame('59.9', $this->fake->query(1)['value']);
    }

    public function testCreatesANewLinkWhenTheOldOneIsAboutToExpireOrWasDisabled(): void
    {
        $this->pay($this->gateway());
        $this->pay($this->gateway([], self::NOW + 7 * 86400 - 1800));
        $this->assertSame(2, $this->fake->count('/link/create/'));

        $this->fake->responses['/link/0'] = [200, json_encode(['status' => 'success', 'link_status' => 'disabled'])];
        $this->pay($this->gateway([], self::NOW + 7 * 86400 - 1800));
        $this->assertSame(3, $this->fake->count('/link/create/'));
    }

    public function testRefusesACurrencyNoncePayCannotPrice(): void
    {
        $html = $this->pay($this->gateway(['currency' => 'XAF']))['html'];
        $this->assertStringContainsString('Invoices in XAF cannot be paid in crypto', $html);
        $this->assertSame([], $this->fake->calls);
    }

    public function testShowsAnErrorWhenMisconfiguredOrTheApiFails(): void
    {
        $html = $this->pay($this->gateway(['address_evm' => '', 'address_tron' => '', 'coins' => '']))['html'];
        $this->assertStringContainsString('not available for this invoice', $html);

        $this->fake->responses['/link/create/'] = [400, json_encode(['status' => 'error', 'error' => 'You must provide a valid currency'])];
        $html = $this->pay($this->gateway())['html'];
        $this->assertStringContainsString('temporarily unavailable', $html);
        $this->assertStringContainsString('name="noncepay" value="pay"', $html);
        $this->assertSame('Link creation failed', $this->whmcs->lastStatus());
        $this->assertSame([], $this->store->links);
    }

    public function testIgnoresAPayRequestForAnotherInvoice(): void
    {
        $result = $this->gateway()->render(['id' => '43', 'noncepay' => 'pay']);
        $this->assertArrayHasKey('html', $result);
        $this->assertSame([], $this->fake->calls);
    }

    public function testShowsTheConfirmingNoticeOnlyForTheRightToken(): void
    {
        $this->pay($this->gateway());
        $linkId = $this->store->links[0]['link_id'];

        $html = $this->gateway()->render(['id' => '42', 'link_id' => $linkId, 'success_token' => 'token-1'])['html'];
        $this->assertStringContainsString('Payment received', $html);
        $this->assertSame([], $this->whmcs->payments, 'the redirect never records a payment');

        $html = $this->gateway()->render(['id' => '42', 'link_id' => $linkId, 'success_token' => 'forged'])['html'];
        $this->assertStringNotContainsString('Payment received', $html);
    }

    public function testEscapesTheRedirectFallback(): void
    {
        $html = $this->gateway()->redirectHtml('https://noncepay.app/pay/?id="><script>');
        $this->assertStringNotContainsString('"><script>', $html);
    }
}
