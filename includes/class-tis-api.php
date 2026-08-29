<?php
/**
 * Shiprocket international serviceability client.
 *
 * This is the ONLY file that knows the shape of Shiprocket's international API.
 * Everything unverified is isolated in the CONTRACT block below so that a single
 * live probe can correct it without touching the rest of the plugin.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

class TIS_API {

    /* ---------------------------------------------------------------------
     * UNVERIFIED CONTRACT
     *
     * The endpoint path is confirmed against a maintained Shiprocket SDK.
     * The parameter names and response envelope below are NOT confirmed against
     * Shiprocket's own documentation — their API docs are not publicly readable.
     *
     * Run Tools > Intl Shipping > "Run live probe" once with real credentials,
     * read the raw JSON it dumps, and correct these four constants plus
     * parse_rates() if they differ. Nothing else in the plugin needs to change.
     * ------------------------------------------------------------------- */
    const ENDPOINT       = 'courier/international/serviceability/';
    const PARAM_PICKUP   = 'pickup_postcode';
    const PARAM_COUNTRY  = 'delivery_country';
    const PARAM_WEIGHT   = 'weight';
    const PARAM_COD      = 'cod';
    /* ------------------------------------------------------------------- */

    const CACHE_PREFIX = 'tis_rates_';
    const CIRCUIT_KEY  = 'tis_circuit_open';

    /**
     * Shiprocket API base. This plugin authenticates independently of HIBOU
     * (see class-tis-auth.php) so it does not borrow HIBOU's constant here.
     */
    protected static function base_url() {
        return TIS_SR_API_BASE;
    }

    /**
     * Configured pickup pincode, falling back to the WooCommerce store postcode.
     */
    public static function pickup_pincode() {
        $pin = trim((string) get_option('tis_pickup_pincode', ''));
        if ('' === $pin) {
            $pin = trim((string) get_option('woocommerce_store_postcode', ''));
        }
        return $pin;
    }

    /**
     * Value to send as the destination country.
     *
     * WooCommerce hands us an ISO-2 code. Shiprocket may want the full country
     * name instead; flip this with the filter once the probe tells you which.
     */
    public static function country_value($iso2) {
        return apply_filters('tis_delivery_country_value', $iso2, $iso2);
    }

    public static function cache_key($country, $weight, $cod) {
        return self::CACHE_PREFIX . md5(self::pickup_pincode() . '|' . $country . '|' . $weight . '|' . $cod);
    }

    /**
     * Read-only cache lookup.
     *
     * @return array|false Normalised rate rows, or false on miss.
     */
    public static function get_cached($country, $weight, $cod = 0) {
        return get_transient(self::cache_key($country, $weight, $cod));
    }

    public static function set_cached($country, $weight, $cod, $rates, $ttl = null) {
        if (null === $ttl) {
            $ttl = max(1, (int) get_option('tis_cache_hours', 26)) * HOUR_IN_SECONDS;
        }
        set_transient(self::cache_key($country, $weight, $cod), $rates, $ttl);
    }

    /**
     * Auth token for the checkout path.
     *
     * Deliberately cache-only — never triggers a login. A blocking HTTP round
     * trip to Shiprocket must never happen while a customer waits at checkout.
     * Cron warms the token via TIS_Auth::token(); checkout only ever reads it.
     *
     * @return string|false
     */
    public static function frontend_token() {
        return TIS_Auth::cached_token();
    }

    /**
     * Auth token for cron/admin context, where a blocking login is acceptable.
     *
     * @return string|array Token string, or array with 'error'.
     */
    public static function background_token() {
        return TIS_Auth::token();
    }

    public static function circuit_is_open() {
        return (bool) get_transient(self::CIRCUIT_KEY);
    }

    protected static function trip_circuit() {
        $minutes = (int) apply_filters('tis_circuit_open_minutes', 10);
        set_transient(self::CIRCUIT_KEY, 1, max(1, $minutes) * MINUTE_IN_SECONDS);
        tis_log('warning', 'Shiprocket rate-fetch circuit breaker opened.', ['open_minutes' => $minutes]);
    }

    public static function reset_circuit() {
        if (self::circuit_is_open()) {
            tis_log('info', 'Shiprocket rate-fetch circuit breaker closed.');
        }
        delete_transient(self::CIRCUIT_KEY);
    }

    /**
     * Main entry point used by the shipping method.
     *
     * Cache first; optionally fall through to a short live call. Never throws,
     * never blocks longer than the configured timeout.
     *
     * @return array Normalised rate rows (possibly empty).
     */
    public static function get_rates($country, $weight, $cod = 0, $allow_live = true) {

        $cached = self::get_cached($country, $weight, $cod);
        if (is_array($cached)) {
            return $cached;
        }

        if (!$allow_live || self::circuit_is_open()) {
            return [];
        }

        $token = self::frontend_token();
        if (!$token) {
            // No warm token. Do not authenticate on the customer's thread.
            return [];
        }

        $timeout = (float) apply_filters('tis_frontend_timeout', 5);
        $result  = self::request($country, $weight, $cod, $token, $timeout);

        if (!empty($result['error'])) {
            self::trip_circuit();
            return [];
        }

        self::set_cached($country, $weight, $cod, $result['rates']);
        return $result['rates'];
    }

    /**
     * Perform the HTTP call. Used by the warmer, the probe, and the live fallback.
     *
     * @return array { rates: array, raw: mixed, error: string, url: string }
     */
    public static function request($country, $weight, $cod, $token, $timeout = 20) {

        $pickup = self::pickup_pincode();
        if ('' === $pickup) {
            $err = 'No pickup pincode configured (and no WooCommerce store postcode set).';
            tis_log('error', $err, ['country' => $country, 'weight' => $weight]);
            self::record_error($country, $weight, $err, null);
            return ['error' => $err, 'rates' => []];
        }

        $params = [
            self::PARAM_PICKUP  => $pickup,
            self::PARAM_COUNTRY => self::country_value($country),
            self::PARAM_WEIGHT  => $weight,
            self::PARAM_COD     => $cod ? 1 : 0,
        ];
        $params = apply_filters('tis_request_params', $params, $country, $weight, $cod);

        $url = self::base_url() . ltrim(self::ENDPOINT, '/') . '?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout'    => $timeout,
            'sslverify'  => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
        ]);

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            tis_log('warning', 'Shiprocket rate request failed (transport error).', ['country' => $country, 'weight' => $weight, 'error' => $err]);
            self::record_error($country, $weight, $err, null);
            return ['error' => $err, 'rates' => [], 'url' => $url];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (401 === $code) {
            TIS_Auth::forget();
            tis_log('warning', 'Shiprocket returned 401 on a rate request; cached token invalidated.', ['country' => $country, 'weight' => $weight]);
        }

        if ($code < 200 || $code >= 300) {
            $msg = is_array($body) && !empty($body['message']) ? $body['message'] : 'HTTP ' . $code;
            tis_log('warning', 'Shiprocket rate request failed.', ['country' => $country, 'weight' => $weight, 'http_code' => $code, 'message' => $msg]);
            self::record_error($country, $weight, $msg, $code);
            return ['error' => $msg, 'rates' => [], 'raw' => $body, 'url' => $url];
        }

        $rates = self::parse_rates($body);

        tis_log('info', 'Shiprocket rate request succeeded.', ['country' => $country, 'weight' => $weight, 'couriers' => count($rates)]);
        self::record_success($country, $weight, count($rates));

        return [
            'rates' => $rates,
            'raw'   => $body,
            'url'   => $url,
        ];
    }

    protected static function record_success($country, $weight, $courier_count) {
        update_option('tis_last_success', [
            'time'    => time(),
            'country' => $country,
            'weight'  => $weight,
            'couriers'=> $courier_count,
        ], false);
    }

    protected static function record_error($country, $weight, $message, $http_code) {
        update_option('tis_last_error', [
            'time'      => time(),
            'country'   => $country,
            'weight'    => $weight,
            'message'   => $message,
            'http_code' => $http_code,
        ], false);
    }

    /**
     * Normalise Shiprocket's response into a predictable shape.
     *
     * Handles the two envelopes Shiprocket is known to use on the domestic
     * endpoint; if international differs, correct this one method.
     *
     * @return array List of [courier_id, courier_name, rate, transit_days, edd].
     */
    public static function parse_rates($body) {

        if (!is_array($body)) {
            return [];
        }

        $list = [];
        if (isset($body['data']['available_courier_companies']) && is_array($body['data']['available_courier_companies'])) {
            $list = $body['data']['available_courier_companies'];
        } elseif (isset($body['available_courier_companies']) && is_array($body['available_courier_companies'])) {
            $list = $body['available_courier_companies'];
        } elseif (isset($body['data']) && is_array($body['data'])) {
            $list = $body['data'];
        }

        $rates = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Some payloads carry an explicit availability flag; respect it when present.
            if (array_key_exists('available', $row) && !$row['available']) {
                continue;
            }

            $rate = 0;
            foreach (['rate', 'freight_charge', 'total_charge', 'shipping_charge'] as $k) {
                if (isset($row[$k]) && is_numeric($row[$k])) {
                    $rate = (float) $row[$k];
                    break;
                }
            }

            if ($rate <= 0) {
                continue;
            }

            $transit = null;
            foreach (['estimated_delivery_days', 'transit_time', 'edd_days', 'delivery_days'] as $k) {
                if (isset($row[$k]) && '' !== $row[$k]) {
                    $transit = $row[$k];
                    break;
                }
            }

            $edd = null;
            foreach (['etd', 'edd', 'expected_delivery_date'] as $k) {
                if (isset($row[$k]) && '' !== $row[$k]) {
                    $edd = (string) $row[$k];
                    break;
                }
            }

            $name = 'Courier';
            foreach (['courier_name', 'courier_company_name', 'name'] as $k) {
                if (!empty($row[$k])) {
                    $name = (string) $row[$k];
                    break;
                }
            }

            $id = null;
            foreach (['courier_company_id', 'courier_id', 'id'] as $k) {
                if (isset($row[$k]) && '' !== $row[$k]) {
                    $id = (string) $row[$k];
                    break;
                }
            }
            if (null === $id) {
                $id = sanitize_key($name);
            }

            $rates[] = [
                'courier_id'   => $id,
                'courier_name' => $name,
                'rate'         => $rate,
                'transit_days' => $transit,
                'edd'          => $edd,
            ];
        }

        usort($rates, function ($a, $b) {
            return $a['rate'] <=> $b['rate'];
        });

        return $rates;
    }
}
