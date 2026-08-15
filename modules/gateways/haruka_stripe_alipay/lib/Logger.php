<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * Thin wrapper over the WHMCS logging helpers so calls are safe in any
 * context and credentials are always redacted.
 *
 * Module log entries appear under Configuration > System Logs > Module Log
 * once debug logging is enabled there.
 *
 * @license MIT
 */

namespace HarukaStripeAlipay;

class Logger
{
    /**
     * @param Config $config
     * @param string $action   Short action name, e.g. "PaymentIntent Create"
     * @param mixed  $request
     * @param mixed  $response
     */
    public static function module(Config $config, $action, $request, $response)
    {
        if (!function_exists('logModuleCall')) {
            return;
        }

        $redact = array_values(array_filter([
            $config->secretKey(),
            $config->webhookSecret(),
        ]));

        logModuleCall(
            Config::MODULE_NAME,
            $action,
            $request,
            $response,
            '',
            $redact
        );
    }

    /**
     * Gateway transaction log (Billing > Gateway Log).
     *
     * @param Config $config
     * @param mixed  $data
     * @param string $status e.g. "Successful", "Failure"
     */
    public static function transaction(Config $config, $data, $status)
    {
        if (!function_exists('logTransaction')) {
            return;
        }

        logTransaction($config->displayName(), $data, $status);
    }
}
