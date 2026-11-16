<?php
/**
 * The aggregator contract.
 *
 * One interface, one implementation per aggregator (Shiprocket, iCarry). The
 * shipping method, the warmer, the checkout display and the settings screen are
 * written against this and against the normalised rate row below; none of them
 * knows either vendor's API shape. See SCOPE.md §2.
 *
 * ---------------------------------------------------------------------------
 * NORMALISED RATE ROW
 *
 * Every provider returns rows of exactly this shape from rates() and request().
 * The first five keys are carried over verbatim from v0.2.0 so that existing
 * code and stored rate meta keep working; the last three are new in 0.3.0.
 *
 *   courier_id     string  Provider's courier identifier, or a slug of the name.
 *   courier_name   string  Display name.
 *   rate           float   Pre-markup cost in store currency.
 *   transit_days   mixed   Estimated days, or null.
 *   edd            string  Expected delivery date, or null.
 *   provider       string  Provider id that produced the row. Needed because a
 *                          shipment must keep syncing against the aggregator it
 *                          was booked with, even after the active provider is
 *                          switched (SCOPE.md §3).
 *   service_type   string  e.g. surface/air/express, or '' when not reported.
 *   cod_available  bool    Whether this courier will collect on delivery.
 * ---------------------------------------------------------------------------
 *
 * DESTINATION ARRAY
 *
 * Rating takes a destination array rather than a bare country, because iCarry
 * rates domestic Indian lanes at pincode level while international rating is
 * country level (SCOPE.md §4):
 *
 *   [ 'country' => 'US', 'postcode' => '' ]
 *
 * A provider uses only the parts it needs, and owns its own cache-key shape, so
 * a country-level provider is not forced into pincode-level cache churn.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

interface TS_Provider {

    /* -----------------------------------------------------------------
     * Identity and capability
     * ----------------------------------------------------------------- */

    /** @return string Stable machine id, e.g. 'shiprocket'. */
    public function id();

    /** @return string Human label for the admin UI. */
    public function label();

    /**
     * What this aggregator can actually do for us.
     *
     * The shipping method consults this instead of hardcoding vendor rules —
     * which is how "Shiprocket does not serve domestic India here" stops being
     * an `if ('IN' === $country)` buried in calculate_shipping() and becomes a
     * property of the provider.
     *
     * @return array { domestic: bool, international: bool, order_sync: bool }
     */
    public function capabilities();

    /**
     * @param string $capability One of the capabilities() keys.
     * @return bool
     */
    public function supports($capability);

    /**
     * Whether this provider can quote the given destination at all, by
     * capability. Does not perform any I/O.
     *
     * @param array $destination
     * @return bool
     */
    public function serves(array $destination);

    /* -----------------------------------------------------------------
     * Credentials and authentication
     *
     * Each provider owns its own credentials, token cache, failure counter and
     * lockout state. That isolation is deliberate and predates this interface:
     * an aggregator's failed-login lockout applies per API user, so a problem
     * with one integration's credentials must not be able to block the other,
     * nor block checkout.
     * ----------------------------------------------------------------- */

    /** @return bool */
    public function has_credentials();

    /** @return string Where the credentials came from, e.g. 'wp-config'|'settings'. */
    public function credential_source();

    /**
     * Cached token only — must never perform a login.
     *
     * This is what the checkout path uses. A login is a blocking HTTP round
     * trip and must never happen while a customer waits for shipping rates.
     *
     * @return string|false
     */
    public function cached_token();

    /**
     * Token for cron/admin context, logging in if necessary.
     *
     * @return string|array Token string, or array with 'error'.
     */
    public function token();

    /**
     * Explicit, admin-initiated credential check. Bypasses the cached token but
     * still goes through the same lockout machinery as any other login.
     *
     * @return string|array Token string, or array with 'error'.
     */
    public function test_connection();

    /** Drop the cached token (401 handling, or the admin's "clear token"). */
    public function forget_token();

    /** Reset the consecutive-failure counter and any active lockout. */
    public function clear_failures();

    /**
     * Human-readable auth state for the settings screen.
     *
     * @return array { configured: bool, source: string, has_token: bool,
     *                 fails: int, blocked_for: int }
     */
    public function auth_status();

    /* -----------------------------------------------------------------
     * Rating
     * ----------------------------------------------------------------- */

    /**
     * Main entry point for the shipping method. Cache first, optionally falling
     * through to a short live call. Must never throw and never block longer
     * than the provider's configured frontend timeout.
     *
     * @param array $destination See DESTINATION ARRAY above.
     * @param float $weight      Billable weight in kg.
     * @param array $opts        { cod: bool, allow_live: bool }
     * @return array Normalised rate rows, possibly empty.
     */
    public function rates(array $destination, $weight, array $opts = []);

    /**
     * One live HTTP call, bypassing the cache. Used by the warmer and by the
     * admin probe, which needs the raw payload to pin down an undocumented
     * response envelope.
     *
     * @param array  $destination
     * @param float  $weight
     * @param array  $opts
     * @param string $token
     * @param float  $timeout
     * @return array { rates: array, raw: mixed, error: string, url: string }
     */
    public function request(array $destination, $weight, array $opts, $token, $timeout = 20);

    /**
     * The lanes this provider wants kept warm by cron.
     *
     * Returned rather than assumed, because the two providers have genuinely
     * different warming economics: country-level international rating warms
     * exhaustively, pincode-level domestic rating cannot (SCOPE.md §4).
     *
     * @return array List of [ 'destination' => array, 'weight' => float, 'cod' => bool ].
     */
    public function warm_lanes();

    /* -----------------------------------------------------------------
     * Cache and circuit breaker
     * ----------------------------------------------------------------- */

    /** @return array|false Normalised rows, or false on miss. */
    public function get_cached(array $destination, $weight, array $opts = []);

    /** @param int|null $ttl Seconds; provider default when null. */
    public function set_cached(array $destination, $weight, array $opts, $rates, $ttl = null);

    /** Drop every cached rate for this provider. */
    public function flush_cache();

    /** @return bool */
    public function circuit_is_open();

    /** Close the breaker. */
    public function reset_circuit();
}
