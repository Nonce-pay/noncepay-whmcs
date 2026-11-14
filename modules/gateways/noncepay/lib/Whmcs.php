<?php

namespace NoncePay\Whmcs;

/**
 * The WHMCS functions the module needs, behind an interface so the flows run in tests.
 */
interface Whmcs
{
    /**
     * @return array{status:string,balance:string,currency:string}|null
     */
    public function invoice(int $invoiceId): ?array;

    public function transactionExists(string $transactionId): bool;

    public function addPayment(int $invoiceId, string $transactionId, string $amount): bool;

    /** @param array|string $data */
    public function logTransaction($data, string $status): void;

    public function lock(string $name): bool;

    public function unlock(string $name): void;
}
