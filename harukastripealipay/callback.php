<?php
use Stripe\StripeClient;
use Stripe\Webhook;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayName = 'HarukaStripeAlipay';
$gatewayParams = getGatewayVariables($gatewayName);
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}
if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
    die("错误请求");
}

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
    $event = Webhook::constructEvent(
        $payload, $sig_header, $gatewayParams['StripeWebhookKey']
    );
} catch(\UnexpectedValueException $e) {
    logTransaction($gatewayParams['paymentmethod'], $e, $gatewayName.': Invalid payload');
    http_response_code(400);
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    logTransaction($gatewayParams['paymentmethod'], $e, $gatewayName.': Invalid signature');
    http_response_code(400);
    exit();
}

try {
    if ($event->type == 'payment_intent.succeeded') {
        $stripe = new Stripe\StripeClient($gatewayParams['StripeSkLive']);
        $paymentId = $event->data->object->id;

        $paymentIntent = $stripe->paymentIntents->retrieve($paymentId,[]);

        if ($paymentIntent['status'] == 'succeeded') {

            $invoiceId = checkCbInvoiceID($paymentIntent['metadata']['invoice_id'], $gatewayParams['paymentmethod']);
			checkCbTransID($paymentId);
            echo "Pass the checkCbTransID check\n";
            logTransaction($gatewayParams['paymentmethod'], $paymentIntent, $gatewayName.': Callback successful');
            addInvoicePayment(
                $invoiceId,
                $paymentId,
                $paymentIntent['metadata']['original_amount'],
                0,
                $params['paymentmethod']
            );
            echo "Success to addInvoicePayment\n";
		}
    }
    
    
} catch (Exception $e) {
    logTransaction($gatewayParams['paymentmethod'], $e, 'error-callback');
    http_response_code(400);
    echo $e;
}
