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
     * How long a "no couriers available" answer is cached. Deliberately far
     * shorter than the normal rate TTL — an empty result is much more likely to
     * be transient than a real rate card, and caching it for a full day would
     * hold the lane on the fallback rate long after service returned.
     */
    const EMPTY_CACHE_TTL = HOUR_IN_SECONDS;

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

        if (empty($result['rates'])) {
            // A live call that legitimately returned no couriers. Cache it only
            // briefly: at the full TTL a momentary "no couriers" answer would
            // pin this lane to the fallback rate for more than a day. Matches
            // the warmer's policy for the same case.
            self::set_cached($country, $weight, $cod, [], self::EMPTY_CACHE_TTL);
            return [];
        }

        self::set_cached($country, $weight, $cod, $result['rates']);
        return $result['rates'];
    }

    /**
     * Build the query parameters for a serviceability call.
     *
     * Single source of truth for the request shape, so that anything reporting
     * on the request (diagnostics, logs) always describes what is genuinely
     * sent rather than a copy that can drift.
     *
     * @return array
     */
    public static function build_params($country, $weight, $cod) {

        $params = [
            self::PARAM_PICKUP  => self::pickup_pincode(),
            self::PARAM_COUNTRY => self::country_value($country),
            self::PARAM_WEIGHT  => $weight,
            self::PARAM_COD     => $cod ? 1 : 0,
        ];

        return apply_filters('tis_request_params', $params, $country, $weight, $cod);
    }

    /**
     * The exact query string this plugin would send, for logging.
     *
     * Contains no credentials: the token is sent as an Authorization header and
     * never appears in the URL.
     *
     * @return string
     */
    public static function describe_request_params($country, $weight, $cod) {
        return http_build_query(self::build_params($country, $weight, $cod));
    }

    /**
     * Perform the HTTP call. Used by the warmer, the connection test, and the
     * live checkout fallback.
     *
     * @return array { rates: array, raw: mixed, error: string, url: string, http_code: int }
     */
    public static function request($country, $weight, $cod, $token, $timeout = 20) {

        $pickup = self::pickup_pincode();
        if ('' === $pickup) {
            $err = 'No pickup pincode configured (and no WooCommerce store postcode set).';
            tis_log('error', $err, ['country' => $country, 'weight' => $weight]);
            self::record_error($country, $weight, $err, null);
            return ['error' => $err, 'rates' => []];
        }

        $params = self::build_params($country, $weight, $cod);

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
            // http_code is part of the contract: callers (notably the warmer)
            // must be able to distinguish a 401 from any other failure so they
            // can stop reusing a token Shiprocket has already rejected.
            return ['error' => $msg, 'rates' => [], 'raw' => $body, 'url' => $url, 'http_code' => $code];
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
     * Charge for one courier row, with the field it came from.
     *
     * Domestic couriers carry the charge in a flat `rate`. International ones
     * were observed returning `rate` = 0 with the real charge held elsewhere
     * (`tariff_data` / `weight_cases`), which silently dropped every courier.
     *
     * Two rules matter here:
     *  - Never stop at the first *numeric* candidate, only the first *positive*
     *    one. Stopping at a numeric zero is what made the fallback chain dead
     *    code for exactly the international payloads it existed to handle.
     *  - The nested search is deliberately conservative and reports which key
     *    it used, because the exact international structure is still only
     *    partially observed. If it picks the wrong number, the log says so.
     *
     * @return array [float $rate, string $source_key]
     */
    protected static function extract_rate($row) {

        // Verified against the live international response: `rate` is not a
        // number but an object, with the actual INR charge nested one level in
        //   rate: { courier_id, id, uid, rate: 921, zone, extra_info, ... }
        // This is checked before the flat candidates because the outer key is
        // also called "rate" — assuming a field named `rate` held a number is
        // exactly what hid the price. Confirmed across ten weight slabs, and
        // independently corroborated by extra_charges[0].value matching it
        // every time.
        if (isset($row['rate']) && is_array($row['rate'])
            && isset($row['rate']['rate']) && is_numeric($row['rate']['rate'])
            && (float) $row['rate']['rate'] > 0) {
            return [(float) $row['rate']['rate'], 'rate.rate'];
        }

        $flat = ['rate', 'freight_charge', 'total_charge', 'shipping_charge', 'final_charge', 'rate_without_gst'];

        foreach ($flat as $k) {
            if (isset($row[$k]) && is_numeric($row[$k]) && (float) $row[$k] > 0) {
                return [(float) $row[$k], $k];
            }
        }

        // Deliberately excludes tariff, tariff_data and weight_cases. Live
        // responses show tariff = 0 and tariff_data = [], while weight_cases
        // is 4.3 — identical to rating, pickup_performance, delivery_performance
        // and rto_performance, i.e. a courier performance score. Treating any of
        // them as money would be reading a price into a number purely because it
        // looks like one.
        foreach (['rate_data'] as $k) {
            if (isset($row[$k]) && is_array($row[$k])) {
                list($found, $path) = self::find_nested_charge($row[$k]);
                if ($found > 0) {
                    return [$found, $k . '.' . $path];
                }
            }
        }

        return [0.0, 'none'];
    }

    /**
     * Depth-limited hunt for a positive charge inside a nested structure.
     *
     * Only keys that name a charge are considered, and rates-per-unit, taxes
     * and weights are excluded so a per-kg tariff or a GST percentage can never
     * be mistaken for the shipment total.
     *
     * @return array [float $value, string $path]
     */
    protected static function find_nested_charge($node, $depth = 0) {

        if (!is_array($node) || $depth > 4) {
            return [0.0, ''];
        }

        foreach ($node as $k => $v) {
            if (!is_string($k) || !is_numeric($v) || (float) $v <= 0) {
                continue;
            }
            if (preg_match('/(rate|charge|amount|total|price|freight|cost)/i', $k)
                && !preg_match('/(percent|tax|gst|weight|min|max|volumetric|per_kg)/i', $k)) {
                return [(float) $v, $k];
            }
        }

        foreach ($node as $k => $v) {
            if (is_array($v)) {
                list($found, $path) = self::find_nested_charge($v, $depth + 1);
                if ($found > 0) {
                    return [$found, $k . '.' . $path];
                }
            }
        }

        return [0.0, ''];
    }

    /**
     * Bounded, non-secret description of a courier row, for diagnosing a row
     * the API returned but we could not price.
     */
    protected static function describe_row($row) {

        // Dump every field, preserving type. An earlier version only reported
        // scalars, which silently hid the single most important fact: `rate`
        // exists but is null, so there was no charge to find anywhere.
        $desc = [];

        foreach ($row as $k => $v) {
            if (null === $v) {
                $desc[$k] = 'NULL';
            } elseif (is_bool($v)) {
                $desc[$k] = $v ? 'true' : 'false';
            } elseif (is_scalar($v)) {
                $desc[$k] = $v;
            } elseif (is_array($v)) {
                $desc[$k] = empty($v) ? 'EMPTY_ARRAY' : 'array:' . substr((string) wp_json_encode($v), 0, 300);
            } else {
                $desc[$k] = gettype($v);
            }
        }

        return $desc;
    }

    /**
     * Normalise Shiprocket's response into a predictable shape.
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

        $rates        = [];
        $unpriced     = [];
        $rate_sources = [];

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Some payloads carry an explicit availability flag; respect it when present.
            if (array_key_exists('available', $row) && !$row['available']) {
                continue;
            }

            list($rate, $rate_source) = self::extract_rate($row);

            if ($rate <= 0) {
                // Keep the row for diagnostics: a courier the API offered but we
                // could not price is a parsing failure, not an empty result, and
                // the two must never look the same in the log again.
                $unpriced[] = self::describe_row($row);
                continue;
            }

            $rate_sources[] = $rate_source;

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

        // The API offered couriers but none could be priced. This is a parsing
        // failure, and previously it was indistinguishable from "no couriers
        // available" — both just logged zero. Record the actual row structure
        // so the correct field can be identified from the log alone.
        if (empty($rates) && !empty($unpriced)) {
            tis_log('error', 'Shiprocket returned couriers but none could be priced — rate field not recognised.', [
                'couriers_offered' => count($unpriced),
                'first_row'        => wp_json_encode($unpriced[0]),
            ]);
        } elseif (!empty($unpriced)) {
            tis_log('notice', 'Some Shiprocket couriers could not be priced and were skipped.', [
                'priced'   => count($rates),
                'unpriced' => count($unpriced),
            ]);
        }

        if (!empty($rates)) {
            // Which field supplied the charge. For international this is the
            // difference between a correct quote and a plausible wrong number.
            tis_log('info', 'Shiprocket rates parsed.', [
                'count'        => count($rates),
                'rate_sources' => implode(',', array_unique($rate_sources)),
            ]);
        }

        usort($rates, function ($a, $b) {
            return $a['rate'] <=> $b['rate'];
        });

        return $rates;
    }
}
