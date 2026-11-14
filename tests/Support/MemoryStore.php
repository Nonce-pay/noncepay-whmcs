<?php

namespace NoncePay\Whmcs\Tests\Support;

use NoncePay\Whmcs\Store;

final class MemoryStore implements Store
{
    /** @var array[] */
    public $links = [];
    /** @var array<string,string> */
    public $values = [];

    public function reusableLink(int $invoiceId, string $amount, string $currency, int $validUntil): ?array
    {
        foreach (array_reverse($this->links) as $row) {
            if ($row['invoice_id'] === $invoiceId && $row['amount'] === $amount && $row['currency'] === $currency
                && $row['status'] === 'open' && $row['expires_at'] > $validUntil) {
                return $row;
            }
        }
        return null;
    }

    public function findLink(string $linkId): ?array
    {
        foreach ($this->links as $row) {
            if ($row['link_id'] === $linkId) {
                return $row;
            }
        }
        return null;
    }

    public function insertLink(array $row): void
    {
        $this->links[] = $row;
    }

    public function markPaid(string $linkId, int $now): void
    {
        foreach ($this->links as $i => $row) {
            if ($row['link_id'] === $linkId && $row['status'] === 'open') {
                $this->links[$i]['status'] = 'paid';
                $this->links[$i]['paid_at'] = $now;
            }
        }
    }

    public function getValue(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    public function setValue(string $name, string $value): void
    {
        $this->values[$name] = $value;
    }
}
