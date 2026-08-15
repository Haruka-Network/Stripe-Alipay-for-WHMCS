<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * PaymentIntent lifecycle and refunds, following the documented Alipay
 * "direct API" server-side flow:
 *
 *   create PaymentIntent (payment_method_types: [alipay])
 *     -> confirm with payment_method_data + return_url
 *     -> status requires_action, next_action.type = alipay_handle_redirect
 *     -> redirect the customer to next_action.alipay_handle_redirect.url
 *     -> payment_intent.succeeded webhook credits the invoice
 *
 * @see https://docs.stripe.com/payments/alipay/accept-a-payment?payment-ui=direct-api
 * @license MIT
 */

namespace HarukaStripeAlipay;

class StripeGateway
{
    /** Metadata keys stored on every PaymentIntent this module creates. */
    const META_INVOICE_ID = 'invoice_id';
    const META_ORIGINAL_AMOUNT = 'original_amount';
    const META_WHMCS_CURRENCY = 'whmcs_currency';
    const META_EXCHANGE_RATE = 'exchange_rate';

    /** @var Config */
    private $config;

    /** @var \Stripe\StripeClient|null */
    private $client;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return \Stripe\StripeClient
     * @throws GatewayException
     */
    private function client()
    {
        if ($this->client === null) {
            Autoloader::ensureStripeSdk();
            $this->client = new \Stripe\StripeClient($this->config->secretKey());
        }

        return $this->client;
    }

    /**
     * Resolve a click on the Pay button into the next step for the customer.
     *
     * @param int    $invoiceId
     * @param string $whmcsAmount   Amount due in the invoice currency ("xxx.xx")
     * @param string $whmcsCurrency Invoice currency code
     * @return array{action:string,url?:string,message_key?:string}
     *         action=redirect + url, or action=notice + message_key
     * @throws GatewayException
     */
    public function preparePayment($invoiceId, $whmcsAmount, $whmcsCurrency)
    {
        $this->config->assertUsable();

        $stripeCurrency = $this->config->stripeCurrency();
        $rate = ExchangeRates::rate($whmcsCurrency, $stripeCurrency, $this->config->exchangeSource());
        $minorAmount = Currency::toMinorUnits((float) $whmcsAmount * $rate, $stripeCurrency);
        Currency::assertChargeable($stripeCurrency, $minorAmount);

        $intent = $this->loadReusableIntent($invoiceId);

        if ($intent !== null && in_array($intent->status, ['succeeded', 'processing'], true)) {
            return ['action' => 'notice', 'message_key' => 'payment_processing'];
        }

        $amountChanged = $intent !== null && (
            (int) $intent->amount !== $minorAmount
            || (string) ($intent->metadata[self::META_ORIGINAL_AMOUNT] ?? '') !== (string) $whmcsAmount
        );

        if ($intent !== null && $intent->status === 'requires_action') {
            if (!$amountChanged) {
                // The pending redirect is still valid; send the customer back to it.
                $url = $this->redirectUrlFrom($intent);
                if ($url !== null) {
                    return ['action' => 'redirect', 'url' => $url];
                }
            }
            // Amount changed (or the action is unusable): a confirmed intent
            // cannot be updated, so cancel and start over.
            $this->request('PaymentIntent Cancel', function () use ($intent) {
                return $this->client()->paymentIntents->cancel($intent->id, []);
            });
            $intent = null;
        }

        $replacedIntentId = $intent !== null ? null : Storage::findIntentId($invoiceId);

        if ($intent === null) {
            $intent = $this->createIntent(
                $invoiceId,
                $whmcsAmount,
                $whmcsCurrency,
                $stripeCurrency,
                $minorAmount,
                $rate,
                $replacedIntentId
            );
            Storage::saveIntentId($invoiceId, $intent->id);
        } elseif ($amountChanged) {
            $intent = $this->request('PaymentIntent Update', function () use ($intent, $minorAmount, $whmcsAmount, $rate) {
                return $this->client()->paymentIntents->update($intent->id, [
                    'amount' => $minorAmount,
                    'metadata' => [
                        self::META_ORIGINAL_AMOUNT => (string) $whmcsAmount,
                        self::META_EXCHANGE_RATE => (string) $rate,
                    ],
                ]);
            });
        }

        if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation'], true)) {
            $intent = $this->confirmIntent($intent, $invoiceId);
        }

