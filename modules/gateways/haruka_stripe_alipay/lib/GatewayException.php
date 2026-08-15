<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * Exception whose message is safe to show to an end customer after
 * localisation. Internal/unexpected errors should use plain \Exception
 * so their details never leak into client-facing output.
 *
 * @license MIT
 */

namespace HarukaStripeAlipay;

class GatewayException extends \Exception
{
    /** @var string Lang key describing the failure for client display */
    private $langKey;

    /** @var array Values interpolated into the localised message */
    private $context;

    public function __construct($message, $langKey = 'error_gateway', array $context = [])
    {
        parent::__construct($message);
        $this->langKey = $langKey;
        $this->context = $context;
    }

    /**
     * @return string
     */
    public function getLangKey()
    {
        return $this->langKey;
    }

    /**
     * @return array
     */
    public function getContext()
    {
        return $this->context;
    }
}
