<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Stripe\StripeClient;
use WHMCS\Database\Capsule;
use Illuminate\Database\Schema\Blueprint;

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
        'ExchangeType' => array(
            'FriendlyName' => '获取汇率源',
            'Type' => 'dropdown',
            'Options' => array(
                'neutrino' => '默认源',
                'wise' => 'Wise 源',
                'visa' => 'Visa 源',
                'unionpay' => '银联源',
                'coinbase' => 'Coinbase 源',
            ),
            'Description' => '支持多种数据源，比较汇率：https://github.com/DyAxy/NewExchangeRatesTable/tree/main/data',
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
    $exchange = haruka_stripe_alipay_exchange($params['StripeCurrency'], $params['currency'], strtolower($params['ExchangeType']));
    if ($exchange < 0) {
        return '<div class="alert alert-danger text-center" role="alert">支付汇率错误，请联系客服进行处理</div>';
    }
    
    // 验证支付金额是否满足最小要求
    $validation = haruka_stripe_alipay_validate_amount($params['amount'], $params['StripeCurrency'], $exchange);
    if (!$validation['valid']) {
        return '<div class="alert alert-warning text-center" role="alert">' . $validation['error'] . '</div>';
    }

    try {
        $stripe = new \Stripe\StripeClient($params['StripeSkLive']);

        // 查询支付订单
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
            // 订单已存在，获取订单状态
            $paymentIntent = $stripe->paymentIntents->retrieve($invoice->transId, []);
        }
        // 更新支付金额
        if ($paymentIntent->status != 'succeeded' && $paymentIntent->metadata->original_amount != $params['amount']) {
            $paymentIntent = $stripe->paymentIntents->update($paymentIntent->id, [
                'amount' => floor($params['amount'] * $exchange * 100.00),
                'metadata' => [
                    'original_amount' => $params['amount']
                ],
            ]);
        }
        // 创建支付方式
        if ($paymentIntent->status == 'requires_payment_method') {
            $paymentIntent = $stripe->paymentIntents->update($paymentIntent->id, [
                'payment_method' => $stripe->paymentMethods->create([
                    'type' => 'alipay'
                ])
            ]);
        }
        // 确认支付
        if ($paymentIntent->status == 'requires_confirmation') {
            $paymentIntent = $stripe->paymentIntents->confirm(
                $paymentIntent->id,
                [
                    'return_url' => $params['systemurl'] . 'viewinvoice.php?id=' . $params['invoiceid'],
                ]
            );
        }
        // 处理需要用户操作的状态
        if ($paymentIntent->status == 'requires_action') {
            $url = explode("?", $paymentIntent['next_action']['alipay_handle_redirect']['url']);
            $secret = explode("=", $url[1])[1];
            return '<form action="' . $url[0] . '" method="get"><input type="hidden" name="client_secret" value="' . $secret . '"><input type="submit" class="btn btn-primary" value="' . $params['langpaynow'] . '" /></form>';
        }
    } catch (Exception $e) {
        return '<div class="alert alert-danger text-center" role="alert">支付网关错误，请联系客服进行处理</div>';
    }
    return '<div class="alert alert-danger text-center" role="alert">支付网关错误，请联系客服进行处理</div>';
}

function haruka_stripe_alipay_refund($params)
{
    $stripe = new \Stripe\StripeClient($params['StripeSkLive']);
    try {
        $responseData = $stripe->paymentIntents->retrieve($params['transid']);
        // 获取实际支付金额和原始金额
        $actualAmount = $responseData->amount_received;
        $originalAmount = $params['amount'];
        // whmcs 退款金额
        $amount = ($originalAmount - $params['RefundFixed']) / ($params['RefundPercent'] / 100 + 1);
        // whmcs 退款手续费
        $fees = $originalAmount - $amount;
        // stripe 退款金额
        $amount = $amount / $originalAmount * $actualAmount;
        $amount = round($amount, 2);

        $responseData = $stripe->refunds->create([
            'payment_intent' => $params['transid'],
            'amount' => (int)$amount,
            'metadata' => [
                'invoice_id' => $params['invoiceid'],
                'original_amount' => $responseData->metadata->original_amount,
            ]
        ]);
        return array(
            'status' => ($responseData->status === 'succeeded' || $responseData->status === 'pending') ? 'success' : 'error',
            'rawdata' => $responseData,
            'transid' => $params['transid'],
            'fees' => $fees,
        );
    } catch (Exception $e) {
        return array(
            'status' => 'error',
            'rawdata' => $e->getMessage(),
            'transid' => $params['transid'],
            'fees' => $fees,
        );
    }
}

