<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * Currency arithmetic against the Stripe API conventions:
 * amounts are integers in the currency's smallest unit, and zero-decimal
 * currencies (JPY, KRW, ...) must NOT be multiplied by 100.
 *
 * @see https://docs.stripe.com/currencies  (amount format, minimums, maximums)
 * @see https://docs.stripe.com/payments/alipay  (supported presentment currencies)
 * @license MIT
 */

namespace HarukaStripeAlipay;

class Currency
{
    /**
     * Currencies Alipay payments can be denominated in on Stripe.
     * The exact subset depends on the account's country; this is the
     * superset from the Alipay payment-method documentation.
     */
    const ALIPAY_CURRENCIES = [
        'AUD', 'CAD', 'CNY', 'EUR', 'GBP', 'HKD', 'JPY', 'MYR', 'NZD', 'SGD', 'USD',
    ];

    /**
     * Zero-decimal currencies per the Stripe API (charge 500 JPY as amount=500).
     * ISK and UGX are intentionally absent: Stripe still expects them as
     * two-decimal values for backwards compatibility.
     */
    const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Minimum charge amounts in smallest currency unit.
     * Source: https://docs.stripe.com/currencies#minimum-and-maximum-charge-amounts
     */
    const MINIMUM_AMOUNTS = [
        'USD' => 50, 'AED' => 200, 'ARS' => 50, 'AUD' => 50, 'BGN' => 100,
        'BRL' => 50, 'CAD' => 50, 'CHF' => 50, 'COP' => 50, 'CZK' => 1500,
        'DKK' => 250, 'EUR' => 50, 'GBP' => 30, 'HKD' => 400, 'HUF' => 17500,
        'IDR' => 50, 'ILS' => 50, 'INR' => 50, 'JPY' => 50, 'KRW' => 50,
        'MXN' => 1000, 'MYR' => 200, 'NOK' => 300, 'NZD' => 50, 'PHP' => 50,
        'PLN' => 200, 'RON' => 200, 'RUB' => 50, 'SEK' => 300, 'SGD' => 50,
        'THB' => 1000, 'ZAR' => 50,
        // Alipay itself has no published minimum for CNY; use 1 fen > 0.
        'CNY' => 1,
    ];

    /**
     * Maximum amount for non-card payment methods: 8 digits in smallest unit.
     */
    const MAXIMUM_AMOUNT = 99999999;

    /**
     * @param string $currency
     * @return bool
     */
    public static function isAlipaySupported($currency)
    {
        return in_array(strtoupper($currency), self::ALIPAY_CURRENCIES, true);
    }

    /**
     * @param string $currency
     * @return bool
     */
    public static function isZeroDecimal($currency)
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true);
    }

    /**
     * Convert a decimal amount to the integer smallest-unit amount Stripe expects.
     * Uses floor with a small epsilon so binary float noise (e.g. 19.999999997)
     * does not shave a unit off, while never rounding the customer upwards.
     *
     * @param float  $amount
     * @param string $currency
     * @return int
     */
    public static function toMinorUnits($amount, $currency)
    {
        $multiplier = self::isZeroDecimal($currency) ? 1 : 100;

        return (int) floor((float) $amount * $multiplier + 1e-6);
    }

    /**
     * Format a smallest-unit amount back into a human readable decimal string.
     *
     * @param int    $minorAmount
     * @param string $currency
     * @return string
     */
    public static function formatMinorUnits($minorAmount, $currency)
    {
        if (self::isZeroDecimal($currency)) {
            return number_format((int) $minorAmount, 0, '.', '');
        }

        return number_format($minorAmount / 100, 2, '.', '');
    }

    /**
     * Validate a smallest-unit charge amount against Stripe's limits.
     *
     * @param string $currency
     * @param int    $minorAmount
     * @throws GatewayException when the amount is outside the allowed range
     */
    public static function assertChargeable($currency, $minorAmount)
    {
        $currency = strtoupper($currency);

        if (!isset(self::MINIMUM_AMOUNTS[$currency])) {
            throw new GatewayException(
                sprintf('Unsupported charge currency "%s".', $currency),
                'error_unsupported_currency',
                ['currency' => $currency]
            );
        }

        $minimum = self::MINIMUM_AMOUNTS[$currency];
        if ($minorAmount < $minimum) {
            throw new GatewayException(
                sprintf(
                    'Amount %s %s is below the Stripe minimum of %s %s.',
                    self::formatMinorUnits($minorAmount, $currency),
                    $currency,
                    self::formatMinorUnits($minimum, $currency),
                    $currency
                ),
                'error_amount_too_small',
                [
                    'currency' => $currency,
                    'minimum' => self::formatMinorUnits($minimum, $currency),
                    'amount' => self::formatMinorUnits($minorAmount, $currency),
                ]
            );
        }

        if ($minorAmount > self::MAXIMUM_AMOUNT) {
            throw new GatewayException(
                sprintf('Amount %d exceeds the Stripe maximum of %d.', $minorAmount, self::MAXIMUM_AMOUNT),
                'error_amount_too_large',
                [
                    'currency' => $currency,
                    'maximum' => self::formatMinorUnits(self::MAXIMUM_AMOUNT, $currency),
                ]
            );
        }
    }
}
