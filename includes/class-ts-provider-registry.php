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

        /**
         * Register additional providers.
         *
         * @param TS_Provider_Registry $registry_class Not an instance — call
         *        TS_Provider_Registry::register() from the callback.
         * @param TS_Http              $http
         */
        do_action('ts_register_providers', $http);
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
}
