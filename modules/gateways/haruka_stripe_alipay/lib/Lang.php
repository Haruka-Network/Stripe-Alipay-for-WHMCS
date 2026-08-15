<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * Client-facing strings. Simplified Chinese is the default; English is used
 * when the active WHMCS client language is English.
 *
 * @license MIT
 */

namespace HarukaStripeAlipay;

class Lang
{
    const STRINGS = [
        'zh' => [
            'pay_now' => '支付宝支付',
            'error_gateway' => '支付网关错误，请联系客服进行处理',
            'error_not_configured' => '支付网关尚未配置完成，请联系客服进行处理',
            'error_exchange' => '支付汇率错误，请联系客服进行处理',
            'error_unsupported_currency' => '不支持的货币：{currency}',
            'error_amount_too_small' => '支付金额过小，货币 {currency} 最低要求为 {minimum}，当前为 {amount}。',
            'error_amount_too_large' => '支付金额超过 Stripe 上限（{currency} {maximum}），请联系客服分期开票。',
            'error_invoice_not_found' => '找不到该账单，请返回重试',
            'error_invoice_paid' => '该账单已支付或无需支付',
            'error_bad_request' => '请求无效，请返回账单页重试',
            'payment_processing' => '支付正在确认中，到账后账单将自动更新',
            'back_to_invoice' => '返回账单',
        ],
        'en' => [
            'pay_now' => 'Pay with Alipay',
            'error_gateway' => 'Payment gateway error. Please contact support.',
            'error_not_configured' => 'The payment gateway is not fully configured. Please contact support.',
            'error_exchange' => 'Exchange rate unavailable. Please contact support.',
            'error_unsupported_currency' => 'Unsupported currency: {currency}',
            'error_amount_too_small' => 'Amount too small: the minimum for {currency} is {minimum}, got {amount}.',
            'error_amount_too_large' => 'Amount exceeds the Stripe maximum ({currency} {maximum}). Please contact support.',
            'error_invoice_not_found' => 'Invoice not found. Please go back and retry.',
            'error_invoice_paid' => 'This invoice is already paid or nothing is due.',
            'error_bad_request' => 'Invalid request. Please go back to the invoice and retry.',
            'payment_processing' => 'Your payment is being confirmed. The invoice will update automatically.',
            'back_to_invoice' => 'Back to invoice',
        ],
    ];

    /**
     * @return string 'zh' or 'en', following the active client language
     */
    public static function locale()
    {
        $language = '';
        if (isset($_SESSION['Language'])) {
            $language = strtolower((string) $_SESSION['Language']);
        }

        return strpos($language, 'english') !== false ? 'en' : 'zh';
    }

    /**
     * @param string $key     String identifier
     * @param array  $context Placeholder values, e.g. ['currency' => 'CNY']
     * @return string
     */
    public static function trans($key, array $context = [])
    {
        $locale = self::locale();
        $text = self::STRINGS[$locale][$key]
            ?? self::STRINGS['zh'][$key]
            ?? $key;

        foreach ($context as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }
}
