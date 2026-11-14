<?php

namespace NoncePay\Whmcs;

/**
 * The gateway configuration, read from the WHMCS module parameters and checked.
 *
 * Wallets are checked lightly (format per family); the API checks them strictly when a link is created.
 */
final class Settings
{
    const DEFAULT_API = 'https://api.noncepay.app';

    const FAMILIES = ['evm', 'tron', 'btc', 'ltc', 'bch', 'doge', 'sol'];

    // NoncePay's fiat list (app/rates/config.ts): a link can only be priced in one of these.
    const FIAT = [
        'USD', 'EUR', 'GBP', 'CAD', 'JPY', 'AED', 'MYR', 'IDR', 'THB', 'CHF', 'SGD', 'RUB', 'ZAR',
        'TRY', 'LKR', 'RON', 'BGN', 'HUF', 'CZK', 'PHP', 'PLN', 'UGX', 'MXN', 'INR', 'HKD', 'CNY',
        'BRL', 'DKK', 'TWD', 'AUD', 'NGN', 'SEK', 'NOK', 'UAH', 'VND', 'COP', 'NZD',
    ];

    const MAX_MERCHANT_NAME = 64;

    /** @var array<string,string> family => wallet */
    public $wallets = [];
    /** @var string Comma-separated ticker paths, empty for every coin of the wallets given. */
    public $coins = '';
    /** @var string */
    public $merchantName = '';
    /** @var string */
    public $logoUrl = '';
    /** @var string Percent, 0 to 1. */
    public $tolerance = '0';
    /** @var string Without trailing slash. */
    public $apiBase = self::DEFAULT_API;
    /** @var bool */
    public $testMode = false;
    /** @var string Pinned PEM, empty to fetch /pubkey/. */
    public $publicKey = '';
    /** @var string[] Human-readable problems, empty when usable. */
    public $errors = [];

    public static function fromParams(array $params): self
    {
        $s = new self();
        $s->testMode = self::flag($params['test_mode'] ?? '');

        foreach (self::FAMILIES as $family) {
            $wallet = trim((string) ($params['address_' . $family] ?? ''));
            if ($wallet === '') {
                continue;
            }
            if (!self::walletLooksValid($family, $wallet)) {
                $s->errors[] = sprintf('The %s wallet "%s" does not look like a valid address.', strtoupper($family), $wallet);
                continue;
            }
            $s->wallets[$family] = $wallet;
        }
        if (!$s->wallets && !$s->errors) {
            $s->errors[] = 'Enter at least one receiving wallet.';
        }

        $coins = strtolower(preg_replace('/\s+/', '', (string) ($params['coins'] ?? '')));
        if ($coins !== '' && !preg_match('/^[a-z0-9]+(\/[a-z0-9.]+)?(,[a-z0-9]+(\/[a-z0-9.]+)?)*$/', $coins)) {
            $s->errors[] = 'Coins must be a comma-separated list of tickers such as trc20/usdt,bep20/usdt.';
        } else {
            $s->coins = $coins;
        }

        $name = trim((string) ($params['merchant_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($params['companyname'] ?? ''));
        }
        $s->merchantName = self::truncate($name, self::MAX_MERCHANT_NAME);

        $logo = trim((string) ($params['logo_url'] ?? ''));
        if ($logo !== '' && !self::isUrl($logo, false)) {
            $s->errors[] = 'The logo URL must be an https URL.';
        } else {
            $s->logoUrl = $logo;
        }

        $tolerance = trim((string) ($params['tolerance'] ?? ''));
        $parsed = Decimal::parse($tolerance === '' ? '0' : rtrim($tolerance, '% '));
        if ($parsed === null || Decimal::cmp($parsed, '1') > 0) {
            $s->errors[] = 'The tolerance must be a percentage between 0 and 1.';
        } else {
            $s->tolerance = $parsed;
        }

        $api = rtrim(trim((string) ($params['api_base'] ?? '')), '/');
        if ($api === '') {
            $api = self::DEFAULT_API;
        }
        if (!self::isUrl($api, $s->testMode)) {
            $s->errors[] = $s->testMode
                ? 'The API base URL must be an http or https URL.'
                : 'The API base URL must be an https URL (http is allowed only in test mode).';
        } else {
            $s->apiBase = $api;
        }

        $pem = trim((string) ($params['public_key'] ?? ''));
        if ($pem !== '' && !(function_exists('openssl_pkey_get_public') && openssl_pkey_get_public($pem))) {
            $s->errors[] = 'The pinned public key is not a valid PEM public key.';
        } else {
            $s->publicKey = $pem;
        }

        return $s;
    }

    public static function currencySupported(string $code): bool
    {
        return in_array(strtoupper($code), self::FIAT, true);
    }

    public static function walletLooksValid(string $family, string $wallet): bool
    {
        // Split syntax: 0.7@addrA|0.3@addrB.
        foreach (explode('|', $wallet) as $output) {
            $parts = explode('@', $output);
            if (count($parts) > 2 || (count($parts) === 2 && Decimal::parse($parts[0]) === null)) {
                return false;
            }
            if (!self::addressLooksValid($family, (string) end($parts))) {
                return false;
            }
        }
        return true;
    }

    private static function addressLooksValid(string $family, string $address): bool
    {
        $base58 = '[1-9A-HJ-NP-Za-km-z]';
        switch ($family) {
            case 'evm':
                return (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $address);
            case 'tron':
                return (bool) preg_match('/^T' . $base58 . '{33}$/', $address);
            case 'sol':
                return (bool) preg_match('/^' . $base58 . '{32,44}$/', $address);
            case 'bch':
                return (bool) preg_match('/^((bitcoincash|bchtest|bchreg):)?[0-9A-Za-z]{25,90}$/', $address);
            default:
                // btc, ltc, doge: base58 legacy/P2SH or bech32.
                return (bool) preg_match('/^(' . $base58 . '{25,40}|[a-z]{2,6}1[0-9a-zA-Z]{6,90})$/', $address);
        }
    }

    private static function isUrl(string $url, bool $allowHttp): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        return $scheme === 'https' || ($allowHttp && $scheme === 'http');
    }

    private static function flag($value): bool
    {
        return in_array(strtolower((string) $value), ['on', 'yes', '1', 'true'], true);
    }

    public static function truncate(string $text, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max, 'UTF-8');
        }
        return strlen($text) > $max ? substr($text, 0, $max) : $text;
    }
}
