# Stripe Alipay for WHMCS

通过 Stripe PaymentIntents 在 WHMCS 中收取支付宝付款的第三方网关模块。

点击账单上的支付按钮后直接拉起支付宝支付页面（支持 PC / 移动端）。账单金额会按可选的汇率源换算为你配置的 Stripe 交易货币后发起支付；到账由验签后的 Webhook 自动入账；支持部分退款，并可配置固定与百分比退款手续费。

> 2024 年 8 月起 Stripe 移除了 Source API，本模块使用 [PaymentIntents 的 Alipay direct API 流程](https://docs.stripe.com/payments/alipay/accept-a-payment?payment-ui=direct-api)。

## 功能特性

- **支付**：Alipay 重定向支付流程，PaymentIntent 按账单复用，重复点击不会产生重复订单（幂等键）
- **汇率**：支持 Neutrino / Wise / Visa / 银联 / Coinbase 多个[数据源](https://github.com/DyAxy/NewExchangeRatesTable/tree/main/data)，结果缓存 15 分钟，源站不可用时回退最近一次成功数据（最长 24 小时）
- **入账**：Webhook 使用官方 SDK 验签，按 WHMCS 回调规范校验账单与重复交易后入账
- **退款**：支持部分退款；按「固定金额 + 百分比」扣除手续费后，按实际支付比例换算为 Stripe 货币退款
- **货币**：正确处理 JPY 等零小数货币，校验 Stripe 最小/最大收款金额，交易货币限定为 Alipay 支持的货币
- **性能**：账单页只渲染支付按钮，点击后才请求 Stripe 与汇率源，账单页加载不再依赖外部服务

## 目录结构

```
modules/gateways/
├── haruka_stripe_alipay.php            # 网关入口（MetaData / config / link / refund）
├── callback/
│   └── haruka_stripe_alipay.php        # Stripe Webhook 回调端点
└── haruka_stripe_alipay/
    ├── pay.php                         # 支付跳转处理（支付按钮的提交目标）
    ├── whmcs.json                      # WHMCS 后台读取的模块元数据
    ├── composer.json                   # 可选：独立安装 Stripe SDK
    └── lib/                            # 模块类库（HarukaStripeAlipay 命名空间）
```

## 安装

1. 将仓库中的 `modules/` 目录上传到 WHMCS 根目录（与现有 `modules/` 合并）。
2. WHMCS 后台 → System Settings → Payment Gateways 中激活 **Haruka Stripe Alipay**。
3. 填写配置：

   | 配置项 | 说明 |
   | --- | --- |
   | Stripe 密钥 | Stripe 后台的 `sk_live_...`（测试环境 `sk_test_...`） |
   | Webhook 签名密钥 | Webhook 端点的 `whsec_...` |
   | 发起交易货币 | 向 Stripe 请求使用的货币，实测需要 CNY 或开户当地货币才支持支付宝 |
   | 汇率数据源 | 账单货币 → 交易货币的换算数据源 |
   | 退款扣除固定金额 / 百分比 | 退款时预先扣除的手续费 |

4. 在 [Stripe Dashboard → Developers → Webhooks](https://dashboard.stripe.com/webhooks) 添加端点：

   - 地址：`https://你的域名/modules/gateways/callback/haruka_stripe_alipay.php`
   - 事件：`payment_intent.succeeded`（可选添加 `payment_intent.payment_failed`、`charge.refunded` 以便记录日志）

模块使用 WHMCS 自带的 `stripe/stripe-php` SDK，无需额外安装。若你的环境缺少该 SDK，在 `modules/gateways/haruka_stripe_alipay/` 下执行 `composer install` 即可。

## 从 v1.x 升级

- 直接用新目录结构覆盖即可，数据表 `mod_harukastripepay_invoices` 与全部配置项名称保持不变。
- 密钥字段类型改为了 password（存储加密）：**升级后需在网关设置中重新填写 Stripe 密钥与 Webhook 签名密钥并保存一次**。
- v1.x 中在账单页直接完成的 Stripe 请求已移至点击支付按钮之后，支付流程与回调地址不变。

## 调试

- **Billing → Gateway Log**：Webhook 入账、支付失败、Stripe 后台退款等交易日志。
- **Configuration → System Logs → Module Log**（开启 Debug Logging 后）：每次 Stripe API 调用的摘要，密钥自动脱敏。

## 已知限制

- Stripe Dashboard 中手工发起的退款只会记录到 Gateway Log，不会自动写入 WHMCS 交易记录（在 WHMCS 中发起的退款会正常记录）。
- Alipay 不支持拒付（dispute）流程，也不支持手动 capture。

## License

[MIT](LICENSE)
