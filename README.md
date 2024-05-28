# Stripe Alipay Gateway For WHMCS

由于2024年8月 Stripe 移除 Source 所以改用 Payment Intent.

基于[https://github.com/Kurenai-Network/KurenaiStripeAlipay](https://github.com/Kurenai-Network/KurenaiStripeAlipay)

回调地址：`/modules/gateways/stripealipay/callback.php`
侦听事件：`payment_intent.succeeded`