<?php

namespace NoncePay\Whmcs\Tests\Support;

use NoncePay\Whmcs\Whmcs;

/**
 * Mimics the WHMCS functions the module calls, with tblaccounts as a list of payments.
 */
final class FakeWhmcs implements Whmcs
{
    /** @var array<int,array> */
    public $invoices = [];
    /** @var array[] invoice_id, transid, amount */
    public $payments = [];
    /** @var array[] data, status */
    public $logs = [];
    /** @var bool */
    public $lockFree = true;
    /** @var bool */
    public $refusePayment = false;

    public function invoice(int $invoiceId): ?array
    {
        return $this->invoices[$invoiceId] ?? null;
    }

    public function transactionExists(string $transactionId): bool
    {
        foreach ($this->payments as $payment) {
            if ($payment['transid'] === $transactionId) {
                return true;
            }
        }
        return false;
    }

    public function addPayment(int $invoiceId, string $transactionId, string $amount): bool
    {
        if ($this->refusePayment) {
            return false;
        }
        $this->payments[] = ['invoice_id' => $invoiceId, 'transid' => $transactionId, 'amount' => $amount];
        $this->invoices[$invoiceId]['status'] = 'Paid';
        return true;
    }

    public function logTransaction($data, string $status): void
    {
        $this->logs[] = ['data' => $data, 'status' => $status];
    }

    public function lock(string $name): bool
    {
        return $this->lockFree;
    }

    public function unlock(string $name): void
    {
    }

    public function lastStatus(): string
    {
        return $this->logs ? $this->logs[count($this->logs) - 1]['status'] : '';
    }
}
