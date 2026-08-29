<?php
/**
 * Shiprocket authentication, using this plugin's own API user.
 *
 * Deliberately independent of the HIBOU Intelligence Engine (historical
 * context: this plugin used to borrow HIBOU's cached token; it now owns its
 * own credentials, its own token cache, and its own lockout state). Create a
 * SECOND API user in the Shiprocket dashboard (Settings > API > Configure)
 * for this plugin — Shiprocket's failed-login lockout applies per API user,
 * so a problem with one integration's credentials cannot block the other.
 *
 * Credentials may be supplied two ways, in order of precedence:
 *   1. Constants in wp-config.php  (keeps the password out of the DB)
 *        define('TIS_SR_EMAIL',    'intl-api@yourdomain.com');
 *        define('TIS_SR_PASSWORD', '...');
 *      This plugin never writes these constants itself — they are a manual,
 *      opt-in step only.
 *   2. The settings screen, stored in wp_options.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

class TIS_Auth {

    const TOKEN_KEY = 'tis_sr_token';
    const FAIL_KEY  = 'tis_sr_auth_fails';
    const BLOCK_KEY = 'tis_sr_auth_blocked';
    const LOCK_KEY  = 'tis_sr_auth_lock';

    /**
     * Shiprocket documents the auth token as valid for 240 hours (10 days).
     * Refresh well ahead of that so a login never has to happen on a stale
     * edge case; 7 days leaves comfortable margin.
     */
    const TOKEN_TTL = 7 * DAY_IN_SECONDS;

    /** Consecutive failures before we stop trying. */
    const MAX_FAILS = 3;

    /** How long a login is allowed to hold the mutex before it's considered stale. */
    const LOCK_TTL = 25;

    /**
     * @return array { email, password, source }
     */
    public static function credentials() {

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

    public static function has_credentials() {
        $c = self::credentials();
        return ('' !== trim($c['email']) && '' !== trim($c['password']));
    }

    /**
     * Cached token only — never logs in.
     *
     * This is what the checkout path uses. A login is a blocking HTTP round
     * trip and must never happen while a customer waits for shipping rates.
     *
     * @return string|false
     */
    public static function cached_token() {
        $token = get_transient(self::TOKEN_KEY);
        return (is_string($token) && '' !== $token) ? $token : false;
    }

    /**
     * Token for cron/admin context, logging in if necessary.
     *
     * @return string|array Token string, or array with 'error'.
     */
    public static function token() {

        $token = self::cached_token();
        if ($token) {
            return $token;
        }

        $blocked_until = (int) get_transient(self::BLOCK_KEY);
        if ($blocked_until > time()) {
            return ['code' => 'locked', 'error' => sprintf(
                'Shiprocket login paused after %d consecutive failures. Retrying in %d minute(s). Check the API user credentials.',
                self::MAX_FAILS,
                (int) ceil(($blocked_until - time()) / MINUTE_IN_SECONDS)
            )];
        }

        return self::login(false);
    }

    /**
     * Explicit, admin-initiated connection test.
     *
     * Bypasses the cached token so it actually re-verifies the credentials,
     * but still goes through the same lock and lockout machinery as every
     * other login — an admin mashing "Test connection" cannot itself cause
     * an authentication storm.
     *
     * @return string|array Token string, or array with 'error'.
     */
    public static function test_connection() {

        $blocked_until = (int) get_transient(self::BLOCK_KEY);
        if ($blocked_until > time()) {
            return ['code' => 'locked', 'error' => sprintf(
                'Shiprocket login is currently locked out after repeated failures. Retrying automatically in %d minute(s).',
                (int) ceil(($blocked_until - time()) / MINUTE_IN_SECONDS)
            )];
        }

        return self::login(true);
    }

    /**
     * Perform the login call, guarded by a mutex.
     *
     * Without the lock, several near-simultaneous callers (e.g. WP-Cron's
     * pseudo-cron firing off the back of concurrent page loads once the daily
     * warm event is due) can all find a cold token cache at once and each
     * fire off their own login — an authentication storm against a single
     * Shiprocket API user, which is exactly what trips their lockout. Only
     * one caller is allowed to actually authenticate at a time; the rest wait
     * briefly and reuse whatever token that caller produces.
     *
     * @param bool $force Skip the cached-token short-circuit (used by the
     *                     explicit "Test connection" admin action).
     * @return string|array Token string, or array with 'error'.
     */
    public static function login($force = false) {

        $creds = self::credentials();

        if ('' === trim($creds['email']) || '' === trim($creds['password'])) {
            return ['code' => 'no_credentials', 'error' => 'No Shiprocket API user configured for this plugin. Add one under WooCommerce > Intl Shipping.'];
        }

        if (!$force) {
            $existing = self::cached_token();
            if ($existing) {
                return $existing;
            }
        }

        if (!self::acquire_lock()) {
            // Another request is mid-login right now. Give it a moment to
            // finish and reuse its result instead of starting a second one.
            usleep(500000);
            $existing = self::cached_token();
            if ($existing) {
                return $existing;
            }
            tis_log('notice', 'Skipped Shiprocket login — another attempt was already in progress.');
            return ['code' => 'busy', 'error' => 'Another Shiprocket authentication attempt is already in progress. Please try again in a few seconds.'];
        }

        try {

            $response = wp_remote_post(TIS_SR_API_BASE . 'auth/login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'       => wp_json_encode([
                    'email'    => $creds['email'],
                    'password' => $creds['password'],
                ]),
                'timeout'    => (float) apply_filters('tis_login_timeout', 20),
                'sslverify'  => true,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ]);

            if (is_wp_error($response)) {
                self::record_failure();
                tis_log('error', 'Shiprocket authentication request failed.', ['error' => $response->get_error_message()]);
                // Transport-level failure: Shiprocket unreachable, not bad credentials.
                return ['code' => 'transport', 'error' => 'Login request failed: ' . $response->get_error_message()];
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code < 200 || $code >= 300 || empty($body['token'])) {
                self::record_failure();
                $msg = is_array($body) && !empty($body['message']) ? $body['message'] : 'HTTP ' . $code;
                tis_log('error', 'Shiprocket rejected authentication.', ['http_code' => $code, 'message' => $msg]);
                // 4xx means the credentials themselves were refused; 5xx means
                // Shiprocket is having trouble. The admin needs to be told
                // different things in those two cases.
                return [
                    'code'  => ($code >= 500) ? 'transport' : 'auth_rejected',
                    'error' => 'Shiprocket rejected the login: ' . $msg,
                ];
            }

            self::clear_failures();
            set_transient(self::TOKEN_KEY, (string) $body['token'], self::TOKEN_TTL);
            tis_log('info', 'Shiprocket authentication succeeded.', ['source' => $creds['source']]);

            return (string) $body['token'];

        } finally {
            self::release_lock();
        }
    }

    /**
     * Drop the cached token — used when Shiprocket returns 401 on a data call,
     * or by the admin's "Clear cached token" action.
     */
    public static function forget() {
        delete_transient(self::TOKEN_KEY);
    }

    /**
     * Acquire the login mutex.
     *
     * Deliberately bypasses the options API. add_option() is NOT atomic: it
     * does a get_option() existence check and then runs
     * "INSERT ... ON DUPLICATE KEY UPDATE", which never errors on a duplicate.
     * Two concurrent requests can both pass the check and both come back
     * truthy — i.e. two simultaneous logins, the exact authentication storm
     * this lock exists to prevent.
     *
     * A bare INSERT against the UNIQUE option_name key does fail for the
     * loser of the race, which is the property we actually need. Because we
     * write around the options API, this key must never be read back through
     * get_option() — nothing else touches it.
     *
     * @return bool True if this caller now owns the lock.
     */
    protected static function acquire_lock() {

        global $wpdb;

        // Reclaim a lock left behind by a login that died mid-flight (e.g. a
        // PHP timeout, which skips the finally block). The age test is part of
        // the DELETE so a live lock can never be removed by a racing caller.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
            self::LOCK_KEY,
            time() - self::LOCK_TTL
        ));

        $suppress = $wpdb->suppress_errors(true);

        // 1 on insert, false on duplicate key — the loser is told it lost.
        $acquired = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            self::LOCK_KEY,
            (string) time()
        ));

        $wpdb->suppress_errors($suppress);

        return (1 === $acquired);
    }

    protected static function release_lock() {

        global $wpdb;

        $wpdb->delete($wpdb->options, ['option_name' => self::LOCK_KEY], ['%s']);
    }

    protected static function record_failure() {
        $fails = (int) get_transient(self::FAIL_KEY) + 1;
        set_transient(self::FAIL_KEY, $fails, 15 * MINUTE_IN_SECONDS);

        if ($fails >= self::MAX_FAILS) {
            $minutes = (int) apply_filters('tis_auth_block_minutes', 15);
            set_transient(self::BLOCK_KEY, time() + ($minutes * MINUTE_IN_SECONDS), $minutes * MINUTE_IN_SECONDS);
            tis_log('warning', 'Shiprocket authentication locked out after repeated failures.', [
                'fails'          => $fails,
                'blocked_minutes'=> $minutes,
            ]);
        }
    }

    public static function clear_failures() {
        delete_transient(self::FAIL_KEY);
        delete_transient(self::BLOCK_KEY);
    }

    /**
     * Human-readable state for the settings screen.
     */
    public static function status() {

        $blocked = (int) get_transient(self::BLOCK_KEY);

        return [
            'configured' => self::has_credentials(),
            'source'     => self::credentials()['source'],
            'has_token'  => (bool) self::cached_token(),
            'fails'      => (int) get_transient(self::FAIL_KEY),
            'blocked_for'=> $blocked > time() ? ($blocked - time()) : 0,
        ];
    }
}
