<?php
use Stripe\StripeClient;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function HarukaStripeAlipay_MetaData()
{
    return array(
        'DisplayName' => 'Haruka Stripe Alipay',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * @see https://developers.whmcs.com/payment-gateways/configuration/
 *
 * @return array
 */
function HarukaStripeAlipay_config() {
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Haruka Stripe Alipay',
        ),
        'StripeSkLive' => array(
            'FriendlyName' => '密钥',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '填写从Stripe获取到的密钥（SK_LIVE）',
        ),
        'StripeWebhookKey' => array(
            'FriendlyName' => 'Stripe Webhook密钥',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '填写从Stripe获取到的Webhook密钥签名',
        ),
        'StripeCurrency' => array(
            'FriendlyName' => '发起交易货币',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '默认获取WHMCS的货币，与您设置的发起交易货币进行汇率转换，再使用转换后的价格和货币向Stripe请求',
        )
    );
}

/**
 * Payment link.
 *
 * Required by third party payment gateway modules only.
 *
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @return string
 */
function HarukaStripeAlipay_link($params){
    $exchange = exchange($params['currency'], strtoupper($params['StripeCurrency']));
    if (!$exchange) {
        return '<div class="alert alert-danger text-center" role="alert">支付汇率错误，请联系客服进行处理</div>';
    }
    try {
        $stripe = new Stripe\StripeClient($params['StripeSkLive']);

        // 创建支付方式
        $paymentMethod = $stripe->paymentMethods->create([
            'type' => 'alipay'
        ]);

        // 创建支付订单
        $paymentIntent = $stripe->paymentIntents->create([
            'amount' => floor($params['amount'] * $exchange * 100.00),
            'currency' => $params['StripeCurrency'],
            'payment_method' => $paymentMethod['id'],
            'metadata' => [
                'invoice_id' => $params['invoiceid'],
                'original_amount' => $params['amount']
            ],
        ]);

        // 获取订单链接
        $paymentConfirm = $stripe->paymentIntents->confirm(
            $paymentIntent['id'],
            [
              'return_url' => $params['systemurl'] . 'modules/gateways/harukastripealipay/result.php?order_id=' . $params['invoiceid'],
            ]
        );
    } catch (Exception $e){
        return '<div class="alert alert-danger text-center" role="alert">支付网关错误，请联系客服进行处理</div>';
    }
    if ($paymentConfirm->status == 'requires_action') {
        $url = explode("?",$paymentConfirm['next_action']['alipay_handle_redirect']['url']);
        $secret = explode("=",$url[1])[1];
        return '<form action="'.$url[0].'" method="get"><input type="hidden" name="client_secret" value="'.$secret.'"><input type="submit" class="btn btn-primary" value="'.$params['langpaynow'].'" /></form>';
    }
    return '<div class="alert alert-danger text-center" role="alert">发生错误，请创建工单联系客服处理</div>';
}
function exchange($from, $to)
{
    try {
        $url = 'https://raw.githubusercontent.com/DyAxy/ExchangeRatesTable/main/data.json';
    
        $result = file_get_contents($url,false);
        $result = json_decode($result, true);
		echo $result['rates'][strtoupper($to)]/$result['rates'][strtoupper($from)];
    } catch (Exception $e){
        echo "Exchange error: ".$e;
        return "Exchange error: ".$e;
    }
}