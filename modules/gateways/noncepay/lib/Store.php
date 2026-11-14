<?php

namespace NoncePay\Whmcs;

/**
 * Persistence for the links created per invoice and the cached public key.
 *
 * A link row: invoice_id, link_id, payment_url, success_token, amount, currency, tolerance,
 * invoice_amount, invoice_currency, expires_at, status (open, paid), created_at, paid_at.
 */
interface Store
{
    /** The newest open link of an invoice for this amount and currency, still valid at $validUntil. */
    public function reusableLink(int $invoiceId, string $amount, string $currency, int $validUntil): ?array;

    public function findLink(string $linkId): ?array;

    public function insertLink(array $row): void;

    public function markPaid(string $linkId, int $now): void;

    public function getValue(string $name): ?string;

    public function setValue(string $name, string $value): void;
}
