<?php

namespace NoncePay\Whmcs;

/**
 * The invoice side: the "Pay with crypto" button, the payment link behind it, and the notice
 * shown when the customer comes back from the payment page.
 */
final class Gateway
{
    const LINK_DAYS = 7;
    // A stored link is reused only while it has at least this long left to be paid.
    const REUSE_MARGIN = 3600;
    const MAX_DESCRIPTION = 128;

    /** @var array */
    private $params;
    /** @var Settings */
    private $settings;
    /** @var Store */
    private $store;
    /** @var Api */
    private $api;
    /** @var Whmcs */
    private $whmcs;
    /** @var Lang */
    private $lang;
    /** @var int */
    private $now;

    public function __construct(array $params, Settings $settings, Store $store, Api $api, Whmcs $whmcs, Lang $lang, int $now)
    {
        $this->params = $params;
        $this->settings = $settings;
        $this->store = $store;
        $this->api = $api;
        $this->whmcs = $whmcs;
        $this->lang = $lang;
        $this->now = $now;
    }

    /**
     * @param array $query The invoice page's query string.
     * @return array{redirect?:string,html?:string}
     */
    public function render(array $query): array
    {
        $invoiceId = (int) $this->params['invoiceid'];

        $linkId = is_string($query['link_id'] ?? null) ? $query['link_id'] : '';
        $token = is_string($query['success_token'] ?? null) ? $query['success_token'] : '';
        if ($linkId !== '' && $token !== '') {
            $row = $this->store->findLink($linkId);
            if ($row && (int) $row['invoice_id'] === $invoiceId && hash_equals((string) $row['success_token'], $token)) {
                return ['html' => $this->confirmingNotice()];
            }
        }

        if ($this->settings->errors) {
            return ['html' => $this->alert($this->lang->get('not_configured'))];
        }
        $currency = strtoupper((string) $this->params['currency']);
        if (!Settings::currencySupported($currency)) {
            return ['html' => $this->alert($this->lang->get('currency_unsupported', $currency))];
        }

        $wantsPay = ($query['noncepay'] ?? null) === 'pay' && (int) ($query['id'] ?? 0) === $invoiceId;
        if (!$wantsPay) {
            return ['html' => $this->button()];
        }

        try {
            return ['redirect' => $this->paymentUrl()];
        } catch (ApiException $e) {
            $this->whmcs->logTransaction(['invoice_id' => $invoiceId, 'error' => $e->getMessage()], 'Link creation failed');
            return ['html' => $this->alert($this->lang->get('unavailable')) . $this->button()];
        }
    }

    /**
     * The payment page of the invoice's open link for its current balance, created when there is none.
     *
     * @throws ApiException
     */
    public function paymentUrl(): string
    {
        $invoiceId = (int) $this->params['invoiceid'];
        $amount = Decimal::parse((string) $this->params['amount']);
        if ($amount === null || Decimal::isZero($amount)) {
            throw new ApiException('Invoice ' . $invoiceId . ' has no amount to pay');
        }
        $currency = strtoupper((string) $this->params['currency']);

        $row = $this->store->reusableLink($invoiceId, $amount, $currency, $this->now + self::REUSE_MARGIN);
        if ($row) {
            try {
                $status = $this->api->linkStatus((string) $row['link_id']);
            } catch (ApiException $e) {
                $status = 'open';
            }
            if ($status === 'open' || $status === 'paid') {
                return (string) $row['payment_url'];
            }
        }

        $invoice = $this->whmcs->invoice($invoiceId);
        if ($invoice === null) {
            throw new ApiException('Invoice ' . $invoiceId . ' not found');
        }
        $expiresAt = $this->now + self::LINK_DAYS * 86400;
        $link = $this->api->createLink($this->linkQuery($amount, $currency, $expiresAt));
        $this->store->insertLink([
            'invoice_id' => $invoiceId,
            'link_id' => $link['link_id'],
            'payment_url' => $link['payment_url'],
            'success_token' => $link['success_token'],
            'amount' => $amount,
            'currency' => $currency,
            'tolerance' => $this->settings->tolerance,
            'invoice_amount' => (string) (Decimal::parse($invoice['balance']) ?? ''),
            'invoice_currency' => $invoice['currency'],
            'expires_at' => $expiresAt,
            'status' => 'open',
            'created_at' => $this->now,
        ]);
        return $link['payment_url'];
    }

    public function linkQuery(string $amount, string $currency, int $expiresAt): array
    {
        $invoiceId = (int) $this->params['invoiceid'];
        $number = trim((string) ($this->params['invoicenum'] ?? ''));
        $query = [
            'kind' => 'payment',
            'value' => $amount,
            'currency' => strtolower($currency),
        ];
        foreach ($this->settings->wallets as $family => $wallet) {
            $query['address_' . $family] = $wallet;
        }
        if ($this->settings->coins !== '') {
            $query['coins'] = $this->settings->coins;
        }
        $query['callback'] = $this->callbackUrl();
        $query['json'] = 1;
        $query['redirect_url'] = (string) $this->params['returnurl'];
        $query['item_description'] = Settings::truncate(
            $this->lang->get('invoice', $number !== '' ? $number : (string) $invoiceId),
            self::MAX_DESCRIPTION
        );
        if ($this->settings->merchantName !== '') {
            $query['merchant_name'] = $this->settings->merchantName;
        }
        if ($this->settings->logoUrl !== '') {
            $query['logo_url'] = $this->settings->logoUrl;
        }
        $query['expire_at'] = $expiresAt;
        if (!Decimal::isZero($this->settings->tolerance)) {
            $query['tolerance'] = $this->settings->tolerance;
        }
        return $query;
    }

    public function callbackUrl(): string
    {
        $module = (string) ($this->params['paymentmethod'] ?? 'noncepay');
        return rtrim((string) $this->params['systemurl'], '/') . '/modules/gateways/callback/' . rawurlencode($module)
            . '.php?invoice_id=' . (int) $this->params['invoiceid'];
    }

    private function button(): string
    {
        $action = rtrim((string) $this->params['systemurl'], '/') . '/viewinvoice.php';
        return '<form method="get" action="' . self::e($action) . '" class="noncepay-pay">'
            . '<input type="hidden" name="id" value="' . (int) $this->params['invoiceid'] . '">'
            . '<input type="hidden" name="noncepay" value="pay">'
            . '<button type="submit" class="btn btn-primary">' . self::e($this->lang->get('pay')) . '</button>'
            . '<p class="small text-muted" style="margin-top:6px">' . self::e($this->lang->get('pay_hint')) . '</p>'
            . '</form>';
    }

    private function confirmingNotice(): string
    {
        $url = rtrim((string) $this->params['systemurl'], '/') . '/viewinvoice.php?id=' . (int) $this->params['invoiceid'];
        return '<div class="alert alert-success noncepay-confirming">' . self::e($this->lang->get('confirming'))
            . ' <a href="' . self::e($url) . '">' . self::e($this->lang->get('refresh')) . '</a></div>';
    }

    private function alert(string $message): string
    {
        return '<div class="alert alert-warning noncepay-error">' . self::e($message) . '</div>';
    }

    /** Fallback when headers are already sent: a link, followed automatically. */
    public function redirectHtml(string $url): string
    {
        return '<p><a href="' . self::e($url) . '" class="btn btn-primary">' . self::e($this->lang->get('continue')) . '</a></p>'
            . '<script>window.location.href = ' . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ';</script>';
    }

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
