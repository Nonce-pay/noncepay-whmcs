<?php

namespace NoncePay\Whmcs;

use WHMCS\Database\Capsule;

/**
 * Store on the WHMCS database. Creates its two tables on first use.
 */
final class CapsuleStore implements Store
{
    const LINKS = 'mod_noncepay_links';
    const VALUES = 'mod_noncepay_values';

    /** @var bool */
    private static $ready = false;

    public function __construct()
    {
        if (self::$ready) {
            return;
        }
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::LINKS)) {
            $schema->create(self::LINKS, function ($table) {
                $table->increments('id');
                $table->unsignedInteger('invoice_id')->index();
                $table->string('link_id', 64)->unique();
                $table->string('payment_url', 255);
                $table->string('success_token', 128);
                $table->string('amount', 40);
                $table->string('currency', 3);
                $table->string('tolerance', 10);
                $table->string('invoice_amount', 40);
                $table->string('invoice_currency', 3);
                $table->unsignedInteger('expires_at');
                $table->string('status', 10)->default('open');
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('paid_at')->nullable();
            });
        }
        if (!$schema->hasTable(self::VALUES)) {
            $schema->create(self::VALUES, function ($table) {
                $table->string('name', 64)->primary();
                $table->text('value');
            });
        }
        self::$ready = true;
    }

    public function reusableLink(int $invoiceId, string $amount, string $currency, int $validUntil): ?array
    {
        $row = Capsule::table(self::LINKS)
            ->where('invoice_id', $invoiceId)
            ->where('amount', $amount)
            ->where('currency', $currency)
            ->where('status', 'open')
            ->where('expires_at', '>', $validUntil)
            ->orderBy('id', 'desc')
            ->first();
        return $row ? (array) $row : null;
    }

    public function findLink(string $linkId): ?array
    {
        $row = Capsule::table(self::LINKS)->where('link_id', $linkId)->first();
        return $row ? (array) $row : null;
    }

    public function insertLink(array $row): void
    {
        Capsule::table(self::LINKS)->insert($row);
    }

    public function markPaid(string $linkId, int $now): void
    {
        Capsule::table(self::LINKS)->where('link_id', $linkId)->where('status', 'open')
            ->update(['status' => 'paid', 'paid_at' => $now]);
    }

    public function getValue(string $name): ?string
    {
        $value = Capsule::table(self::VALUES)->where('name', $name)->value('value');
        return $value === null ? null : (string) $value;
    }

    public function setValue(string $name, string $value): void
    {
        Capsule::table(self::VALUES)->updateOrInsert(['name' => $name], ['value' => $value]);
    }
}
