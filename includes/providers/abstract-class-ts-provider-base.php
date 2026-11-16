<?php
/**
 * Shared provider machinery.
 *
 * Everything here is aggregator-agnostic and was proven in v0.2.0 against
 * Shiprocket: the cache-only checkout token rule, the login mutex, the
 * consecutive-failure lockout, the circuit breaker, the rate cache and the
 * diagnostics counters. It is hoisted into a base class so the iCarry provider
 * inherits it rather than reimplementing ~250 lines of it.
 *
 * Storage keys are indirected through key() so that the Shiprocket provider can
 * keep the exact option and transient names v0.2.0 used. That matters on
 * upgrade: renaming them would silently orphan the stored credentials and throw
 * away a warm token and a warm rate cache.
 *
 * Storage itself (transients and options) is still WordPress-native here. It is
 * the one HIE seam left open — every read and write funnels through the five
 * storage primitives at the foot of this class, so swapping it out later is a
 * change to those five methods rather than a hunt through the class.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

abstract class TS_Provider_Base implements TS_Provider {

    /** @var TS_Http */
    protected $http;

    /** @var TS_Logger */
    protected $logger;

    /**
     * Token lifetime. Shiprocket documents 240 hours (10 days); refreshing well
     * ahead of that means a login never has to happen on a stale edge case.
     */
    const TOKEN_TTL = 7 * DAY_IN_SECONDS;

    /** Consecutive failures before we stop trying. */
    const MAX_FAILS = 3;

    /** How long a login may hold the mutex before it is considered stale. */
    const LOCK_TTL = 25;

    public function __construct(?TS_Http $http = null, ?TS_Logger $logger = null) {
        $this->http   = $http ?: new TS_Http_WP();
        $this->logger = $logger ?: new TS_Logger_Null();
    }

    /* =================================================================
     * Provider-specific hooks
     * ================================================================= */

    /**
     * Storage key for a logical name.
     *
     * Override to preserve legacy names. Default namespaces everything under
     * the provider id, which is what a new provider wants.
     *
     * @param string $name token|fails|blocked|lock|circuit|cache|last_success|last_error
     * @return string
     */
    protected function key($name) {
        return 'ts_' . $this->id() . '_' . $name;
    }

    /**
     * Credentials for this provider.
     *
     * @return array { ...provider-specific fields, source: string }
     */
    abstract public function credentials();

    /**
     * Perform the actual authentication call.
     *
     * Implementations do the HTTP round trip and nothing else — the mutex,
     * failure counting, lockout and token caching are all handled by login()
     * below.
     *
     * @param array $credentials
     * @return array { token: string } or { error: string }
     */
    abstract protected function authenticate(array $credentials);

    /**
     * Build the rate-request URL and query for this aggregator.
     *
     * @return string Full URL.
     */
    abstract protected function rate_url(array $destination, $weight, array $opts);

    /**
     * Turn this aggregator's response envelope into normalised rate rows.
     *
     * @param mixed $body Decoded response.
     * @return array
     */
    abstract public function parse_rates($body);

    /* =================================================================
     * Identity
     * ================================================================= */

    public function supports($capability) {
        $caps = $this->capabilities();
        return !empty($caps[$capability]);
    }

    /**
     * Capability-based serviceability.
     *
     * This is what replaces the hardcoded `'IN' === $country` guard that used
     * to sit in calculate_shipping(): whether India is in scope is now a
     * property of the active provider, not a constant of the plugin.
     */
    public function serves(array $destination) {

        $country = isset($destination['country']) ? strtoupper((string) $destination['country']) : '';

        if ('' === $country) {
            return false;
        }

        $home = strtoupper((string) apply_filters('ts_home_country', 'IN'));

        return ($country === $home)
            ? $this->supports('domestic')
            : $this->supports('international');
    }

    /* =================================================================
     * Credentials and authentication
     * ================================================================= */

    public function credential_source() {
        $c = $this->credentials();
        return isset($c['source']) ? $c['source'] : 'settings';
    }

    public function cached_token() {
        $token = $this->cache_get($this->key('token'));
        return (is_string($token) && '' !== $token) ? $token : false;
    }

    public function token() {

        $token = $this->cached_token();
        if ($token) {
            return $token;
        }

        $blocked = $this->blocked_until();
        if ($blocked > time()) {
            return ['error' => sprintf(
                '%s login paused after %d consecutive failures. Retrying in %d minute(s). Check the API credentials.',
                $this->label(),
                self::MAX_FAILS,
                (int) ceil(($blocked - time()) / MINUTE_IN_SECONDS)
            )];
        }

        return $this->login(false);
    }

    public function test_connection() {

        $blocked = $this->blocked_until();
        if ($blocked > time()) {
            return ['error' => sprintf(
                '%s login is currently locked out after repeated failures. Retrying automatically in %d minute(s).',
                $this->label(),
                (int) ceil(($blocked - time()) / MINUTE_IN_SECONDS)
            )];
        }

        return $this->login(true);
    }

    /**
     * Authenticate, guarded by a mutex.
     *
     * Without the lock, several near-simultaneous callers (e.g. WP-Cron's
     * pseudo-cron firing off the back of concurrent page loads once the daily
     * warm event is due) can all find a cold token cache at once and each fire
     * off their own login — an authentication storm against a single API user,
     * which is exactly what trips a vendor lockout. Only one caller actually
     * authenticates; the rest wait briefly and reuse its token.
     *
     * @param bool $force Skip the cached-token short-circuit.
     * @return string|array Token string, or array with 'error'.
     */
    public function login($force = false) {

        if (!$this->has_credentials()) {
            return ['error' => sprintf(
                'No %s API credentials configured. Add them under WooCommerce > Intl Shipping.',
                $this->label()
            )];
        }

        if (!$force) {
            $existing = $this->cached_token();
            if ($existing) {
                return $existing;
            }
        }

        if (!$this->acquire_lock()) {
            // Another request is mid-login. Give it a moment and reuse its
            // result rather than starting a second one.
            usleep(500000);
            $existing = $this->cached_token();
            if ($existing) {
                return $existing;
            }
            $this->logger->log('notice', 'Skipped login — another attempt was already in progress.', ['provider' => $this->id()]);
            return ['error' => sprintf(
                'Another %s authentication attempt is already in progress. Please try again in a few seconds.',
                $this->label()
            )];
        }

        try {

            $result = $this->authenticate($this->credentials());

            if (!empty($result['error'])) {
                $this->record_failure();
                $this->logger->log('error', 'Authentication failed.', [
                    'provider' => $this->id(),
                    'message'  => $result['error'],
                ]);
                return ['error' => $result['error']];
            }

            $this->clear_failures();
            $this->cache_set($this->key('token'), (string) $result['token'], static::TOKEN_TTL);
            $this->logger->log('info', 'Authentication succeeded.', [
                'provider' => $this->id(),
                'source'   => $this->credential_source(),
            ]);

            return (string) $result['token'];

        } finally {
            $this->release_lock();
        }
    }

    public function forget_token() {
        $this->cache_delete($this->key('token'));
    }

    public function clear_failures() {
        $this->cache_delete($this->key('fails'));
        $this->cache_delete($this->key('blocked'));
    }

    public function auth_status() {

        $blocked = $this->blocked_until();

        return [
            'configured'  => $this->has_credentials(),
            'source'      => $this->credential_source(),
            'has_token'   => (bool) $this->cached_token(),
            'fails'       => (int) $this->cache_get($this->key('fails')),
            'blocked_for' => $blocked > time() ? ($blocked - time()) : 0,
        ];
    }

    protected function blocked_until() {
        return (int) $this->cache_get($this->key('blocked'));
    }

    protected function record_failure() {

        $fails = (int) $this->cache_get($this->key('fails')) + 1;
        $this->cache_set($this->key('fails'), $fails, 15 * MINUTE_IN_SECONDS);

        if ($fails >= static::MAX_FAILS) {
            $minutes = (int) apply_filters('tis_auth_block_minutes', 15);
            $this->cache_set($this->key('blocked'), time() + ($minutes * MINUTE_IN_SECONDS), $minutes * MINUTE_IN_SECONDS);
            $this->logger->log('warning', 'Authentication locked out after repeated failures.', [
                'provider'        => $this->id(),
                'fails'           => $fails,
                'blocked_minutes' => $minutes,
            ]);
        }
    }

    /**
     * add_option() is a plain INSERT against a UNIQUE key in wp_options, so
     * under concurrent requests exactly one caller wins this race — a
     * lightweight mutex with no external lock service.
     */
    protected function acquire_lock() {

        $lock_key = $this->key('lock');
        $existing = get_option($lock_key);

        if ($existing && (time() - (int) $existing) > static::LOCK_TTL) {
            // A previous login died mid-flight (e.g. PHP timeout) without
            // releasing the lock. Treat it as stale and reclaim it.
            delete_option($lock_key);
        }

        return (bool) add_option($lock_key, time(), '', false);
    }

    protected function release_lock() {
        delete_option($this->key('lock'));
    }

    /* =================================================================
     * Rating
     * ================================================================= */

    public function rates(array $destination, $weight, array $opts = []) {

        $opts = $this->normalise_opts($opts);

        $cached = $this->get_cached($destination, $weight, $opts);
        if (is_array($cached)) {
            return $cached;
        }

        if (empty($opts['allow_live']) || $this->circuit_is_open()) {
            return [];
        }

        $token = $this->cached_token();
        if (!$token) {
            // No warm token. Do not authenticate on the customer's thread.
            return [];
        }

        // A BOUNDED live call. The timeout, the circuit breaker and the
        // cache-only token rule above are what make it safe to do this while a
        // customer waits: the request cannot hang, a failing aggregator stops
        // being called at all, and a login never happens on this thread.
        $result = $this->request($destination, $weight, $opts, $token, $this->frontend_timeout());

        if (!empty($result['error'])) {
            $this->trip_circuit();
            return [];
        }

        // Cache an empty result only briefly. A lane that returned no couriers
        // may simply have been unserviceable for a moment; holding that for the
        // full rate TTL would keep serving the fallback rate long after the
        // aggregator recovered. The warmer has always made this distinction —
        // the checkout path did not, which mattered little for a handful of
        // countries but matters a lot for pincode-level lanes, where a cold
        // miss is routine.
        $ttl = empty($result['rates']) ? $this->negative_cache_ttl() : null;

        $this->set_cached($destination, $weight, $opts, $result['rates'], $ttl);

        return $result['rates'];
    }

    /**
     * How long a live rate call may take while a customer waits, in seconds.
     *
     * A provider may tighten this: a domestic lane that is expected to miss the
     * cache often cannot afford the same budget as a rare international miss.
     *
     * @return float
     */
    protected function frontend_timeout() {
        return (float) apply_filters('tis_frontend_timeout', 5, $this->id());
    }

    /**
     * How long to remember that a lane returned no couriers, in seconds.
     *
     * Deliberately much shorter than the rate TTL — see rates().
     *
     * Public because the warmer applies the same policy to a lane that comes
     * back empty, so the number lives in exactly one place.
     *
     * @return int
     */
    public function negative_cache_ttl() {
        return (int) apply_filters('ts_negative_cache_ttl', HOUR_IN_SECONDS, $this->id());
    }

    public function request(array $destination, $weight, array $opts, $token, $timeout = 20) {

        $opts = $this->normalise_opts($opts);
        $url  = $this->rate_url($destination, $weight, $opts);

        if (is_array($url) && !empty($url['error'])) {
            $this->logger->log('error', 'Rate request not attempted.', [
                'provider' => $this->id(),
                'message'  => $url['error'],
            ]);
            $this->record_error($destination, $weight, $url['error'], null);
            return ['error' => $url['error'], 'rates' => []];
        }

        $response = $this->http->get($url, ['Authorization' => 'Bearer ' . $token], $timeout);

        $log = [
            'provider'    => $this->id(),
            'destination' => $this->destination_label($destination),
            'weight'      => $weight,
        ];

        if (!empty($response['error'])) {
            $this->logger->log('warning', 'Rate request failed (transport error).', $log + ['error' => $response['error']]);
            $this->record_error($destination, $weight, $response['error'], null);
            return ['error' => $response['error'], 'rates' => [], 'url' => $url];
        }

        $code = (int) $response['code'];
        $body = $response['body'];

        if (401 === $code) {
            $this->forget_token();
            $this->logger->log('warning', 'Received 401 on a rate request; cached token invalidated.', $log);
        }

        if ($code < 200 || $code >= 300) {
            $msg = (is_array($body) && !empty($body['message'])) ? $body['message'] : 'HTTP ' . $code;
            $this->logger->log('warning', 'Rate request failed.', $log + ['http_code' => $code, 'message' => $msg]);
            $this->record_error($destination, $weight, $msg, $code);
            return ['error' => $msg, 'rates' => [], 'raw' => $body, 'url' => $url];
        }

        $rates = $this->parse_rates($body);

        $this->logger->log('info', 'Rate request succeeded.', $log + ['couriers' => count($rates)]);
        $this->record_success($destination, $weight, count($rates));

        return [
            'rates' => $rates,
            'raw'   => $body,
            'url'   => $url,
        ];
    }

    /**
     * Fill in rating option defaults.
     *
     * allow_live defaults to true because the warmer and the probe both want a
     * live call; the checkout path is the one that passes false when it must
     * not block.
     */
    protected function normalise_opts(array $opts) {
        return array_merge([
            'cod'        => false,
            'allow_live' => true,
        ], $opts);
    }

    /** Short label for logs and diagnostics. */
    protected function destination_label(array $destination) {
        $country  = isset($destination['country']) ? (string) $destination['country'] : '?';
        $postcode = isset($destination['postcode']) ? trim((string) $destination['postcode']) : '';
        return '' !== $postcode ? $country . '/' . $postcode : $country;
    }

    /* =================================================================
     * Cache and circuit breaker
     * ================================================================= */

    /**
     * Cache key for a lane.
     *
     * Deliberately NOT final: a pincode-level provider overrides this so that
     * domestic rating does not inherit country-level key collapsing.
     */
    public function cache_key(array $destination, $weight, array $opts) {
        return $this->key('cache') . md5(implode('|', [
            $this->destination_label($destination),
            (string) $weight,
            !empty($opts['cod']) ? '1' : '0',
        ]));
    }

    public function get_cached(array $destination, $weight, array $opts = []) {
        return $this->cache_get($this->cache_key($destination, $weight, $this->normalise_opts($opts)));
    }

    public function set_cached(array $destination, $weight, array $opts, $rates, $ttl = null) {

        if (null === $ttl) {
            $ttl = $this->cache_ttl();
        }

        $this->cache_set(
            $this->cache_key($destination, $weight, $this->normalise_opts($opts)),
            $rates,
            $ttl
        );
    }

    /** @return int Seconds. */
    protected function cache_ttl() {
        return max(1, (int) get_option('tis_cache_hours', 26)) * HOUR_IN_SECONDS;
    }

    /**
     * Drop every cached rate for this provider.
     */
    public function flush_cache() {

        $this->cache_delete_prefixed($this->key('cache'));

        $this->logger->log('info', 'Rate cache flushed.', ['provider' => $this->id()]);
    }

    public function circuit_is_open() {
        return (bool) $this->cache_get($this->key('circuit'));
    }

    protected function trip_circuit() {
        $minutes = (int) apply_filters('tis_circuit_open_minutes', 10);
        $this->cache_set($this->key('circuit'), 1, max(1, $minutes) * MINUTE_IN_SECONDS);
        $this->logger->log('warning', 'Rate-fetch circuit breaker opened.', [
            'provider'     => $this->id(),
            'open_minutes' => $minutes,
        ]);
    }

    public function reset_circuit() {
        if ($this->circuit_is_open()) {
            $this->logger->log('info', 'Rate-fetch circuit breaker closed.', ['provider' => $this->id()]);
        }
        $this->cache_delete($this->key('circuit'));
    }

    /* =================================================================
     * Diagnostics
     * ================================================================= */

    protected function record_success(array $destination, $weight, $courier_count) {
        $this->state_set($this->key('last_success'), [
            'time'     => time(),
            'country'  => $this->destination_label($destination),
            'weight'   => $weight,
            'couriers' => $courier_count,
        ]);
    }

    protected function record_error(array $destination, $weight, $message, $http_code) {
        $this->state_set($this->key('last_error'), [
            'time'      => time(),
            'country'   => $this->destination_label($destination),
            'weight'    => $weight,
            'message'   => $message,
            'http_code' => $http_code,
        ]);
    }

    public function last_success() {
        return $this->state_get($this->key('last_success'));
    }

    public function last_error() {
        return $this->state_get($this->key('last_error'));
    }

    /* =================================================================
     * Storage primitives — the remaining HIE seam (see class docblock).
     * Nothing else in this class may touch WordPress storage directly.
     * ================================================================= */

    protected function cache_get($key) {
        return get_transient($key);
    }

    protected function cache_set($key, $value, $ttl) {
        set_transient($key, $value, $ttl);
    }

    protected function cache_delete($key) {
        delete_transient($key);
    }

    protected function state_get($key) {
        return get_option($key);
    }

    protected function state_set($key, $value) {
        update_option($key, $value, false);
    }

    /**
     * Delete every cache entry whose key starts with $prefix.
     *
     * Transients have no prefix search, so this goes at the options table
     * directly — the same approach WooCommerce itself uses to clear transient
     * families.
     */
    protected function cache_delete_prefixed($prefix) {

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . $prefix) . '%',
                $wpdb->esc_like('_transient_timeout_' . $prefix) . '%'
            )
        );
    }
}
