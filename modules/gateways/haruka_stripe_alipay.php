<?php
/**
 * Haruka Stripe Alipay - WHMCS Payment Gateway Module
 *
 * Accepts Alipay payments through Stripe PaymentIntents, converting the
 * invoice amount into a configurable Stripe presentment currency using a
 * selectable exchange-rate source.
 *
 * Module layout:
 *   modules/gateways/haruka_stripe_alipay.php            this file (thin entry)
 *   modules/gateways/callback/haruka_stripe_alipay.php   Stripe webhook endpoint
 *   modules/gateways/haruka_stripe_alipay/pay.php        redirect handler (Pay button target)
 *   modules/gateways/haruka_stripe_alipay/lib/           module classes
 *
 * All functions are prefixed with the module filename per the WHMCS gateway
 * module conventions.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 * @see https://docs.stripe.com/payments/alipay
 * @license MIT
 */

use HarukaStripeAlipay\Autoloader;
use HarukaStripeAlipay\Config;
use HarukaStripeAlipay\Currency;
use HarukaStripeAlipay\GatewayException;
use HarukaStripeAlipay\Lang;
use HarukaStripeAlipay\Storage;
use HarukaStripeAlipay\StripeGateway;
use HarukaStripeAlipay\View;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Load the module's class library from the support directory.
 *
 * Guarded so an incomplete upload (a common install mistake) degrades into a
 * visible warning in the admin area instead of a fatal error that takes down
 * the whole payment gateway configuration page.
 *
 * @return bool Whether the support directory is present and loaded
 */
function haruka_stripe_alipay_bootstrap()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $autoloader = __DIR__ . '/haruka_stripe_alipay/lib/Autoloader.php';
    if (!is_file($autoloader)) {
        return $ready = false;
    }

    require_once $autoloader;
    Autoloader::register();

    return $ready = true;
}

/** Shown wherever the module cannot run because files are missing. */
define(
    'HARUKA_STRIPE_ALIPAY_INCOMPLETE',
    'Haruka Stripe Alipay 安装不完整：缺少 modules/gateways/haruka_stripe_alipay/ 支持目录，请完整上传模块文件。'
);

/**
 * Define module related meta data.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function haruka_stripe_alipay_MetaData()
{
    return [
        'DisplayName' => 'Haruka Stripe Alipay',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Define gateway configuration options.
 *
 * Field system names are unchanged from v1.x so existing installations keep
 * their settings. Note the key fields are now "password" type: after
 * upgrading from v1.x, re-enter both secrets once and save.
 *
 * @see https://developers.whmcs.com/payment-gateways/configuration/
 *
 * @return array
 */
function haruka_stripe_alipay_config()
{
    if (!haruka_stripe_alipay_bootstrap()) {
        return [
            'FriendlyName' => [
                'Type' => 'System',
                'Value' => 'Haruka Stripe Alipay',
            ],
            'incompleteInstall' => [
                'FriendlyName' => '⚠ 安装不完整',
                'Type' => 'text',
                'Size' => '80',
                'Description' => HARUKA_STRIPE_ALIPAY_INCOMPLETE,
            ],
        ];
    }

    // Make sure the module tables exist by the time the gateway is being
    // configured; later code only ever needs to touch them lazily.
    Storage::ensureSchema();

    $currencyOptions = [];
    foreach (Currency::ALIPAY_CURRENCIES as $code) {
        $currencyOptions[$code] = $code;
    }

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Haruka Stripe Alipay',
        ],
        'StripeSkLive' => [
            'FriendlyName' => 'Stripe 密钥 (Secret Key)',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => '填写 Stripe 后台获取的 sk_live_...（测试环境可填 sk_test_...）',
        ],
        'StripeWebhookKey' => [
            'FriendlyName' => 'Webhook 签名密钥',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Webhook 端点的签名密钥 whsec_...，端点地址：/modules/gateways/callback/haruka_stripe_alipay.php，侦听事件 payment_intent.succeeded',
        ],
        'StripeCurrency' => [
            'FriendlyName' => '发起交易货币',
            'Type' => 'dropdown',
            'Options' => $currencyOptions,
            'Default' => 'CNY',
            'Description' => '向 Stripe 发起交易使用的货币。账单金额将按所选汇率源换算成该货币，需为 Stripe 账户支持的 Alipay 交易货币',
        ],
        'ExchangeType' => [
            'FriendlyName' => '汇率数据源',
            'Type' => 'dropdown',
            'Options' => Config::EXCHANGE_SOURCES,
            'Default' => 'neutrino',
            'Description' => '汇率数据对比：https://github.com/DyAxy/NewExchangeRatesTable/tree/main/data（结果缓存 15 分钟）',
        ],
        'RefundFixed' => [
            'FriendlyName' => '退款扣除固定金额',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0.00',
            'Description' => '账单货币金额',
        ],
        'RefundPercent' => [
            'FriendlyName' => '退款扣除百分比',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0.00',
            'Description' => '%',
        ],
    ];
}

/**
 * Render the payment button on the invoice.
 *
 * No Stripe or exchange-rate calls happen here: the button posts the signed
 * invoice details to pay.php, which prepares the PaymentIntent and redirects
 * the customer to Alipay. Invoice pages therefore load fast and keep working
 * when Stripe or the rate source is briefly unreachable.
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @param array $params Payment gateway module parameters
 * @return string HTML for the invoice payment area
 */
function haruka_stripe_alipay_link($params)
{
    if (!haruka_stripe_alipay_bootstrap()) {
        return '<div class="alert alert-danger text-center" role="alert">'
            . HARUKA_STRIPE_ALIPAY_INCOMPLETE
            . '</div>';
    }

    $config = new Config($params);

    try {
        $config->assertUsable();
    } catch (GatewayException $e) {
        return View::alert(Lang::trans($e->getLangKey(), $e->getContext()), 'warning');
    }

    $invoiceId = (string) $params['invoiceid'];
    $amount = (string) $params['amount'];
    $currency = strtoupper((string) $params['currency']);

    return View::payForm(
        $config->systemUrl() . 'modules/gateways/haruka_stripe_alipay/pay.php',
        [
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $currency,
            'signature' => $config->signPayload($invoiceId, $amount, $currency),
        ],
        !empty($params['langpaynow']) ? $params['langpaynow'] : Lang::trans('pay_now')
    );
}

/**
 * Process a refund initiated from the WHMCS admin area.
 *
 * A fixed fee and a percentage fee (module settings) are withheld from the
 * requested amount; the remainder is refunded on Stripe proportionally to
 * the captured charge. Supports partial refunds.
 *
 * @see https://developers.whmcs.com/payment-gateways/refunds/
 *
 * @param array $params Payment gateway module parameters
 * @return array Refund status, raw response data, transaction id and fees
 */
function haruka_stripe_alipay_refund($params)
{
    if (!haruka_stripe_alipay_bootstrap()) {
        return [
            'status' => 'error',
            'rawdata' => HARUKA_STRIPE_ALIPAY_INCOMPLETE,
            'transid' => isset($params['transid']) ? $params['transid'] : '',
            'fees' => 0,
        ];
    }

    $gateway = new StripeGateway(new Config($params));

    return $gateway->refund($params);
}
