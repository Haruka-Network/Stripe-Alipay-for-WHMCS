<?php
/**
 * Haruka Stripe Alipay for WHMCS
 *
 * All database access for the module lives here:
 *  - mod_harukastripepay_invoices : invoice id <-> PaymentIntent id mapping
 *    (name and layout kept from v1.x so existing installs upgrade in place)
 *  - mod_harukastripepay_cache    : small key/value cache with expiry,
 *    used for exchange-rate tables
 *
 * @license MIT
 */

namespace HarukaStripeAlipay;

use WHMCS\Database\Capsule;

class Storage
{
    const INVOICES_TABLE = 'mod_harukastripepay_invoices';
    const CACHE_TABLE = 'mod_harukastripepay_cache';

    /** @var bool Schema is verified at most once per request */
    private static $schemaReady = false;

    /**
     * Create the module tables when missing. Cheap after the first call.
     */
    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }
        self::$schemaReady = true;

        $schema = Capsule::schema();

        if (!$schema->hasTable(self::INVOICES_TABLE)) {
            $schema->create(self::INVOICES_TABLE, function ($table) {
                $table->increments('id');
                $table->integer('invoiceId')->unique();
                $table->string('transId')->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable(self::CACHE_TABLE)) {
            $schema->create(self::CACHE_TABLE, function ($table) {
                $table->increments('id');
                $table->string('cacheKey', 100)->unique();
                $table->mediumText('cacheValue');
                $table->dateTime('expiresAt');
            });
        }
    }

    /**
     * @param int $invoiceId
     * @return string|null PaymentIntent id previously stored for this invoice
     */
    public static function findIntentId($invoiceId)
    {
        self::ensureSchema();

        $row = Capsule::table(self::INVOICES_TABLE)
            ->where('invoiceId', (int) $invoiceId)
            ->first();

        return $row && $row->transId ? (string) $row->transId : null;
    }

    /**
     * Store (or replace) the PaymentIntent handling an invoice.
     *
     * @param int    $invoiceId
     * @param string $intentId
     */
    public static function saveIntentId($invoiceId, $intentId)
    {
        self::ensureSchema();

        $now = date('Y-m-d H:i:s');
        $updated = Capsule::table(self::INVOICES_TABLE)
            ->where('invoiceId', (int) $invoiceId)
            ->update(['transId' => $intentId, 'updated_at' => $now]);

        if ($updated === 0) {
            Capsule::table(self::INVOICES_TABLE)->insert([
                'invoiceId' => (int) $invoiceId,
                'transId' => $intentId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param string $key
     * @param bool   $allowStale Return an expired value instead of null
     * @return string|null
     */
    public static function cacheGet($key, $allowStale = false)
    {
        self::ensureSchema();

        $row = Capsule::table(self::CACHE_TABLE)->where('cacheKey', $key)->first();
        if (!$row) {
            return null;
        }
        if (!$allowStale && $row->expiresAt < date('Y-m-d H:i:s')) {
            return null;
        }

        return (string) $row->cacheValue;
    }

    /**
     * @param string $key
     * @param string $value
     * @param int    $ttlSeconds
     */
    public static function cachePut($key, $value, $ttlSeconds)
    {
        self::ensureSchema();

        $expiresAt = date('Y-m-d H:i:s', time() + (int) $ttlSeconds);
        $updated = Capsule::table(self::CACHE_TABLE)
            ->where('cacheKey', $key)
            ->update(['cacheValue' => $value, 'expiresAt' => $expiresAt]);

        if ($updated === 0) {
            Capsule::table(self::CACHE_TABLE)->insert([
                'cacheKey' => $key,
                'cacheValue' => $value,
                'expiresAt' => $expiresAt,
            ]);
        }
    }
}
