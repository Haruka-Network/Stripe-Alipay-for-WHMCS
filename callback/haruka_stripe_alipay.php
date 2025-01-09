<?php

use Stripe\Webhook;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}
if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
    die("错误请求");
}

$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
    $event = Webhook::constructEvent(
        $payload, $sig_header, $gatewayParams['StripeWebhookKey']
    );
} catch(\UnexpectedValueException $e) {
    http_response_code(400);
    exit();
} catch(Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit();
}

try {
    if ($event->type == 'payment_intent.succeeded') {
        $paymentIntent = $event->data->object;
        if ($paymentIntent->status == 'succeeded') {
            $invoiceId = $paymentIntent['metadata']['invoice_id'];
            $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
			checkCbTransID($paymentIntent['id']);
            echo "Pass the checkCbTransID check\n";
            logTransaction($gatewayParams['name'], $event, 'Callback successful');
            addInvoicePayment(
                $invoiceId,
                $paymentIntent['id'],
                $paymentIntent['metadata']['original_amount'],
                0,
                $gatewayModuleName
            );
            echo "Success to addInvoicePayment\n";
        }
    }
    echo 'Received unhandled event type: ' . $event->type;
} catch (Exception $e) {
    logTransaction($gatewayParams['name'], $e, 'error-callback');
    http_response_code(400);
    echo $e;
}