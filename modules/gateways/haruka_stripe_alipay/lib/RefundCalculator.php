<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * Refund maths. Business rule carried over from v1.x: a fixed fee and a
 * percentage fee are withheld from the amount the admin asks to refund,
 * and only the remainder is refunded on Stripe.
 *
 * The Stripe amount is mapped proportionally: the intent's metadata records
 * the invoice-currency amount the charge was made for, so
 *   stripe_refund = net / invoice_amount * amount_received
 * works for both full and partial refunds. (v1.x divided by the *requested*
 * amount, which over-refunded partial refunds.)
 *
 * @license MIT
 */

namespace HarukaStripeAlipay;

class RefundCalculator
{
    /** @var float Amount requested in WHMCS, in the invoice currency */
    private $requestedAmount;

    /** @var float Net amount to give back, in the invoice currency */
    private $netAmount;

    /** @var float Fee withheld, in the invoice currency */
    private $fees;

    /**
     * @param float $requestedAmount Refund amount entered in WHMCS admin
     * @param float $fixedFee        Config::refundFixedFee()
     * @param float $percentFee      Config::refundPercentFee()
     * @throws GatewayException when fees leave nothing to refund
     */
    public function __construct($requestedAmount, $fixedFee, $percentFee)
    {
        $this->requestedAmount = (float) $requestedAmount;
        $this->netAmount = ($this->requestedAmount - $fixedFee) / ($percentFee / 100 + 1);
        $this->fees = $this->requestedAmount - $this->netAmount;

        if ($this->requestedAmount <= 0 || $this->netAmount <= 0) {
            throw new GatewayException(
                sprintf(
                    'Nothing left to refund: requested %.2f minus fees leaves %.2f.',
                    $this->requestedAmount,
                    $this->netAmount
                )
            );
        }
    }

    /**
     * @return float Fee withheld in the invoice currency (reported to WHMCS)
     */
    public function fees()
    {
        return round($this->fees, 2);
    }

    /**
     * @param int   $capturedMinorAmount  PaymentIntent amount_received
     * @param float $invoicePaidAmount    Invoice-currency amount the charge
     *                                    covered (metadata.original_amount)
     * @return int Amount to refund on Stripe, in smallest currency unit
     * @throws GatewayException
     */
    public function stripeMinorAmount($capturedMinorAmount, $invoicePaidAmount)
    {
        $invoicePaidAmount = (float) $invoicePaidAmount;
        if ($invoicePaidAmount <= 0) {
            // Old intents always carry original_amount; treat a missing value
            // as a full refund request to preserve v1.x behaviour.
            $invoicePaidAmount = $this->requestedAmount;
        }

        $minor = (int) round($this->netAmount / $invoicePaidAmount * $capturedMinorAmount);

        if ($minor <= 0) {
            throw new GatewayException('Computed Stripe refund amount is zero.');
        }

        return $minor;
    }
}
