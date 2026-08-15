<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * Webhook endpoint logic. Follows the Stripe webhook guidance (verify the
 * signature against the raw body with the official library, acknowledge
 * fast, tolerate duplicate deliveries) and the WHMCS callback sequence
 * (validate invoice id, reject duplicate transaction ids, log, then apply
 * the payment).
 *
 * @see https://docs.stripe.com/webhooks
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 * @license MIT
 */

namespace HarukaStripeAlipay;

class WebhookController
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Handle one webhook delivery. Emits the HTTP response (status + body)
     * and returns nothing; designed to be the last call in the endpoint.
     *
     * @param string $payload   Raw request body
     * @param string $signature Stripe-Signature header value
     */
    public function handle($payload, $signature)
    {
        if ($this->config->webhookSecret() === '') {
            $this->respond(500, 'Webhook secret is not configured');
            return;
        }

        try {
            Autoloader::ensureStripeSdk();
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $this->config->webhookSecret());
        } catch (\UnexpectedValueException $e) {
            $this->respond(400, 'Invalid payload');
            return;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->respond(400, 'Invalid signature');
            return;
        } catch (\Exception $e) {
            Logger::transaction($this->config, $e->getMessage(), 'Webhook Error');
            $this->respond(500, 'Webhook processing error');
            return;
        }

        try {
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $this->onPaymentSucceeded($event);
                    break;

                case 'payment_intent.payment_failed':
                    Logger::transaction($this->config, $this->eventSummary($event), 'Payment Failed');
                    $this->respond(200, 'Failure logged');
                    break;

                case 'charge.refunded':
                    // Refunds made from the Stripe Dashboard are logged for
                    // visibility; WHMCS-initiated refunds are already recorded
                    // by the refund function.
                    Logger::transaction($this->config, $this->eventSummary($event), 'Refund Notice');
                    $this->respond(200, 'Refund logged');
                    break;

                default:
                    $this->respond(200, 'Ignored event type: ' . $event->type);
            }
        } catch (\Exception $e) {
            Logger::transaction($this->config, $e->getMessage(), 'Webhook Error');
            // Non-2xx so Stripe retries transient failures.
            $this->respond(500, 'Webhook processing error');
        }
    }

    /**
     * Credit the invoice for a succeeded PaymentIntent.
     *
     * @param \Stripe\Event $event
     */
    private function onPaymentSucceeded($event)
    {
        $intent = $event->data->object;

        if ($intent->status !== 'succeeded') {
            $this->respond(200, 'Intent not in succeeded state');
            return;
        }

        $invoiceId = (int) ($intent->metadata[StripeGateway::META_INVOICE_ID] ?? 0);
        if ($invoiceId <= 0) {
            // Not created by this module (e.g. another integration on the
            // same account): acknowledge so Stripe stops retrying.
            $this->respond(200, 'No invoice_id metadata; ignored');
            return;
        }

        // Amount credited to the invoice, in the invoice's own currency.
        $originalAmount = (string) ($intent->metadata[StripeGateway::META_ORIGINAL_AMOUNT] ?? '');
        if ($originalAmount === '' || !is_numeric($originalAmount)) {
            // Fall back to "full balance" semantics of addInvoicePayment.
            $originalAmount = '';
        }

        // Dies with output on an invalid invoice id or duplicate transaction,
        // which acknowledges the event with a 200.
        $invoiceId = checkCbInvoiceID($invoiceId, $this->config->displayName());
        checkCbTransID($intent->id);

        Logger::transaction($this->config, $this->eventSummary($event), 'Successful');

        addInvoicePayment(
            $invoiceId,
            $intent->id,
            $originalAmount,
            0,
            Config::MODULE_NAME
        );

        $this->respond(200, 'Payment recorded for invoice ' . $invoiceId);
    }

    /**
     * Compact event representation for the gateway log.
     *
     * @param \Stripe\Event $event
     * @return array
     */
    private function eventSummary($event)
    {
        $object = $event->data->object;

        return [
            'event_id' => $event->id,
            'event_type' => $event->type,
            'object_id' => isset($object->id) ? $object->id : null,
            'status' => isset($object->status) ? $object->status : null,
            'amount' => isset($object->amount) ? $object->amount : null,
            'amount_received' => isset($object->amount_received) ? $object->amount_received : null,
            'currency' => isset($object->currency) ? $object->currency : null,
            'metadata' => isset($object->metadata) ? $object->metadata->toArray() : [],
            'last_payment_error' => isset($object->last_payment_error->message)
                ? $object->last_payment_error->message
                : null,
        ];
    }

    /**
     * @param int    $status
     * @param string $body
     */
    private function respond($status, $body)
    {
        http_response_code($status);
        echo $body;
    }
}
