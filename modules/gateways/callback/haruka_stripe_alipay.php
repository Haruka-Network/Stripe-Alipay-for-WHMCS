<?php
/**
 * Haruka Stripe Alipay - Stripe Webhook Endpoint
 *
 * Configure this URL as a webhook endpoint in the Stripe Dashboard:
 *   https://<your-whmcs>/modules/gateways/callback/haruka_stripe_alipay.php
 * listening (at minimum) for the payment_intent.succeeded event.
 *
 * The raw request body is passed straight to signature verification, as the
 * Stripe webhook documentation requires.
 *
 * @see https://docs.stripe.com/webhooks
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 * @license MIT
 */

use HarukaStripeAlipay\Autoloader;
use HarukaStripeAlipay\Config;
use HarukaStripeAlipay\WebhookController;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$autoloader = __DIR__ . '/../haruka_stripe_alipay/lib/Autoloader.php';
if (!is_file($autoloader)) {
    // Incomplete upload: answer 500 so Stripe keeps retrying the event
    // until the module files are restored.
    http_response_code(500);
    die('Gateway module installation is incomplete');
}
require_once $autoloader;
Autoloader::register();

$gatewayParams = getGatewayVariables(Config::MODULE_NAME);
if (empty($gatewayParams['type'])) {
    http_response_code(404);
    die('Module Not Activated');
}

$signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string) $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
if ($signature === '') {
    http_response_code(400);
    die('Missing Stripe-Signature header');
}

$controller = new WebhookController(new Config($gatewayParams));
$controller->handle((string) file_get_contents('php://input'), $signature);
