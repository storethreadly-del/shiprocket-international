<?php
/**
 * Shiprocket X provider — international lanes only.
 *
 * This is a behaviour-preserving refactor of v0.2.0's class-tis-auth.php and
 * class-tis-api.php. Three things are held fixed on purpose:
 *
 *  1. Every storage key keeps its v0.2.0 name (see key()). Renaming them on
 *     upgrade would orphan the stored credentials and discard a warm token and
 *     a warm rate cache.
 *  2. cache_key() reproduces the v0.2.0 md5 formula byte for byte, so an
 *     upgrade inherits the existing warm cache instead of starting cold.
 *  3. Postcode is ignored throughout. Shiprocket international rating is
 *     country-level; folding a postcode into the key would multiply the cache
 *     by every customer address for no change in the quoted rate. This is the
 *     provider owning its own key shape, as the interface describes.
 *
 * Credentials may be supplied two ways, in order of precedence:
 *   1. Constants in wp-config.php (keeps the password out of the DB)
 *        define('TIS_SR_EMAIL',    'intl-api@yourdomain.com');
 *        define('TIS_SR_PASSWORD', '...');
 *      The plugin never writes these itself — a manual, opt-in step only.
 *   2. The settings screen, stored in wp_options.
 *
 * Create a DEDICATED API user in the Shiprocket dashboard (Settings > API >
 * Configure) for this plugin. Shiprocket's failed-login lockout applies per API
 * user, so a problem with one integration's credentials cannot block the other.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

class TS_Provider_Shiprocket extends TS_Provider_Base {

    /* ---------------------------------------------------------------------
     * UNVERIFIED CONTRACT
     *
     * The endpoint path is confirmed against a maintained Shiprocket SDK.
     * The parameter names and response envelope are NOT confirmed against
     * Shiprocket's own documentation — their API docs are not publicly
     * readable.
     *
     * Run Tools > Intl Shipping > "Run live probe" once with real credentials,
     * read the raw JSON it dumps, and correct these four constants plus
     * parse_rates() if they differ. Nothing else needs to change.
     * ------------------------------------------------------------------- */
    const ENDPOINT      = 'courier/international/serviceability/';
    const PARAM_PICKUP  = 'pickup_postcode';
    const PARAM_COUNTRY = 'delivery_country';
    const PARAM_WEIGHT  = 'weight';
    const PARAM_COD     = 'cod';
    /* ------------------------------------------------------------------- */

    public function id() {
        return 'shiprocket';
    }

    public function label() {
        return __('Shiprocket X', 'threadly-intl-shipping');
    }

    /**
     * Shiprocket is international-only here: the official Shiprocket plugin
     * owns domestic India, and order sync against this account is not built
     * yet (SCOPE.md phase 5).
     */
    public function capabilities() {
        return apply_filters('ts_shiprocket_capabilities', [
            'domestic'      => false,
            'international' => true,
            'order_sync'    => false,
        ]);
    }

    /**
     * Legacy storage keys, preserved exactly. See the class docblock.
     */
    protected function key($name) {

        $map = [
            'token'        => 'tis_sr_token',
            'fails'        => 'tis_sr_auth_fails',
            'blocked'      => 'tis_sr_auth_blocked',
            'lock'         => 'tis_sr_auth_lock',
            'circuit'      => 'tis_circuit_open',
            'cache'        => 'tis_rates_',
            'last_success' => 'tis_last_success',
            'last_error'   => 'tis_last_error',
        ];

        return isset($map[$name]) ? $map[$name] : parent::key($name);
    }

    /* =================================================================
     * Credentials
     * ================================================================= */

    public function credentials() {

        if (defined('TIS_SR_EMAIL') && defined('TIS_SR_PASSWORD') && TIS_SR_EMAIL && TIS_SR_PASSWORD) {
            return [
                'email'    => TIS_SR_EMAIL,
                'password' => TIS_SR_PASSWORD,
                'source'   => 'wp-config',
            ];
        }

        return [
            'email'    => (string) get_option('tis_sr_email', ''),
            'password' => (string) get_option('tis_sr_password', ''),
            'source'   => 'settings',
        ];
    }

    public function has_credentials() {
        $c = $this->credentials();
        return ('' !== trim($c['email']) && '' !== trim($c['password']));
    }

    protected function authenticate(array $credentials) {

        if ('' === trim($credentials['email']) || '' === trim($credentials['password'])) {
            return ['error' => 'No Shiprocket API user configured for this plugin. Add one under WooCommerce > Intl Shipping.'];
        }

        $response = $this->http->post(
            $this->base_url() . 'auth/login',
            ['Content-Type' => 'application/json'],
            [
                'email'    => $credentials['email'],
                'password' => $credentials['password'],
            ],
            (float) apply_filters('tis_login_timeout', 20)
        );

        if (!empty($response['error'])) {
            return ['error' => 'Login request failed: ' . $response['error']];
        }

        $code = (int) $response['code'];
        $body = $response['body'];

        if ($code < 200 || $code >= 300 || empty($body['token'])) {
            $msg = (is_array($body) && !empty($body['message'])) ? $body['message'] : 'HTTP ' . $code;
            return ['error' => 'Shiprocket rejected the login: ' . $msg];
        }

        return ['token' => (string) $body['token']];
    }

    /* =================================================================
     * Rating
     * ================================================================= */

    protected function base_url() {
        return TIS_SR_API_BASE;
    }

    /**
     * Value to send as the destination country.
     *
     * WooCommerce hands us an ISO-2 code. Shiprocket may want the full country
     * name instead; flip this with the filter once the probe says which.
     */
    public function country_value($iso2) {
        return apply_filters('tis_delivery_country_value', $iso2, $iso2);
    }

    /** Destination country, uppercased. Postcode is deliberately ignored. */
    protected function country_of(array $destination) {
        return isset($destination['country']) ? strtoupper((string) $destination['country']) : '';
    }

    /**
     * Country-level only — see the class docblock, point 3.
     */
    protected function destination_label(array $destination) {
        return $this->country_of($destination);
    }

    /**
     * v0.2.0's cache key, reproduced exactly so an upgrade keeps its warm cache.
     *
     * Legacy formula: 'tis_rates_' . md5( pickup | country | weight | cod )
     */
    public function cache_key(array $destination, $weight, array $opts) {

        $cod = !empty($opts['cod']) ? 1 : 0;

        return $this->key('cache') . md5(
            $this->pickup_pincode() . '|' . $this->country_of($destination) . '|' . $weight . '|' . $cod
        );
    }

    protected function rate_url(array $destination, $weight, array $opts) {

        $pickup = $this->pickup_pincode();

        if ('' === $pickup) {
            return ['error' => 'No pickup pincode configured (and no WooCommerce store postcode set).'];
        }

        $country = $this->country_of($destination);
        $cod     = !empty($opts['cod']) ? 1 : 0;

        $params = [
            self::PARAM_PICKUP  => $pickup,
            self::PARAM_COUNTRY => $this->country_value($country),
            self::PARAM_WEIGHT  => $weight,
            self::PARAM_COD     => $cod,
        ];

        /**
         * Filter kept at its v0.2.0 signature ($params, $country, $weight, $cod)
         * so existing site-level overrides keep working.
         */
        $params = apply_filters('tis_request_params', $params, $country, $weight, $cod);

        return $this->base_url() . ltrim(self::ENDPOINT, '/') . '?' . http_build_query($params);
    }

    /**
     * Lanes to keep warm: the configured countries across the configured weight
     * slabs. International rating being country-level is what makes warming
     * every lane practical here.
     */
    public function warm_lanes() {

        $lanes = [];

        foreach ($this->warm_countries() as $country) {
            foreach ($this->warm_slabs() as $weight) {
                $lanes[] = [
                    'destination' => ['country' => $country, 'postcode' => ''],
                    'weight'      => $weight,
                    // International shipments are prepaid; COD never applies.
                    'cod'         => false,
                ];
            }
        }

        return $lanes;
    }

    /**
     * Normalise Shiprocket's response into the rate row the rest of the plugin
     * is written against.
     *
     * Handles the two envelopes Shiprocket is known to use on the domestic
     * endpoint; if international differs, correct this one method.
     */
    public function parse_rates($body) {

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

            // Some payloads carry an explicit availability flag; respect it.
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

            $service = '';
            foreach (['service_type', 'courier_type', 'mode'] as $k) {
                if (!empty($row[$k])) {
                    $service = (string) $row[$k];
                    break;
                }
            }

            $rates[] = [
                'courier_id'    => $id,
                'courier_name'  => $name,
                'rate'          => $rate,
                'transit_days'  => $transit,
                'edd'           => $edd,
                'provider'      => $this->id(),
                'service_type'  => $service,
                'cod_available' => !empty($row['cod']),
                'rto_charge'    => null,
                'delivery_rate' => null,
            ];
        }

        usort($rates, function ($a, $b) {
            return $a['rate'] <=> $b['rate'];
        });

        return $rates;
    }
}