        if ($intent->status === 'requires_action') {
            $url = $this->redirectUrlFrom($intent);
            if ($url !== null) {
                return ['action' => 'redirect', 'url' => $url];
            }
        }

        if (in_array($intent->status, ['succeeded', 'processing'], true)) {
            return ['action' => 'notice', 'message_key' => 'payment_processing'];
        }

        throw new GatewayException(
            sprintf('Unexpected PaymentIntent status "%s" for invoice %d.', $intent->status, $invoiceId)
        );
    }

    /**
     * Process a refund initiated from the WHMCS admin area.
     *
     * @param array $params WHMCS refund parameters
     * @return array WHMCS refund response (status/rawdata/transid/fees)
     */
    public function refund(array $params)
    {
        $transactionId = (string) ($params['transid'] ?? '');

        try {
            $calculator = new RefundCalculator(
                (float) ($params['amount'] ?? 0),
                $this->config->refundFixedFee(),
                $this->config->refundPercentFee()
            );

            try {
                $intent = $this->request('PaymentIntent Retrieve', function () use ($transactionId) {
                    return $this->client()->paymentIntents->retrieve($transactionId, [
                        'expand' => ['latest_charge'],
                    ]);
                });
            } catch (GatewayException $expandError) {
                // Accounts pinned to a pre-2022-11-15 API version cannot expand
                // latest_charge; retry plain and skip the refunded-so-far check.
                $intent = $this->request('PaymentIntent Retrieve', function () use ($transactionId) {
                    return $this->client()->paymentIntents->retrieve($transactionId, []);
                });
            }

            $captured = (int) $intent->amount_received;
            if ($captured <= 0) {
                throw new GatewayException('No captured amount available to refund.');
            }

            $alreadyRefunded = 0;
            if (isset($intent->latest_charge) && is_object($intent->latest_charge)) {
                $alreadyRefunded = (int) $intent->latest_charge->amount_refunded;
            }
            $remaining = $captured - $alreadyRefunded;
            if ($remaining <= 0) {
                throw new GatewayException('The charge is already fully refunded on Stripe.');
            }

            $minorAmount = $calculator->stripeMinorAmount(
                $captured,
                (float) ($intent->metadata[self::META_ORIGINAL_AMOUNT] ?? 0)
            );
            // Clamp rounding overshoot; anything left over stays on Stripe.
            $minorAmount = min($minorAmount, $remaining);

            $payload = [
                'payment_intent' => $transactionId,
                'amount' => $minorAmount,
                'metadata' => [
                    self::META_INVOICE_ID => (string) ($params['invoiceid'] ?? ''),
                    'whmcs_refund_amount' => (string) ($params['amount'] ?? ''),
                ],
            ];

            $refund = $this->request('Refund Create', function () use ($payload, $alreadyRefunded) {
                return $this->client()->refunds->create(
                    $payload,
                    [
                        // Full-payload hash: an identical retry dedupes, any
                        // difference gets a fresh key. The refunded-so-far salt
                        // lets a second same-sized refund go through once the
                        // first has settled.
                        'idempotency_key' => 'hsa-refund-' . md5(json_encode($payload) . '|' . $alreadyRefunded),
                    ]
                );
            });

            $succeeded = in_array($refund->status, ['succeeded', 'pending'], true);

            return [
                'status' => $succeeded ? 'success' : 'declined',
                'rawdata' => $refund->toArray(),
                'transid' => $refund->id,
                'fees' => $calculator->fees(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'rawdata' => $e->getMessage(),
                'transid' => $transactionId,
                'fees' => 0,
            ];
        }
    }

    /**
     * Fetch the intent mapped to the invoice, or null when there is none or
     * it no longer exists / was canceled on Stripe.
     *
     * @param int $invoiceId
     * @return \Stripe\PaymentIntent|null
     */
    private function loadReusableIntent($invoiceId)
    {
        $intentId = Storage::findIntentId($invoiceId);
        if ($intentId === null) {
            return null;
        }

        try {
            $intent = $this->client()->paymentIntents->retrieve($intentId, []);
            Logger::module($this->config, 'PaymentIntent Retrieve', $intentId, $this->summarise($intent));
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Unknown id (mode switch, purged test data): fall through to create.
            Logger::module($this->config, 'PaymentIntent Retrieve', $intentId, 'MISSING: ' . $e->getMessage());
            return null;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::module($this->config, 'PaymentIntent Retrieve', $intentId, 'ERROR: ' . $e->getMessage());
            throw new GatewayException('PaymentIntent Retrieve failed: ' . $e->getMessage());
        }

        return $intent->status === 'canceled' ? null : $intent;
    }

    /**
     * @param int         $invoiceId
     * @param string      $whmcsAmount
     * @param string      $whmcsCurrency
     * @param string      $stripeCurrency
     * @param int         $minorAmount
     * @param float       $rate
     * @param string|null $replacedIntentId Old intent this one supersedes
     * @return \Stripe\PaymentIntent
     */
    private function createIntent(
        $invoiceId,
        $whmcsAmount,
        $whmcsCurrency,
        $stripeCurrency,
        $minorAmount,
        $rate,
        $replacedIntentId
    ) {
        $payload = [
            'amount' => $minorAmount,
            'currency' => strtolower($stripeCurrency),
            'payment_method_types' => ['alipay'],
            'description' => 'Invoice #' . $invoiceId,
            'metadata' => [
                self::META_INVOICE_ID => (string) $invoiceId,
                self::META_ORIGINAL_AMOUNT => (string) $whmcsAmount,
                self::META_WHMCS_CURRENCY => $whmcsCurrency,
                self::META_EXCHANGE_RATE => (string) $rate,
            ],
        ];

        $descriptor = $this->config->statementDescriptor($invoiceId);
        if ($descriptor !== '') {
            $payload['statement_descriptor'] = $descriptor;
        }

        // Derived from the full request body: an identical double-click replays
        // the same intent, while ANY payload difference (amount, rate metadata,
        // descriptor) gets a fresh key instead of Stripe's idempotency_error.
        // The replaced-intent salt additionally escapes replay when superseding
        // a dead intent with otherwise identical parameters.
        $idempotencyKey = 'hsa-pi-' . md5(json_encode($payload) . '|' . (string) $replacedIntentId);

        return $this->request('PaymentIntent Create', function () use ($payload, $idempotencyKey) {
            return $this->client()->paymentIntents->create(
                $payload,
                ['idempotency_key' => $idempotencyKey]
            );
        });
    }

    /**
     * Confirm the intent with an Alipay payment method and our return URL.
     *
     * @param \Stripe\PaymentIntent $intent
     * @param int                   $invoiceId
     * @return \Stripe\PaymentIntent
     */
    private function confirmIntent($intent, $invoiceId)
    {
        $payload = [
            'return_url' => $this->config->systemUrl() . 'viewinvoice.php?id=' . (int) $invoiceId,
        ];
        if (empty($intent->payment_method)) {
            $payload['payment_method_data'] = ['type' => 'alipay'];
        }

        return $this->request('PaymentIntent Confirm', function () use ($intent, $payload) {
            return $this->client()->paymentIntents->confirm($intent->id, $payload);
        });
    }

    /**
     * @param \Stripe\PaymentIntent $intent
     * @return string|null Alipay redirect URL when present
     */
    private function redirectUrlFrom($intent)
    {
        if (isset($intent->next_action->alipay_handle_redirect->url)) {
            $url = (string) $intent->next_action->alipay_handle_redirect->url;
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * Run a Stripe API call with module-log instrumentation.
     *
     * @param string   $action
     * @param callable $call
     * @return mixed
     * @throws GatewayException on Stripe API errors
     */
    private function request($action, callable $call)
    {
        try {
            $result = $call();
            Logger::module($this->config, $action, '', $this->summarise($result));

            return $result;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::module($this->config, $action, '', 'ERROR: ' . $e->getMessage());
            throw new GatewayException($action . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Compact representation of an API resource for the module log.
     *
     * @param mixed $resource
     * @return mixed
     */
    private function summarise($resource)
    {
        if (is_object($resource) && method_exists($resource, 'toArray')) {
            $data = $resource->toArray();
            // The full object is large; keep the useful fields.
            $keys = ['id', 'object', 'status', 'amount', 'currency', 'amount_received', 'metadata', 'next_action'];
            $summary = array_intersect_key($data, array_flip($keys));

            return $summary ?: $data;
        }

        return $resource;
    }
}
