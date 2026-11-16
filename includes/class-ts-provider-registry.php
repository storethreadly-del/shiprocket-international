<?php
/**
 * Provider registry — resolves which aggregator is currently serving rates.
 *
 * Phase 1 registers Shiprocket only, so active() always resolves to it and
 * behaviour is unchanged. The option and filter are read here already so that
 * phase 2 adds the settings UI and the switch lifecycle without touching this
 * resolution logic again.
 *
 * Mutual exclusion is by construction, not by validation: there is a single
 * active provider id, so there is no state in which two aggregators both quote
 * at checkout (SCOPE.md §3).
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

class TS_Provider_Registry {

    /** Option holding the active provider id. */
    const ACTIVE_OPTION = 'ts_active_provider';

    /** Fallback when nothing is configured. */
    const DEFAULT_PROVIDER = 'shiprocket';

    /** @var TS_Provider[] Keyed by provider id. */
    protected static $providers = [];

    /** @var bool */
    protected static $booted = false;

    /**
     * Instantiate the providers.
     *
     * The HTTP client and logger are built here and injected, which is the
     * single place to swap either one — for tests, or for the HIE tool reusing
     * these adapters outside WordPress.
     */
    public static function boot() {

        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $http = new TS_Http_WP();

        // Do not clobber an id that is already registered. That lets a site
        // override a built-in provider by registering its own earlier, and lets
        // the smoke harness inject a provider with a stubbed HTTP client.
        if (!isset(self::$providers['shiprocket'])) {
            self::register(new TS_Provider_Shiprocket($http, new TS_Logger_WC('tis-shiprocket')));
        }

        self::register_optional_providers($http);

        /**
         * Register additional providers.
         *
         * @param TS_Provider_Registry $registry_class Not an instance — call
         *        TS_Provider_Registry::register() from the callback.
         * @param TS_Http              $http
         */
        do_action('ts_register_providers', $http);
    }

    /**
     * Providers that only appear once they can be used.
     *
     * iCarry is registered only when its credentials exist. That keeps a store that
     * has never configured it from seeing a provider it cannot use, and from being
     * able to switch checkout onto one that has never authenticated.
     *
     * Public so the iCarry screen can call it straight after saving credentials,
     * when the registry has already booted without it.
     */
    public static function register_optional_providers(?TS_Http $http = null) {

        if (isset(self::$providers['icarry'])
            || !class_exists('TS_Provider_ICarry')
            || !class_exists('TS_ICarry_Credentials')
            || !TS_ICarry_Credentials::exists()) {
            return;
        }

        self::register(new TS_Provider_ICarry($http ?: new TS_Http_WP(), new TS_Logger_WC('tis-icarry')));
    }

    public static function register(TS_Provider $provider) {
        self::$providers[$provider->id()] = $provider;
    }

    /**
     * Drop every registration and re-arm boot().
     *
     * For tests only — the smoke harness uses this to install a provider with a
     * stubbed HTTP client before anything can boot the real one.
     */
    public static function reset() {
        self::$providers = [];
        self::$booted    = false;
    }

    /**
     * @return TS_Provider[] Keyed by id.
     */
    public static function all() {
        self::boot();
        return self::$providers;
    }

    /**
     * @param string $id
     * @return TS_Provider|null
     */
    public static function get($id) {
        self::boot();
        return isset(self::$providers[$id]) ? self::$providers[$id] : null;
    }

    public static function exists($id) {
        return null !== self::get($id);
    }

    /**
     * The active provider's id.
     *
     * Filterable so the HIE tool, a staging constant, or a future per-zone rule
     * can override the stored setting without a code change.
     *
     * @return string
     */
    public static function active_id() {

        self::boot();

        $id = (string) get_option(self::ACTIVE_OPTION, self::DEFAULT_PROVIDER);

        /** @param string $id Active provider id. */
        $id = (string) apply_filters('ts_active_provider', $id);

        if (!isset(self::$providers[$id])) {

            // A stored or filtered id naming a provider that is not registered
            // must not take checkout down. Fall back, and say so once.
            if ('' !== $id) {
                tis_log('warning', 'Configured shipping provider is not registered; falling back.', [
                    'requested' => $id,
                    'fallback'  => self::DEFAULT_PROVIDER,
                ]);
            }

            $id = self::DEFAULT_PROVIDER;
        }

        return $id;
    }

    /**
     * The active provider.
     *
     * @return TS_Provider|null Null only if nothing at all is registered, which
     *         would mean the plugin failed to load its own provider.
     */
    public static function active() {
        return self::get(self::active_id());
    }

    /**
     * Providers that have usable credentials.
     *
     * @return TS_Provider[]
     */
    public static function configured() {
        return array_filter(self::all(), function (TS_Provider $p) {
            return $p->has_credentials();
        });
    }

    /* =================================================================
     * Switching
     * ================================================================= */

    /**
     * Make $id the active provider and stand the outgoing one down.
     *
     * Switching changes what customers are quoted, so the caller is expected to
     * have confirmed with the operator first; this method does the work and
     * reports what happened rather than asking.
     *
     * What happens, in order:
     *   1. Refuse unless the target is registered. A typo must not leave the
     *      store with no provider.
     *   2. Store the new id.
     *   3. Stand the outgoing provider down: drop its rate cache, its token and
     *      its circuit-breaker state. Credentials are deliberately LEFT INTACT
     *      so switching back does not mean re-entering them.
     *   4. Bring the warm cron forward, so the incoming provider's cache fills
     *      in minutes instead of whenever the daily event next happens to run.
     *      Without this, a switch leaves checkout on fallback rates for up to a
     *      day.
     *   5. Fire ts_provider_switched so other code — the HIE tool included —
     *      can react.
     *
     * @param string $id Target provider id.
     * @return array {
     *     @type bool     $ok       Whether the switch was applied.
     *     @type bool     $changed  False when the target was already active.
     *     @type string   $from     Previous provider id.
     *     @type string   $to       New provider id.
     *     @type string   $error    Present only when $ok is false.
     *     @type string[] $warnings Non-fatal problems the operator should see.
     * }
     */
    public static function activate($id) {

        self::boot();

        $id = (string) $id;
        $to = self::get($id);

        if (!$to) {
            return [
                'ok'       => false,
                'changed'  => false,
                'error'    => sprintf('Unknown shipping provider "%s".', $id),
                'warnings' => [],
            ];
        }

        $from_id = self::active_id();

        if ($from_id === $id) {
            return [
                'ok'       => true,
                'changed'  => false,
                'from'     => $from_id,
                'to'       => $id,
                'warnings' => [],
            ];
        }

        $from = self::get($from_id);

        update_option(self::ACTIVE_OPTION, $id);

        if ($from) {
            // Drop the outgoing provider's cached rates, token and breaker
            // state. Its credentials stay put.
            $from->flush_cache();
            $from->forget_token();
            $from->reset_circuit();
        }

        self::reschedule_warm();

        // The incoming provider may need its token kept fresh in the background
        // (iCarry's lives for an hour); the outgoing one may no longer.
        if (function_exists('tis_sync_token_refresh_schedule')) {
            tis_sync_token_refresh_schedule();
        }

        $warnings = [];

        if (!$to->has_credentials()) {
            $warnings[] = sprintf(
                '%s has no API credentials configured yet, so checkout will serve the fallback rate until they are added.',
                $to->label()
            );
        }

        // A filter on ts_active_provider outranks the stored option, so a
        // forced override would silently undo what the operator just did.
        $effective = self::active_id();
        if ($effective !== $id) {
            $warnings[] = sprintf(
                'The setting was saved, but a ts_active_provider filter is forcing "%s", so that is what checkout will use.',
                $effective
            );
        }

        tis_log('notice', 'Active shipping provider switched.', [
            'from'     => $from_id,
            'to'       => $id,
            'warnings' => $warnings,
        ]);

        /**
         * Fires after the active provider changes.
         *
         * @param string $from_id Previous provider id.
         * @param string $id      New provider id.
         */
        do_action('ts_provider_switched', $from_id, $id);

        return [
            'ok'       => true,
            'changed'  => true,
            'from'     => $from_id,
            'to'       => $id,
            'warnings' => $warnings,
        ];
    }

    /**
     * Bring the daily rate warm forward so a freshly activated provider does
     * not sit on a cold cache until the next scheduled run.
     */
    protected static function reschedule_warm() {

        if (!defined('TIS_CRON_HOOK')) {
            return;
        }

        wp_clear_scheduled_hook(TIS_CRON_HOOK);
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', TIS_CRON_HOOK);
    }
}