function haruka_stripe_alipay_exchange($from, $to, $type)
{
    try {
        // Fetch Exchange Rates from a URL
        $url = 'https://raw.githubusercontent.com/DyAxy/NewExchangeRatesTable/main/data/' . $type . '.json';
        $result = file_get_contents($url, false);
        if ($result === false) {
            throw new Exception("Failed to fetch data from the URL.");
        }
        $result = json_decode($result, true);

        // Extract currency rates
        $toCurrency = $result['data'][strtoupper($to)];
        $fromCurrency = $result['data'][strtoupper($from)];
        if ($toCurrency < 0 || $fromCurrency < 0) {
            throw new Exception("Invalid currency rate.");
        }
        return $toCurrency / $fromCurrency;
    } catch (Exception $e) {
        return -1; // Return 0 on error
    }
}

/**
 * 获取 Stripe 支持货币的最小收费金额表格
 * 基于 Stripe 官方文档: https://docs.stripe.com/currencies#minimum-and-maximum-charge-amounts
 * 金额已转换为最小货币单位（分）
 */
function haruka_stripe_alipay_minimum_amounts_list()
{
    return [
        'USD' => 50,      // $0.50
        'AED' => 200,     // 2.00 د.إ
        'AUD' => 50,      // $0.50
        'BGN' => 100,     // лв1.00
        'BRL' => 50,      // R$0.50
        'CAD' => 50,      // $0.50
        'CHF' => 50,      // 0.50 Fr
        'CZK' => 1500,    // 15.00Kč
        'DKK' => 250,     // 2.50 kr.
        'EUR' => 50,      // €0.50
        'GBP' => 30,      // £0.30
        'HKD' => 400,     // $4.00
        'HUF' => 17500,   // 175.00 Ft
        'INR' => 50,      // ₹0.50
        'JPY' => 50,      // ¥50 (零小数货币)
        'MXN' => 1000,    // $10
        'MYR' => 200,     // RM 2
        'NOK' => 300,     // 3.00 kr.
        'NZD' => 50,      // $0.50
        'PLN' => 200,     // 2.00 zł
        'RON' => 200,     // lei2.00
        'SEK' => 300,     // 3.00 kr.
        'SGD' => 50,      // $0.50
        'THB' => 1000,    // ฿10
    ];
}

/**
 * 验证支付金额是否满足最小要求
 * @param float $amount 金额
 * @param string $currency 货币代码
 * @param float $exchange 汇率
 * @return array 包含验证结果和错误信息
 */
function haruka_stripe_alipay_validate_amount($amount, $currency, $exchange)
{
    $minimumAmounts = haruka_stripe_alipay_minimum_amounts_list();
    $currencyUpper = strtoupper($currency);

    if (!isset($minimumAmounts[$currencyUpper])) {
        return [
            'valid' => false,
            'error' => "不支持的货币：{$currency}"
        ];
    }

    $convertedAmount = floor($amount * $exchange * 100);
    $minimumRequired = $minimumAmounts[$currencyUpper];

    if ($convertedAmount < $minimumRequired) {
        $minimumDisplay = number_format($minimumRequired / 100, 2);
        $currentDisplay = number_format($convertedAmount / 100, 2);

        return [
            'valid' => false,
            'error' => "支付金额过小。"
        ];
    }

    return ['valid' => true, 'error' => ''];
}
