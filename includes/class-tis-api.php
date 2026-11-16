<?php
/**
 * DEPRECATED since 0.3.0 — kept as a thin back-compat shim.
 *
 * The Shiprocket serviceability client moved into TS_Provider_Shiprocket. This
 * class remains so that any site-level or third-party code calling TIS_API::*
 * keeps working; it holds no logic of its own and forwards to that provider.
 *
 * Note the signature change this shim absorbs: providers take a destination
 * ARRAY (['country' => ..., 'postcode' => ...]) rather than a bare country
 * string, because iCarry rates domestic lanes at pincode level. The old scalar
 * signature is preserved here and widened on the way through.
 *
 * New code should use TS_Provider_Registry::active().
 *
 * @package Threadly_Intl_Shipping
 * @deprecated 0.3.0
 */

if (!defined('ABSPATH')) exit;

class TIS_API {

    const CACHE_PREFIX = 'tis_rates_';
    const CIRCUIT_KEY  = 'tis_circuit_open';

    /** @return TS_Provider_Shiprocket|null */
    protected static function provider() {
        return TS_Provider_Registry::get('shiprocket');
    }

    /** Widen a legacy country string into a destination array. */
    protected static function destination($country) {
        return ['country' => (string) $country, 'postcode' => ''];
    }

    public static function pickup_pincode() {
        $p = self::provider();
        return $p ? $p->pickup_pincode() : '';
    }

    public static function country_value($iso2) {
        $p = self::provider();
        return $p ? $p->country_value($iso2) : $iso2;
    }

    public static function cache_key($country, $weight, $cod) {
        $p = self::provider();
        return $p ? $p->cache_key(self::destination($country), $weight, ['cod' => (bool) $cod]) : '';
    }

    public static function get_cached($country, $weight, $cod = 0) {
        $p = self::provider();
        return $p ? $p->get_cached(self::destination($country), $weight, ['cod' => (bool) $cod]) : false;
    }

    public static function set_cached($country, $weight, $cod, $rates, $ttl = null) {
        $p = self::provider();
        if ($p) {
            $p->set_cached(self::destination($country), $weight, ['cod' => (bool) $cod], $rates, $ttl);
        }
    }

    public static function frontend_token() {
        $p = self::provider();
        return $p ? $p->cached_token() : false;
    }

    public static function background_token() {
        $p = self::provider();
        return $p ? $p->token() : ['error' => 'Shiprocket provider is not registered.'];
    }

    public static function circuit_is_open() {
        $p = self::provider();
        return $p ? $p->circuit_is_open() : false;
    }

    public static function reset_circuit() {
        $p = self::provider();
        if ($p) {
            $p->reset_circuit();
        }
    }

    public static function get_rates($country, $weight, $cod = 0, $allow_live = true) {
        $p = self::provider();
        return $p ? $p->rates(self::destination($country), $weight, [
            'cod'        => (bool) $cod,
            'allow_live' => (bool) $allow_live,
        ]) : [];
    }

    public static function request($country, $weight, $cod, $token, $timeout = 20) {
        $p = self::provider();
        return $p ? $p->request(self::destination($country), $weight, ['cod' => (bool) $cod], $token, $timeout)
                  : ['error' => 'Shiprocket provider is not registered.', 'rates' => []];
    }

    public static function parse_rates($body) {
        $p = self::provider();
        return $p ? $p->parse_rates($body) : [];
    }
}
