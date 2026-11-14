<?php
/**
 * NoncePay callback: verifies x-ca-signature and records the payment of a paid link.
 *
 * @license MIT
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../noncepay/bootstrap.php';

use NoncePay\Whmcs\Api;
use NoncePay\Whmcs\Callback;
use NoncePay\Whmcs\CapsuleStore;
use NoncePay\Whmcs\PublicKey;
use NoncePay\Whmcs\Settings;
use NoncePay\Whmcs\WhmcsBridge;

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    die('Module Not Activated');
}

$settings = Settings::fromParams($gatewayParams);
$store = new CapsuleStore();
$api = new Api($settings->apiBase);
$callback = new Callback(
    $store,
    new WhmcsBridge((string) $gatewayParams['name'], $gatewayModuleName),
    new PublicKey($api, $store, $settings->publicKey),
    time()
);

$signature = isset($_SERVER['HTTP_X_CA_SIGNATURE']) ? (string) $_SERVER['HTTP_X_CA_SIGNATURE'] : '';
list($status, $body) = $callback->handle((string) file_get_contents('php://input'), $signature, $_GET);

http_response_code($status);
echo $body;
