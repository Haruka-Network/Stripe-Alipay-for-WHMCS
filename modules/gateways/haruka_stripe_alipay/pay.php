<?php
/**
 * Haruka Stripe Alipay - Payment Redirect Handler
 *
 * Target of the Pay button rendered by the _link function. Verifies the
 * signed invoice details, prepares the PaymentIntent (create/reuse/confirm)
 * and redirects the customer to the Alipay page returned by Stripe.
 *
 * Keeping this out of the _link function means invoice pages never block on
 * Stripe or the exchange-rate source.
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 * @license MIT
 */

use HarukaStripeAlipay\Autoloader;
use HarukaStripeAlipay\Config;
use HarukaStripeAlipay\GatewayException;
use HarukaStripeAlipay\Lang;
use HarukaStripeAlipay\Logger;
use HarukaStripeAlipay\StripeGateway;
use HarukaStripeAlipay\View;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';

require_once __DIR__ . '/lib/Autoloader.php';
Autoloader::register();

$gatewayParams = getGatewayVariables(Config::MODULE_NAME);
if (empty($gatewayParams['type'])) {
    die('Module Not Activated');
}

$config = new Config($gatewayParams);

/**
 * Render a standalone notice page and stop.
 *
 * @param Config $config
 * @param string $messageKey
 * @param array  $context
 * @param int    $invoiceId
 */
$fail = function (Config $config, $messageKey, array $context, $invoiceId) {
    $backUrl = $invoiceId > 0
        ? $config->systemUrl() . 'viewinvoice.php?id=' . $invoiceId
        : $config->systemUrl() . 'clientarea.php';

    echo View::standalonePage(
        $config->displayName(),
        Lang::trans($messageKey, $context),
        $backUrl,
        Lang::trans('back_to_invoice')
    );
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $fail($config, 'error_bad_request', [], 0);
}

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$amount = (string) ($_POST['amount'] ?? '');
$currency = strtoupper((string) ($_POST['currency'] ?? ''));
$signature = (string) ($_POST['signature'] ?? '');

$expected = $config->signPayload((string) $invoiceId, $amount, $currency);
if (
    $invoiceId <= 0
    || $amount === ''
    || !is_numeric($amount)
    || $signature === ''
    || !hash_equals($expected, $signature)
) {
    $fail($config, 'error_bad_request', [], $invoiceId);
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    $fail($config, 'error_invoice_not_found', [], 0);
}
if ($invoice->status !== 'Unpaid') {
    $fail($config, 'error_invoice_paid', [], $invoiceId);
}

try {
    $gateway = new StripeGateway($config);
    $result = $gateway->preparePayment($invoiceId, $amount, $currency);
} catch (GatewayException $e) {
    Logger::module($config, 'Pay Redirect', ['invoice_id' => $invoiceId], 'ERROR: ' . $e->getMessage());
    $fail($config, $e->getLangKey(), $e->getContext(), $invoiceId);
} catch (Exception $e) {
    Logger::module($config, 'Pay Redirect', ['invoice_id' => $invoiceId], 'ERROR: ' . $e->getMessage());
    $fail($config, 'error_gateway', [], $invoiceId);
}

if ($result['action'] === 'redirect') {
    header('Location: ' . $result['url'], true, 303);
    exit;
}

// action = notice (payment already succeeded or still processing)
echo View::standalonePage(
    $config->displayName(),
    Lang::trans($result['message_key']),
    $config->systemUrl() . 'viewinvoice.php?id=' . $invoiceId,
    Lang::trans('back_to_invoice')
);
exit;
