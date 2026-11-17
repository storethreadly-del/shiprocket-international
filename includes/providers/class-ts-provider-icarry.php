<?php
/**
 * iCarry.in provider: domestic and international rating.
 *
 * Built against iCarry.in's API Document (v20.0). That document is marked
 * proprietary and confidential, so it is deliberately NOT reproduced in this
 * repository; what follows is only the part of the contract the code needs.
 *
 * NOT iCarry.com. That is an unrelated Middle East company with its own API and
 * its own WooCommerce plugin on wordpress.org. See SCOPE.md section 7.
 *
 * What is DOCUMENTED (and implemented here):
 *  - Login:   POST {base}api_login  fields username + key  ->  api_token.
 *             The token is valid for 60 MINUTES. The account key does not expire.
 *  - Calls:   POST {base}<endpoint>&api_token=<token>  with form fields. The
 *             documentation's own example sends a session cookie (OCSESSID) too,
 *             so the session cookie from login is kept alongside the token.
 *  - Domestic estimate:      api_get_estimate
 *  - International estimate: api_get_estimate_international
 *  - API access can be restricted by caller IP, so the server's outbound IP may
 *    need adding in the iCarry account before any call succeeds.
 *
 * What is NOT yet verified against the live service (see VERIFY below), so each
 * is isolated behind one method or filter rather than spread through the code:
 *  - whether the session cookie is required or merely sent by the sample;
 *  - whether the GST figure is already inside courier_cost (the sample implies
 *    it is not, since courier_cost = freight + COD charge);
 *  - the exact separator before api_token (the documentation prints "&" with no
 *    "?", which is OpenCart's friendly-URL style).
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

class TS_Provider_ICarry extends TS_Provider_Base {

    /**
     * The documentation says tokens last 60 minutes. Cache for 55 so a request is
     * never made with one that is about to lapse.
     */
    const TOKEN_TTL = 55 * MINUTE_IN_SECONDS;

    const PATH_LOGIN         = 'api_login';
    const PATH_ESTIMATE      = 'api_get_estimate';
    const PATH_ESTIMATE_INTL = 'api_get_estimate_international';

    /** Declared value sent for a prepaid lane; it does not change the freight. */
    const PREPAID_VALUE = 1000;

    /** COD charges scale with value, so COD lanes are cached per value slab. */
    const VALUE_SLAB = 500;

    /** Parcel dimensions sent when the caller supplies none (cm). */
    const DEFAULT_DIMS = [10, 10, 10];

    public function id() {
        return 'icarry';
    }

    public function label() {
        return __('iCarry.in (beta)', 'threadly-intl-shipping');
    }

    public function capabilities() {
        return apply_filters('ts_icarry_capabilities', [
            'domestic'      => true,
            'international' => true,
            // Order sync is phase 5. Note international BOOKING is documented as
            // available only on iCarry's Gold and Platinum plans.
            'order_sync'    => false,
        ]);
    }

    /**
     * Domestic lanes are rated by pincode, so a domestic destination with no valid
     * six-digit pincode cannot be quoted yet (the customer has not finished the
     * address). Everything else follows the shared capability rule.
     */
    public function serves(array $destination) {

        if (!parent::serves($destination)) {
            return false;
        }

        if ($this->is_domestic($destination)) {
            return (bool) preg_match('/^\d{6}$/', $this->postcode_of($destination));
        }

        return true;
    }

    /** Refresh the token in the background; see TS_Provider_Base::refresh_interval(). */
    public function refresh_interval() {
        return 40 * MINUTE_IN_SECONDS;
    }

    /* =================================================================
     * Configuration
     * ================================================================= */

    protected function api_base() {
        $base = defined('TS_IC_API_BASE') ? (string) TS_IC_API_BASE : 'https://www.icarry.in/';
        return rtrim($base, '/') . '/';
    }

    /** @return string[] Shipment modes to quote on domestic lanes: S, E, H. */
    public function modes() {
        $raw   = strtoupper((string) get_option('tis_ic_modes', 'S'));
        $modes = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), function ($m) {
            return in_array($m, ['S', 'E', 'H'], true);
        })));
        return $modes ? $modes : ['S'];
    }

    public function include_gst() {
        return 'no' !== get_option('tis_ic_include_gst', 'yes');
    }

    /* =================================================================
     * Credentials and authentication
     * ================================================================= */

    public function credentials() {
        $c = TS_ICarry_Credentials::get();
        return ['user' => $c['user'], 'password' => $c['password'], 'source' => $c['source']];
    }

    public function has_credentials() {
        return TS_ICarry_Credentials::exists();
    }

    protected function authenticate(array $credentials) {

        if ('' === trim($credentials['user']) || '' === trim($credentials['password'])) {
            return ['error' => 'No iCarry API credentials configured. Add them under WooCommerce > iCarry probe.'];
        }

        $response = $this->http->post_form(
            $this->api_base() . self::PATH_LOGIN,
            [],
            ['username' => $credentials['user'], 'key' => $credentials['password']],
            (float) apply_filters('tis_login_timeout', 20)
        );

        if (!empty($response['error'])) {
            return ['error' => $this->scrub('Login request failed: ' . $response['error'])];
        }

        $body = $response['body'];

        if (!is_array($body)) {
            return ['error' => 'iCarry returned an unreadable login response (HTTP ' . (int) $response['code'] . ').'];
        }

        if (!empty($body['error'])) {

            $message = $this->flatten_error($body['error']);

            return [
                'error'    => $this->scrub('iCarry rejected the login: ' . $message),
                // A wrong username or key will keep failing; an IP restriction or an
                // outage may not. Only the former should pause automatic logins.
                'rejected' => (bool) preg_match('/invalid username|client not found|incorrect api key|invalid.{0,20}key/i', $message),
            ];
        }

        if (empty($body['api_token'])) {
            return ['error' => 'iCarry accepted the login but returned no api_token.'];
        }

        // VERIFY: the documentation's sample request carries a session cookie. Keep
        // whatever cookies login set, and send them back on every call.
        return ['token' => $this->pack_token((string) $body['api_token'], (array) ($response['cookies'] ?? []))];
    }

    /** The cached "token" is the api_token plus the session cookie that goes with it. */
    protected function pack_token($api_token, array $cookies) {

        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return wp_json_encode(['t' => $api_token, 'c' => implode('; ', $pairs)]);
    }

    /** @return array [ api_token, cookie_header ] */
    protected function unpack_token($packed) {

        $d = json_decode((string) $packed, true);

        if (!is_array($d) || empty($d['t'])) {
            return [(string) $packed, ''];
        }

        return [(string) $d['t'], isset($d['c']) ? (string) $d['c'] : ''];
    }

    /**
     * iCarry returns errors either as a string or as an object keyed by field
     * (for example {"ip": "...", "key": "..."}). Flatten either to one line.
     */
    protected function flatten_error($error) {

        if (is_array($error)) {
            $parts = [];
            foreach ($error as $k => $v) {
                $parts[] = is_scalar($v) ? (string) $v : wp_json_encode($v);
            }
            return implode(' ', $parts);
        }

        return (string) $error;
    }

    /** Never let the account key reach a log, a notice or a stored diagnostic. */
    protected function scrub($text) {

        $c = $this->credentials();

        foreach ([$c['password']] as $secret) {
            if ('' !== (string) $secret) {
                $text = str_replace((string) $secret, '[REDACTED]', (string) $text);
            }
        }

        return $text;
    }

    /* =================================================================
     * Rating
     * ================================================================= */

    protected function is_domestic(array $destination) {
        $home = strtoupper((string) apply_filters('ts_home_country', 'IN'));
        return $home === $this->country_of($destination);
    }

    protected function country_of(array $destination) {
        return isset($destination['country']) ? strtoupper((string) $destination['country']) : '';
    }

    protected function postcode_of(array $destination) {
        return isset($destination['postcode']) ? preg_replace('/\s+/', '', (string) $destination['postcode']) : '';
    }

    /**
     * The endpoint URL WITHOUT the token. The token is added only at send time, so
     * the URL that is logged and returned for diagnostics never contains it.
     */
    protected function rate_url(array $destination, $weight, array $opts) {

        if ('' === $this->pickup_pincode()) {
            return ['error' => 'No pickup pincode configured (and no WooCommerce store postcode set).'];
        }

        if ($this->is_domestic($destination) && !preg_match('/^\d{6}$/', $this->postcode_of($destination))) {
            return ['error' => 'A six-digit destination pincode is required for a domestic lane.'];
        }

        return $this->api_base() . ($this->is_domestic($destination) ? self::PATH_ESTIMATE : self::PATH_ESTIMATE_INTL);
    }

    /**
     * VERIFY: the documentation prints "&api_token=" straight after the path, with
     * no "?". Flip the separator here if the live service wants a query string.
     */
    protected function url_with_token($url, $api_token) {
        $sep = (string) apply_filters('ts_icarry_token_separator', '&');
        return $url . $sep . 'api_token=' . rawurlencode($api_token);
    }

    /** Parcel dimensions in whole centimetres, as the API requires integers. */
    protected function dimensions(array $opts) {

        $d = (isset($opts['dimensions']) && is_array($opts['dimensions']) && 3 === count($opts['dimensions']))
            ? array_values($opts['dimensions'])
            : self::DEFAULT_DIMS;

        return array_map(function ($v) {
            return max(1, (int) ceil((float) $v));
        }, $d);
    }

    /** Declared value: real for COD (it is collected), a constant for prepaid. */
    protected function declared_value(array $opts) {

        if (empty($opts['cod'])) {
            return self::PREPAID_VALUE;
        }

        $v = max((float) $opts['value'], 1);

        return (int) (ceil($v / self::VALUE_SLAB) * self::VALUE_SLAB);
    }

    /** Form fields for one estimate call. */
    protected function estimate_fields(array $destination, $weight, array $opts, $mode) {

        list($l, $b, $h) = $this->dimensions($opts);

        // The weight passed in is already the billable weight (the greater of
        // actual and volumetric), so the dimensions only need to be small enough
        // that they do not outweigh it.
        $fields = [
            'length'                   => $l,
            'breadth'                  => $b,
            'height'                   => $h,
            'weight'                   => max(1, (int) ceil((float) $weight * 1000)),
            'origin_pincode'           => $this->pickup_pincode(),
            'destination_country_code' => $this->country_of($destination),
        ];

        if ($this->is_domestic($destination)) {
            $fields['destination_pincode']     = $this->postcode_of($destination);
            $fields['origin_country_code']     = 'IN';
            $fields['shipment_mode']           = $mode;
            $fields['shipment_type']           = !empty($opts['cod']) ? 'C' : 'P';
            $fields['shipment_value']          = $this->declared_value($opts);
        }

        return $fields;
    }

    protected function send_rate_request($url, $token, array $destination, $weight, array $opts, $timeout) {

        list($api_token, $cookie) = $this->unpack_token($token);

        $headers = ('' !== $cookie) ? ['Cookie' => $cookie] : [];
        $modes   = $this->is_domestic($destination) ? $this->modes() : [''];

        $good = [];
        $first_failure = null;

        foreach ($modes as $mode) {

            $r = $this->http->post_form(
                $this->url_with_token($url, $api_token),
                $headers,
                $this->estimate_fields($destination, $weight, $opts, $mode),
                $timeout
            );

            $failed = !empty($r['error']) || (int) $r['code'] < 200 || (int) $r['code'] >= 300
                || null !== $this->body_error($r['body']);

            if ($failed) {
                if (null === $first_failure) {
                    $first_failure = $r;
                }
                continue;
            }

            $good[$mode] = $r['body'];
            $last_ok     = $r;
        }

        // One mode answering is enough to quote. Only when every mode failed is the
        // whole request a failure, and then the first failure is what gets reported.
        if (!$good) {
            return $first_failure;
        }

        $last_ok['body'] = (1 === count($good) && isset($good['']))
            ? $good['']
            : ['modes' => $good];

        return $last_ok;
    }

    protected function body_error($body) {

        if (!is_array($body)) {
            return 'iCarry returned an unreadable response.';
        }

        if (empty($body['error'])) {
            return null;
        }

        return $this->scrub($this->flatten_error($body['error']));
    }

    /**
     * VERIFY: the wording of an expired-token error is not documented. This is a
     * deliberately broad match; dropping a good token costs one background login.
     */
    protected function is_auth_error($message) {
        return (bool) preg_match('/token|session|log ?in|not allowed|unauthori[sz]ed|authenticat/i', (string) $message);
    }

    /**
     * Country level for international lanes, pincode level for domestic ones, and
     * the modes and COD value slab always, since they change the price.
     */
    public function cache_key(array $destination, $weight, array $opts) {

        $opts = $this->normalise_opts($opts);

        $where = $this->is_domestic($destination)
            ? $this->country_of($destination) . '/' . $this->postcode_of($destination)
            : $this->country_of($destination);

        return $this->key('cache') . md5(implode('|', [
            $where,
            $this->pickup_pincode(),
            (string) $weight,
            !empty($opts['cod']) ? 'C' . $this->declared_value($opts) : 'P',
            $this->is_domestic($destination) ? implode(',', $this->modes()) : '-',
            $this->include_gst() ? 'gst' : 'nogst',
        ]));
    }

    /**
     * Domestic lanes cannot be warmed exhaustively (about 19,000 pincodes), so for
     * now only the international lanes are warmed. Warming the busiest domestic
     * pincodes from order history is phase 4; until then a cold domestic lane
     * takes the bounded live call.
     */
    public function warm_lanes() {

        $lanes = [];

        foreach ($this->warm_countries() as $country) {

            if ($this->is_domestic(['country' => $country])) {
                continue;
            }

            foreach ($this->warm_slabs() as $weight) {
                $lanes[] = [
                    'destination' => ['country' => $country, 'postcode' => ''],
                    'weight'      => $weight,
                    'cod'         => false,
                ];
            }
        }

        return $lanes;
    }

    /* =================================================================
     * Response parsing
     * ================================================================= */

    public function parse_rates($body) {

        if (!is_array($body)) {
            return [];
        }

        $rows = [];

        if (isset($body['modes']) && is_array($body['modes'])) {
            foreach ($body['modes'] as $mode => $b) {
                $rows = array_merge($rows, $this->parse_one($b, (string) $mode));
            }
        } else {
            $rows = $this->parse_one($body, '');
        }

        usort($rows, function ($a, $b) {
            return $a['rate'] <=> $b['rate'];
        });

        return $rows;
    }

    protected function parse_one($body, $mode) {

        if (!is_array($body)) {
            return [];
        }

        // International: a list under "estimates".
        if (isset($body['estimates']) && is_array($body['estimates'])) {
            return $this->parse_international($body['estimates']);
        }

        // Domestic: an object keyed by courier group under "estimate".
        if (isset($body['estimate']) && is_array($body['estimate'])) {
            return $this->parse_domestic($body['estimate'], $mode);
        }

        return [];
    }

    protected function parse_domestic(array $estimate, $mode) {

        $labels = ['S' => 'surface', 'E' => 'express', 'H' => 'hyperlocal'];
        $rows   = [];

        foreach ($estimate as $group => $r) {

            if (!is_array($r) || !isset($r['courier_cost']) || !is_numeric($r['courier_cost'])) {
                continue;
            }

            $rate = (float) $r['courier_cost'];

            // VERIFY: in the documentation's sample, courier_cost equals freight
            // plus the COD charge, so GST is a separate figure. Added by default so
            // a quote cannot undercut what the account is billed; switch off with
            // the tis_ic_include_gst option if live billing shows it is already in.
            if ($this->include_gst() && isset($r['gst']) && is_numeric($r['gst'])) {
                $rate += (float) $r['gst'];
            }

            if ($rate <= 0) {
                continue;
            }

            $id   = isset($r['courier_id']) && '' !== (string) $r['courier_id'] ? (string) $r['courier_id'] : sanitize_key((string) $group);
            $name = !empty($r['courier_name']) ? (string) $r['courier_name'] : (!empty($r['courier_group_name']) ? (string) $r['courier_group_name'] : (string) $group);

            $rows[] = [
                // The mode is part of the id: the same courier can be quoted in
                // more than one mode, and WooCommerce needs distinct rate ids.
                'courier_id'    => '' !== $mode ? $id . '-' . strtolower($mode) : $id,
                'courier_name'  => $name,
                'rate'          => round($rate, 2),
                // iCarry's estimate carries no transit time or delivery date.
                'transit_days'  => null,
                'edd'           => null,
                'provider'      => $this->id(),
                'service_type'  => isset($labels[$mode]) ? $labels[$mode] : '',
                'cod_available' => isset($r['cod_cost']) && is_numeric($r['cod_cost']) && (float) $r['cod_cost'] > 0,
                'rto_charge'    => isset($r['rto_cost']) && is_numeric($r['rto_cost']) ? (float) $r['rto_cost'] : null,
                'delivery_rate' => null,
            ];
        }

        return $rows;
    }

    protected function parse_international(array $list) {

        $rows = [];

        foreach ($list as $r) {

            if (!is_array($r)) {
                continue;
            }

            if (isset($r['total']) && is_numeric($r['total'])) {
                $rate = (float) $r['total'];
            } else {
                $rate = 0.0;
                foreach (['shipping', 'tax', 'surcharge', 'fsc'] as $k) {
                    $rate += isset($r[$k]) && is_numeric($r[$k]) ? (float) $r[$k] : 0.0;
                }
            }

            if ($rate <= 0) {
                continue;
            }

            $name = !empty($r['courier_name']) ? (string) $r['courier_name'] : 'Courier';
            $id   = isset($r['courier_id']) && '' !== (string) $r['courier_id'] ? (string) $r['courier_id'] : sanitize_key($name);

            $rows[] = [
                'courier_id'    => $id,
                'courier_name'  => $name,
                'rate'          => round($rate, 2),
                'transit_days'  => null,
                'edd'           => null,
                'provider'      => $this->id(),
                'service_type'  => '',
                'cod_available' => false,
                'rto_charge'    => null,
                'delivery_rate' => null,
            ];
        }

        return $rows;
    }
}
