<?php

use Stripe\StripeClient;
use WHMCS\Database\Capsule;
use Illuminate\Database\Schema\Blueprint;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function haruka_stripe_alipay_MetaData()
{
    return array(
        'DisplayName' => 'Haruka Stripe Alipay',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function haruka_stripe_alipay_config()
{
    // 创建 invoices 表，映射 invoiceId 和 transId
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_harukastripepay_invoices')) {
        $schema->create('mod_harukastripepay_invoices', function (Blueprint $table) {
            $table->id();
            $table->integer('invoiceId');
            $table->string('transId')->nullable();
            $table->timestamps();
        });
    }

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Haruka Stripe Alipay',
        ),
        'StripeSkLive' => array(
            'FriendlyName' => 'SK_LIVE 密钥',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '填写从Stripe获取到的密钥（SK_LIVE）',
        ),
        'StripeWebhookKey' => array(
            'FriendlyName' => 'Webhook 密钥',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '填写从Stripe获取到的Webhook密钥签名',
        ),
        'StripeCurrency' => array(
            'FriendlyName' => '发起交易货币',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '默认获取WHMCS的货币，与您设置的发起交易货币进行汇率转换，再使用转换后的价格和货币向Stripe请求',
        ),
        'RefundFixed' => array(
            'FriendlyName' => '退款扣除固定金额',
            'Type' => 'text',
            'Size' => 30,
            'Default' => '0.00',
            'Description' => '$'
        ),
        'RefundPercent' => array(
            'FriendlyName' => '退款扣除百分比金额',
            'Type' => 'text',
            'Size' => 30,
            'Default' => '0.00',
            'Description' => '%'
        )
    );
}

function haruka_stripe_alipay_link($params)
{
    $exchange = haruka_stripe_alipay_exchange($params['currency'], strtoupper($params['StripeCurrency']));
    if (!$exchange) {
        return '<div class="alert alert-danger text-center" role="alert">支付汇率错误，请联系客服进行处理</div>';
    }
    try {
        $stripe = new Stripe\StripeClient($params['StripeSkLive']);

        $invoice = Capsule::table('mod_harukastripepay_invoices')
            ->where('invoiceId', $params['invoiceid'])
            ->first();
        $paymentIntent = null;

        if (!$invoice) {
            // 创建支付订单
            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => floor($params['amount'] * $exchange * 100.00),
                'currency' => $params['StripeCurrency'],
                'description' => "invoiceID: " . $params['invoiceid'],
                'metadata' => [
                    'invoice_id' => $params['invoiceid'],
                    'original_amount' => $params['amount']
                ],
            ]);

            Capsule::table('mod_harukastripepay_invoices')->insert([
                'invoiceId' => $params['invoiceid'],
                'transId' => $paymentIntent->id,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            $paymentIntent = $stripe->paymentIntents->retrieve($invoice->transId, []);
        }
        if ($paymentIntent->status != 'succeeded' && $paymentIntent->metadata->original_amount != $params['amount']) {
            $paymentIntent = $stripe->paymentIntents->update($paymentIntent->id, [
                'amount' => floor($params['amount'] * $exchange * 100.00),
                'metadata' => [
                    'original_amount' => $params['amount']
                ],
            ]);
        }
        if ($paymentIntent->status == 'requires_payment_method') {
            $paymentIntent = $stripe->paymentIntents->update($paymentIntent->id, [
                'payment_method' => $stripe->paymentMethods->create([
                    'type' => 'alipay'
                ])
            ]);
        }
        if ($paymentIntent->status == 'requires_confirmation') {
            $paymentIntent = $stripe->paymentIntents->confirm(
                $paymentIntent->id,
                [
                    'return_url' => $params['systemurl'] . '/viewinvoice.php?id=' . $params['invoiceid'],
                ]
            );
        }
        if ($paymentIntent->status == 'requires_action') {
            $url = explode("?", $paymentIntent['next_action']['alipay_handle_redirect']['url']);
            $secret = explode("=", $url[1])[1];
            return '<form action="' . $url[0] . '" method="get"><input type="hidden" name="client_secret" value="' . $secret . '"><input type="submit" class="btn btn-primary" value="' . $params['langpaynow'] . '" /></form>';
        }
    } catch (Exception $e) {
        return '<div class="alert alert-danger text-center" role="alert">支付网关错误，请联系客服进行处理</div>';
    }
    return '<div class="alert alert-danger text-center" role="alert">发生错误，请创建工单联系客服处理</div>';
}

function haruka_stripe_alipay_refund($params)
{
    $stripe = new Stripe\StripeClient($params['StripeSkLive']);
    $amount = ($params['amount'] - $params['RefundFixed']) / ($params['RefundPercent'] / 100 + 1);
    try {
        $responseData = $stripe->refunds->create([
            'payment_intent' => $params['transid'],
            'amount' => $amount * 100.00,
            'metadata' => [
                'invoice_id' => $params['invoiceid'],
                'original_amount' => $params['amount'],
            ]
        ]);
        return array(
            'status' => ($responseData->status === 'succeeded' || $responseData->status === 'pending') ? 'success' : 'error',
            'rawdata' => $responseData,
            'transid' => $params['transid'],
            'fees' => $params['amount'],
        );
    } catch (Exception $e) {
        return array(
            'status' => 'error',
            'rawdata' => $e->getMessage(),
            'transid' => $params['transid'],
            'fees' => $params['amount'],
        );
    }
}

function haruka_stripe_alipay_exchange($from, $to)
{
    try {
        $url = 'https://raw.githubusercontent.com/DyAxy/ExchangeRatesTable/main/data.json';

        $result = file_get_contents($url, false);
        $result = json_decode($result, true);
        return $result['rates'][strtoupper($to)] / $result['rates'][strtoupper($from)];
    } catch (Exception $e) {
        echo "Exchange error: " . $e;
        return "Exchange error: " . $e;
    }
}