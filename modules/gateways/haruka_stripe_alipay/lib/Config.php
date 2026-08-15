<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * Typed view over the raw gateway parameter array WHMCS passes around.
 * All reads of module settings go through this class so that defaults,
 * normalisation and validation live in exactly one place.
 *
 * @license MIT
 */

namespace HarukaStripeAlipay;

class Config
{
    const MODULE_NAME = 'haruka_stripe_alipay';

    /** Exchange-rate sources the module accepts (also the dropdown options). */
    const EXCHANGE_SOURCES = [
        'neutrino' => '默认源 (Neutrino)',
        'wise' => 'Wise 源',
        'visa' => 'Visa 源',
        'unionpay' => '银联源',
        'coinbase' => 'Coinbase 源',
    ];

    /** @var array Raw gateway params from WHMCS */
    private $params;

    public function __construct(array $params)
    {
        $this->params = $params;
    }

    /**
     * @return string Stripe secret key (sk_live_... / sk_test_...)
     */
    public function secretKey()
    {
        return trim((string) ($this->params['StripeSkLive'] ?? ''));
    }

    /**
     * @return string Webhook endpoint signing secret (whsec_...)
     */
    public function webhookSecret()
    {
        return trim((string) ($this->params['StripeWebhookKey'] ?? ''));
    }

    /**
     * @return string Uppercase ISO code of the currency charged on Stripe
     */
    public function stripeCurrency()
    {
        return strtoupper(trim((string) ($this->params['StripeCurrency'] ?? '')));
    }

    /**
     * @return string One of the EXCHANGE_SOURCES keys
     */
    public function exchangeSource()
    {
        $source = strtolower(trim((string) ($this->params['ExchangeType'] ?? '')));

        return array_key_exists($source, self::EXCHANGE_SOURCES) ? $source : 'neutrino';
    }

    /**
     * Statement descriptor for the charge, overriding the account default.
     *
     * Alipay is a non-card payment method, so the full statement_descriptor
     * field applies (the card-only statement_descriptor_suffix is ignored,
     * and cards would reject the full field). The configured template may
     * contain {invoice}, replaced with the invoice id. The result is
     * sanitised to Stripe's rules: printable ASCII without < > \ ' " *,
     * at most 22 characters, and at least one letter — otherwise ''.
     *
     * @see https://docs.stripe.com/get-started/account/statement-descriptors
     *
     * @param int|string $invoiceId
     * @return string Sanitised descriptor, or '' to use the account default
     */
    public function statementDescriptor($invoiceId)
    {
        $template = trim((string) ($this->params['StatementDescriptor'] ?? ''));
        if ($template === '') {
            return '';
        }

        $descriptor = str_replace('{invoice}', (string) $invoiceId, $template);
        $descriptor = preg_replace('/[^\x20-\x7E]/', '', $descriptor);
        $descriptor = str_replace(['<', '>', '\\', "'", '"', '*'], '', $descriptor);
        $descriptor = trim(substr(trim($descriptor), 0, 22));

        return preg_match('/[A-Za-z]/', $descriptor) ? $descriptor : '';
    }

    /**
     * @return float Fixed fee deducted from refunds, in invoice currency
     */
    public function refundFixedFee()
    {
        return max(0.0, (float) ($this->params['RefundFixed'] ?? 0));
    }

    /**
     * @return float Percentage fee deducted from refunds
     */
    public function refundPercentFee()
    {
        return max(0.0, (float) ($this->params['RefundPercent'] ?? 0));
    }

    /**
     * @return string WHMCS base URL with a single trailing slash
     */
    public function systemUrl()
    {
        return rtrim((string) ($this->params['systemurl'] ?? ''), '/') . '/';
    }

    /**
     * @return string Gateway display name as configured in WHMCS
     */
    public function displayName()
    {
        return (string) ($this->params['name'] ?? 'Haruka Stripe Alipay');
    }

    /**
     * @return array Raw parameter array (for WHMCS helpers that expect it)
     */
    public function raw()
    {
        return $this->params;
    }

    /**
     * Assert the module is configured well enough to take a payment.
     *
     * @throws GatewayException
     */
    public function assertUsable()
    {
        if ($this->secretKey() === '') {
            throw new GatewayException('Stripe secret key is not configured.', 'error_not_configured');
        }
        if (!Currency::isAlipaySupported($this->stripeCurrency())) {
            throw new GatewayException(
                sprintf('Currency "%s" is not supported by Alipay on Stripe.', $this->stripeCurrency()),
                'error_not_configured'
            );
        }
    }

    /**
     * HMAC used to sign the invoice details posted from the invoice page to
     * pay.php, so the amount cannot be tampered with in transit.
     *
     * @param string $invoiceId
     * @param string $amount
     * @param string $currency
     * @return string
     */
    public function signPayload($invoiceId, $amount, $currency)
    {
        $data = implode('|', [$invoiceId, $amount, strtoupper($currency)]);

        return hash_hmac('sha256', $data, 'hsa-link-v1:' . $this->secretKey());
    }
}
