<?php

namespace NoncePay\Whmcs;

/**
 * The signed NoncePay callback. Links are created with json=1, so the signature covers the raw body.
 *
 * Answers *ok* (HTTP 200) to every signed notification it has handled or can never handle, so NoncePay
 * stops retrying; anything else (bad signature, busy, an error) is retried by NoncePay.
 */
final class Callback
{
    const OK = [200, '*ok*'];
    const PAYABLE_STATUSES = ['Unpaid', 'Payment Pending'];

    /** @var Store */
    private $store;
    /** @var Whmcs */
    private $whmcs;
    /** @var PublicKey */
    private $key;
    /** @var int */
    private $now;

    public function __construct(Store $store, Whmcs $whmcs, PublicKey $key, int $now)
    {
        $this->store = $store;
        $this->whmcs = $whmcs;
        $this->key = $key;
        $this->now = $now;
    }

    /**
     * @return array{0:int,1:string} HTTP status and body.
     */
    public function handle(string $body, string $signature, array $query): array
    {
        if (!$this->key->verify($body, $signature)) {
            $this->whmcs->logTransaction(['query' => self::scalars($query), 'signature' => $signature === '' ? 'missing' : 'invalid'], 'Invalid signature');
            return [401, 'Bad signature'];
        }

        $fields = json_decode($body, true);
        if (!is_array($fields)) {
            $this->whmcs->logTransaction(['query' => self::scalars($query), 'body' => substr($body, 0, 500)], 'Unreadable callback');
            return self::OK;
        }
        $fields = self::scalars($fields);

        if (($fields['type'] ?? '') !== 'payment') {
            $this->whmcs->logTransaction(array_merge(['invoice_id' => (string) ($query['invoice_id'] ?? '')], $fields), 'Deposit notification');
            return self::OK;
        }

        $linkId = (string) ($fields['link_id'] ?? '');
        $row = preg_match('/^[A-Za-z0-9-]{1,64}$/', $linkId) ? $this->store->findLink($linkId) : null;
        if ($row === null || ($fields['is_paid'] ?? '') !== '1') {
            $this->whmcs->logTransaction($fields, 'Unknown link, ignored');
            return self::OK;
        }

        $invoiceId = (int) $row['invoice_id'];
        if (!$this->whmcs->lock('invoice_' . $invoiceId)) {
            return [503, 'Busy, retry later'];
        }
        try {
            return $this->settle($row, $fields, $invoiceId);
        } catch (\Throwable $e) {
            $this->whmcs->logTransaction(array_merge($fields, ['error' => $e->getMessage()]), 'Error, NoncePay will retry');
            return [503, 'Error, retry later'];
        } finally {
            $this->whmcs->unlock('invoice_' . $invoiceId);
        }
    }

    private function settle(array $row, array $fields, int $invoiceId): array
    {
        $linkId = (string) $row['link_id'];
        $log = array_merge(['invoice_id' => (string) $invoiceId], $fields);

        if ($this->whmcs->transactionExists($linkId)) {
            $this->store->markPaid($linkId, $this->now);
            $this->whmcs->logTransaction($log, 'Duplicate, already recorded');
            return self::OK;
        }

        $value = Decimal::parse($fields['value'] ?? null);
        $paid = Decimal::parse($fields['paid_amount_fiat'] ?? null);
        $sameLink = $value !== null && Decimal::cmp($value, (string) $row['amount']) === 0
            && strtoupper((string) ($fields['currency'] ?? '')) === strtoupper((string) $row['currency']);
        if (!$sameLink || $paid === null || !Decimal::covers($paid, (string) $row['amount'], (string) $row['tolerance'])) {
            $this->whmcs->logTransaction($log, 'Amount mismatch, review manually');
            return self::OK;
        }

        $invoice = $this->whmcs->invoice($invoiceId);
        if ($invoice === null || !in_array($invoice['status'], self::PAYABLE_STATUSES, true)) {
            $this->store->markPaid($linkId, $this->now);
            $status = $invoice === null ? 'missing' : $invoice['status'];
            $this->whmcs->logTransaction($log, 'Paid link on a ' . $status . ' invoice, review manually');
            return self::OK;
        }

        $amount = $this->amountToRecord($row, $paid);
        if ($amount === null) {
            $this->store->markPaid($linkId, $this->now);
            $this->whmcs->logTransaction($log, 'Paid link without an invoice amount, review manually');
            return self::OK;
        }

        if (!$this->whmcs->addPayment($invoiceId, $linkId, $amount)) {
            throw new \RuntimeException('addInvoicePayment refused the payment');
        }
        $this->store->markPaid($linkId, $this->now);
        $log['recorded_amount'] = $amount;
        $log['note'] = 'Fee recorded as 0: the NoncePay fee is deducted from the crypto forwarded to your wallet.';
        $this->whmcs->logTransaction($log, 'Success');
        return self::OK;
    }

    /**
     * In the invoice currency: what was paid, raised to the link amount when short within the tolerance
     * (an overpayment becomes client credit). Priced in another currency ("Convert To For Processing"),
     * the invoice balance recorded when the link was created.
     */
    private function amountToRecord(array $row, string $paid): ?string
    {
        if (strtoupper((string) $row['currency']) === strtoupper((string) $row['invoice_currency'])) {
            return Decimal::max($paid, (string) $row['amount']);
        }
        $balance = Decimal::parse((string) $row['invoice_amount']);
        return $balance === null || Decimal::isZero($balance) ? null : $balance;
    }

    /** Only string fields, so nothing nested or odd reaches the log or the checks. */
    private static function scalars(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if (is_scalar($value)) {
                $out[(string) $key] = substr((string) $value, 0, 2000);
            }
        }
        return $out;
    }
}
