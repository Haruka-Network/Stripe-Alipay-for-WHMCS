<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * PSR-4 style autoloader for the module's own classes, plus a helper to
 * bootstrap the Stripe PHP SDK.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 * @license MIT
 */

namespace HarukaStripeAlipay;

class Autoloader
{
    /** @var bool */
    private static $registered = false;

    /**
     * Register the class autoloader. Safe to call multiple times.
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        spl_autoload_register(function ($class) {
            $prefix = __NAMESPACE__ . '\\';
            if (strpos($class, $prefix) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    /**
     * Make sure the Stripe PHP SDK is available.
     *
     * Resolution order:
     *  1. Already loaded (WHMCS bundles stripe/stripe-php for its own Stripe gateway).
     *  2. A module-local Composer install at haruka_stripe_alipay/vendor/.
     *
     * @throws GatewayException when the SDK cannot be located
     */
    public static function ensureStripeSdk()
    {
        if (class_exists('\\Stripe\\StripeClient')) {
            return;
        }

        $vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($vendorAutoload)) {
            require_once $vendorAutoload;
        }

        if (!class_exists('\\Stripe\\StripeClient')) {
            throw new GatewayException(
                'Stripe PHP SDK not found. Run "composer install" inside '
                . 'modules/gateways/haruka_stripe_alipay/ or upgrade WHMCS.'
            );
        }
    }
}
