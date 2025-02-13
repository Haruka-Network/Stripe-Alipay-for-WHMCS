# Stripe Alipay For WHMCS

由于2024年8月 Stripe 移除 Source 所以改用 Payment Intent.

支付：直接拉起支付宝支付页面，支持 PC / 移动端，可自定义货币实测需要 CNY 或者开户当地货币才支持支付宝
退款：根据退款比例来转换为退款的当地货币金额

开发 API 版本：2024-06-20，兼容至2024-12-18.acacia

侦听事件：`payment_intent.succeeded`

回调地址：`/modules/gateways/callback/haruka_stripe_alipay.php`