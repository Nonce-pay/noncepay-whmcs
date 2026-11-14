<?php

namespace NoncePay\Whmcs\Tests;

use NoncePay\Whmcs\Api;
use NoncePay\Whmcs\Callback;
use NoncePay\Whmcs\PublicKey;
use NoncePay\Whmcs\Tests\Support\FakeWhmcs;
use NoncePay\Whmcs\Tests\Support\MemoryStore;
use PHPUnit\Framework\TestCase;

final class CallbackTest extends TestCase
{
    const LINK = '3f6c2a1e-8b4d-4c7a-9e21-5d0f7b9a6c13';

    /** @var MemoryStore */
    private $store;
    /** @var FakeWhmcs */
    private $whmcs;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->store->insertLink($this->row());
        $this->whmcs = new FakeWhmcs();
        $this->whmcs->invoices[42] = ['status' => 'Unpaid', 'balance' => '49.90', 'currency' => 'USD'];
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'invoice_id' => 42,
            'link_id' => self::LINK,
            'payment_url' => 'https://noncepay.app/pay/?id=' . self::LINK,
            'success_token' => 'secret',
            'amount' => '49.9',
            'currency' => 'USD',
            'tolerance' => '0.5',
            'invoice_amount' => '49.9',
            'invoice_currency' => 'USD',
            'expires_at' => 1800000000,
            'status' => 'open',
            'created_at' => 1700000000,
        ], $overrides);
    }

    private function paid(array $overrides = []): string
    {
        return json_encode(array_merge([
            'type' => 'payment',
            'link_id' => self::LINK,
            'value' => '49.90',
            'currency' => 'USD',
            'paid_amount_fiat' => '49.90',
            'paid_coin' => 'trc20_usdt',
            'paid_amount' => '49.9',
            'exchange_rate' => '1',
            'txids' => 'abc123',
            'address_in' => 'TXyz',
            'is_paid' => '1',
        ], $overrides));
    }

    private function handle(string $body, ?string $signature = null, array $query = ['invoice_id' => '42']): array
    {
        $api = new Api('https://api.test', function () {
            return [200, json_encode(['status' => 'success', 'pubkey' => PublicKeyTest::pem()])];
        });
        $callback = new Callback($this->store, $this->whmcs, new PublicKey($api, $this->store, ''), 1700000100);
        return $callback->handle($body, $signature ?? PublicKeyTest::sign($body), $query);
    }

    public function testRecordsThePaymentOfAPaidLink(): void
    {
        $this->assertSame([200, '*ok*'], $this->handle($this->paid()));
        $this->assertSame([['invoice_id' => 42, 'transid' => self::LINK, 'amount' => '49.9']], $this->whmcs->payments);
        $this->assertSame('paid', $this->store->links[0]['status']);
        $this->assertSame('Success', $this->whmcs->lastStatus());
    }

    public function testRejectsABadSignatureSoNoncePayRetries(): void
    {
        $this->assertSame([401, 'Bad signature'], $this->handle($this->paid(), PublicKeyTest::sign('something else')));
        $this->assertSame([401, 'Bad signature'], $this->handle($this->paid(), ''));
        $this->assertSame([], $this->whmcs->payments);
        $this->assertSame('Invalid signature', $this->whmcs->lastStatus());
    }

    public function testRecordsADuplicateCallbackOnce(): void
    {
        $this->handle($this->paid());
        $this->whmcs->invoices[42]['status'] = 'Unpaid';
        $this->assertSame([200, '*ok*'], $this->handle($this->paid()));
        $this->assertCount(1, $this->whmcs->payments);
        $this->assertSame('Duplicate, already recorded', $this->whmcs->lastStatus());
    }

    public function testTheInvoiceComesFromTheStoredLinkNotTheUnsignedUrl(): void
    {
        $this->whmcs->invoices[99] = ['status' => 'Unpaid', 'balance' => '500', 'currency' => 'USD'];
        $this->handle($this->paid(), null, ['invoice_id' => '99']);
        $this->assertSame(42, $this->whmcs->payments[0]['invoice_id']);
    }

    public function testAcknowledgesDepositCallbacksWithoutRecordingAnything(): void
    {
        $body = json_encode(['uuid' => 'd1', 'coin' => 'trc20_usdt', 'value_coin' => '10', 'pending' => '0', 'txid_in' => 'abc']);
        $this->assertSame([200, '*ok*'], $this->handle($body, null, ['invoice_id' => '42', 'noncepay_link' => self::LINK]));
        $this->assertSame([], $this->whmcs->payments);
        $this->assertSame('Deposit notification', $this->whmcs->lastStatus());
    }

    public function testIgnoresAnUnknownLink(): void
    {
        $this->assertSame([200, '*ok*'], $this->handle($this->paid(['link_id' => 'ffffffff-0000-4000-8000-000000000000'])));
        $this->assertSame([], $this->whmcs->payments);
    }

    public function testRaisesAShortfallWithinToleranceToTheInvoiceAmount(): void
    {
        $this->handle($this->paid(['paid_amount_fiat' => '49.66']));
        $this->assertSame('49.9', $this->whmcs->payments[0]['amount']);
    }

    public function testRecordsNothingBelowTheTolerance(): void
    {
        $this->assertSame([200, '*ok*'], $this->handle($this->paid(['paid_amount_fiat' => '49.65'])));
        $this->assertSame([], $this->whmcs->payments);
        $this->assertSame('Amount mismatch, review manually', $this->whmcs->lastStatus());
    }

    public function testRecordsNothingWhenTheSignedAmountIsNotTheStoredLink(): void
    {
        $this->handle($this->paid(['value' => '4.99', 'paid_amount_fiat' => '4.99']));
        $this->handle($this->paid(['currency' => 'EUR']));
        $this->assertSame([], $this->whmcs->payments);
    }

    public function testRecordsAnOverpaymentInFull(): void
    {
        $this->handle($this->paid(['paid_amount_fiat' => '60.12']));
        $this->assertSame('60.12', $this->whmcs->payments[0]['amount']);
    }

    public function testRecordsTheInvoiceBalanceWhenTheLinkWasPricedInAnotherCurrency(): void
    {
        $this->store->links[0] = $this->row(['amount' => '49.9', 'currency' => 'USD', 'invoice_amount' => '4500', 'invoice_currency' => 'XAF']);
        $this->handle($this->paid(['paid_amount_fiat' => '55']));
        $this->assertSame('4500', $this->whmcs->payments[0]['amount']);
    }

    public function testLeavesAnInvoiceThatIsNoLongerUnpaidForReview(): void
    {
        $this->whmcs->invoices[42]['status'] = 'Cancelled';
        $this->assertSame([200, '*ok*'], $this->handle($this->paid()));
        $this->assertSame([], $this->whmcs->payments);
        $this->assertSame('Paid link on a Cancelled invoice, review manually', $this->whmcs->lastStatus());
    }

    public function testAsksForARetryWhenBusyOrWhenWhmcsRefuses(): void
    {
        $this->whmcs->lockFree = false;
        $this->assertSame(503, $this->handle($this->paid())[0]);
        $this->whmcs->lockFree = true;
        $this->whmcs->refusePayment = true;
        $this->assertSame(503, $this->handle($this->paid())[0]);
        $this->assertSame('open', $this->store->links[0]['status']);
        $this->whmcs->refusePayment = false;
        $this->assertSame([200, '*ok*'], $this->handle($this->paid()));
        $this->assertCount(1, $this->whmcs->payments);
    }
}
