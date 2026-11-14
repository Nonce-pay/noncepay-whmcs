<?php

namespace NoncePay\Whmcs;

use WHMCS\Database\Capsule;

/**
 * Whmcs on the real WHMCS functions (gatewayfunctions.php, invoicefunctions.php, localAPI).
 */
final class WhmcsBridge implements Whmcs
{
    /** @var string Display name, as the gateway log shows it. */
    private $gatewayName;
    /** @var string Module name. */
    private $module;

    public function __construct(string $gatewayName, string $module)
    {
        $this->gatewayName = $gatewayName;
        $this->module = $module;
    }

    public function invoice(int $invoiceId): ?array
    {
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (!is_array($invoice) || ($invoice['result'] ?? '') !== 'success') {
            return null;
        }
        $client = localAPI('GetClientsDetails', ['clientid' => (int) $invoice['userid'], 'stats' => false]);
        $currency = '';
        if (is_array($client)) {
            $currency = (string) ($client['currency_code'] ?? ($client['client']['currency_code'] ?? ''));
        }
        return [
            'status' => (string) $invoice['status'],
            'balance' => (string) $invoice['balance'],
            'currency' => strtoupper($currency),
        ];
    }

    public function transactionExists(string $transactionId): bool
    {
        return Capsule::table('tblaccounts')
            ->where('gateway', $this->module)
            ->where('transid', $transactionId)
            ->exists();
    }

    public function addPayment(int $invoiceId, string $transactionId, string $amount): bool
    {
        // Dies on a duplicate; transactionExists() already answered that case with *ok*.
        checkCbTransID($transactionId);
        return (bool) addInvoicePayment($invoiceId, $transactionId, $amount, 0, $this->module);
    }

    public function logTransaction($data, string $status): void
    {
        logTransaction($this->gatewayName, $data, $status);
    }

    public function lock(string $name): bool
    {
        $row = Capsule::connection()->selectOne('SELECT GET_LOCK(?, 10) AS l', [$this->lockName($name)]);
        return $row && (string) $row->l === '1';
    }

    public function unlock(string $name): void
    {
        Capsule::connection()->selectOne('SELECT RELEASE_LOCK(?) AS l', [$this->lockName($name)]);
    }

    private function lockName(string $name): string
    {
        return 'noncepay_' . substr(md5(Capsule::connection()->getDatabaseName()), 0, 12) . '_' . $name;
    }
}
