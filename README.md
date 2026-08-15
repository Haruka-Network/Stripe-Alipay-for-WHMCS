**English** | [简体中文](README_ZH.md)

# Stripe Alipay for WHMCS

A third-party payment gateway module that accepts Alipay payments in WHMCS through Stripe PaymentIntents.

Clicking the pay button on an invoice takes the customer straight to the Alipay payment page (desktop and mobile). The invoice amount is converted into your configured Stripe presentment currency using a selectable exchange-rate source; payments are credited automatically by a signature-verified webhook; partial refunds are supported with configurable fixed and percentage refund fees.

> Stripe removed the Source API in August 2024. This module uses the [PaymentIntents Alipay direct API flow](https://docs.stripe.com/payments/alipay/accept-a-payment?payment-ui=direct-api).

## Features

- **Payments**: Alipay redirect flow. One PaymentIntent is reused per invoice, and idempotency keys ensure repeated clicks never create duplicate charges
- **Exchange rates**: multiple [data sources](https://github.com/DyAxy/NewExchangeRatesTable/tree/main/data) (Neutrino / Wise / Visa / UnionPay / Coinbase), cached for 15 minutes, with fallback to the last successful table (up to 24 hours) when the source is unreachable
- **Crediting**: webhooks are verified with the official SDK, then processed following the WHMCS callback conventions (invoice validation, duplicate transaction protection)
- **Refunds**: partial refunds supported; a fixed fee plus a percentage fee is withheld, and the remainder is refunded on Stripe proportionally to the captured charge
- **Currencies**: correct handling of zero-decimal currencies such as JPY, validation against Stripe minimum/maximum charge amounts, presentment currency restricted to Alipay-supported currencies
- **Performance**: invoice pages only render a payment button — Stripe and the rate source are contacted only after the customer clicks, so invoice pages never block on external services

## Directory layout

```
modules/gateways/
├── haruka_stripe_alipay.php            # Gateway entry (MetaData / config / link / refund)
├── callback/
│   └── haruka_stripe_alipay.php        # Stripe webhook endpoint
└── haruka_stripe_alipay/
    ├── pay.php                         # Payment redirect handler (pay button target)
    ├── whmcs.json                      # Module metadata read by the WHMCS admin
    ├── composer.json                   # Optional: standalone Stripe SDK install
    └── lib/                            # Module classes (HarukaStripeAlipay namespace)
```

## Installation

1. Download the latest zip from [Releases](https://github.com/Haruka-Network/Stripe-Alipay-for-WHMCS/releases) and extract it into your WHMCS root (or upload the repository's `modules/` directory, merging with the existing `modules/`).
2. Activate **Haruka Stripe Alipay** under System Settings → Payment Gateways.
3. Fill in the settings:

   | Setting | Description |
   | --- | --- |
   | Stripe Secret Key | `sk_live_...` from the Stripe Dashboard (`sk_test_...` for test mode) |
   | Webhook Signing Secret | The endpoint's `whsec_...` |
   | Presentment Currency | Currency charged on Stripe. In practice Alipay requires CNY or your Stripe account's local currency |
   | Exchange Rate Source | Data source for converting the invoice currency into the presentment currency |
   | Refund Fixed / Percentage Fee | Fees withheld from refunds |

4. Add a webhook endpoint in the [Stripe Dashboard → Developers → Webhooks](https://dashboard.stripe.com/webhooks):

   - URL: `https://your-domain/modules/gateways/callback/haruka_stripe_alipay.php`
   - Event: `payment_intent.succeeded` (optionally add `payment_intent.payment_failed` and `charge.refunded` for logging)

The module uses the `stripe/stripe-php` SDK bundled with WHMCS — no extra install needed. If your environment lacks the SDK, run `composer install` inside `modules/gateways/haruka_stripe_alipay/`.

## Upgrading from v1.x

- Deploy the new directory layout over the old files. The `mod_harukastripepay_invoices` table and all setting names are unchanged.
- The key fields are now `password` type (stored encrypted): **after upgrading, re-enter the Stripe secret key and the webhook signing secret once and save**.
- Stripe requests that v1.x made while rendering the invoice page now happen only after the customer clicks the pay button. The payment flow and the callback URL are unchanged.

## Debugging

- **Billing → Gateway Log**: transaction log entries for webhook crediting, failed payments, and refunds made from the Stripe Dashboard.
- **Configuration → System Logs → Module Log** (with Debug Logging enabled): a summary of every Stripe API call, with secrets automatically redacted.

## Known limitations

- Refunds initiated from the Stripe Dashboard are only written to the Gateway Log; they are not applied to WHMCS transactions automatically (refunds initiated from WHMCS are recorded normally).
- Alipay does not support disputes or manual capture.

## License

[MIT](LICENSE)
