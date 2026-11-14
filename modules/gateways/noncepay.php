<?php
/**
 * NoncePay for WHMCS: non-custodial crypto payments through NoncePay payment links.
 *
 * @see https://noncepay.app/docs/guides/whmcs/
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/noncepay/bootstrap.php';

use NoncePay\Whmcs\Api;
use NoncePay\Whmcs\CapsuleStore;
use NoncePay\Whmcs\Gateway;
use NoncePay\Whmcs\Lang;
use NoncePay\Whmcs\Settings;
use NoncePay\Whmcs\WhmcsBridge;

function noncepay_MetaData()
{
    return [
        'DisplayName' => 'NoncePay (crypto)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function noncepay_config()
{
    $wallet = function ($label, $example) {
        return [
            'FriendlyName' => $label,
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Your receiving wallet, e.g. ' . $example . '. Leave empty to not offer these coins.',
        ];
    };

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'NoncePay (crypto)',
        ],
        'address_evm' => $wallet('EVM wallet', '0x… (ETH, USDT/USDC on Ethereum, BNB Smart Chain, Polygon, Base, Arbitrum…)'),
        'address_tron' => $wallet('Tron wallet', 'T… (TRX, USDT TRC-20)'),
        'address_btc' => $wallet('Bitcoin wallet', 'bc1…'),
        'address_ltc' => $wallet('Litecoin wallet', 'ltc1… or L…'),
        'address_bch' => $wallet('Bitcoin Cash wallet', 'bitcoincash:q…'),
        'address_doge' => $wallet('Dogecoin wallet', 'D…'),
        'address_sol' => $wallet('Solana wallet', 'a base58 address (SOL, USDC/USDT on Solana)'),
        'coins' => [
            'FriendlyName' => 'Coins offered',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Optional comma-separated tickers, e.g. trc20/usdt,bep20/usdt,btc. Empty offers every coin of the wallets above.',
        ],
        'merchant_name' => [
            'FriendlyName' => 'Merchant name',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Shown on the payment page (64 characters max). Defaults to your company name.',
        ],
        'logo_url' => [
            'FriendlyName' => 'Logo URL',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Optional https image shown beside your name on a dark background, at most 40 px tall.',
        ],
        'tolerance' => [
            'FriendlyName' => 'Underpayment tolerance (%)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '0',
            'Description' => 'From 0 to 1. With 0.5, an invoice of 100 counts as paid from 99.50 (covers exchange withdrawal fees).',
        ],
        'api_base' => [
            'FriendlyName' => 'API base URL',
            'Type' => 'text',
            'Size' => '40',
            'Default' => Settings::DEFAULT_API,
            'Description' => 'Leave as is unless testing against another NoncePay instance.',
        ],
        'public_key' => [
            'FriendlyName' => 'Pinned public key',
            'Type' => 'textarea',
            'Rows' => '4',
            'Cols' => '60',
            'Description' => 'Optional. The PEM key from /pubkey/ that signs callbacks. Empty fetches and caches it automatically.',
        ],
        'test_mode' => [
            'FriendlyName' => 'Test mode',
            'Type' => 'yesno',
            'Description' => 'Allows an http API base URL (a local or testnet instance). Keep off in production.',
        ],
    ];
}

/**
 * Rejects a configuration that cannot create a link (WHMCS 8.x config validation).
 */
function noncepay_config_validate(array $params)
{
    $settings = Settings::fromParams($params);
    if ($settings->errors && class_exists('\WHMCS\Exception\Module\InvalidConfiguration')) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration(implode(' ', $settings->errors));
    }
}

function noncepay_link($params)
{
    $settings = Settings::fromParams($params);
    $whmcs = new WhmcsBridge((string) $params['name'], (string) $params['paymentmethod']);
    $logger = function ($action, $url, $response) {
        if (function_exists('logModuleCall')) {
            logModuleCall('noncepay', $action, $url, $response);
        }
    };
    $gateway = new Gateway(
        $params,
        $settings,
        new CapsuleStore(),
        new Api($settings->apiBase, null, $logger),
        $whmcs,
        Lang::forParams($params),
        time()
    );

    $result = $gateway->render($_GET);
    if (isset($result['redirect'])) {
        if (!headers_sent()) {
            header('Location: ' . $result['redirect'], true, 303);
            exit;
        }
        return $gateway->redirectHtml($result['redirect']);
    }
    return $result['html'];
}
