<?php
/**
 * DEPRECATED since 0.3.0 — kept as a thin back-compat shim.
 *
 * Shiprocket authentication moved into TS_Provider_Shiprocket (via
 * TS_Provider_Base), where it is shared with every other aggregator rather than
 * being Shiprocket-specific. This class remains so that any site-level or
 * third-party code calling TIS_Auth::* keeps working; it holds no logic of its
 * own and simply forwards to that provider.
 *
 * New code should use TS_Provider_Registry::active() — or
 * TS_Provider_Registry::get('shiprocket') when it specifically means Shiprocket.
 *
 * @package Threadly_Intl_Shipping
 * @deprecated 0.3.0
 */

if (!defined('ABSPATH')) exit;

class TIS_Auth {

    /**
     * Preserved for back-compat: v0.2.0 code (including this plugin's own
     * settings screen) referenced this constant directly to clear the token.
     */
    const TOKEN_KEY = 'tis_sr_token';
    const FAIL_KEY  = 'tis_sr_auth_fails';
    const BLOCK_KEY = 'tis_sr_auth_blocked';
    const LOCK_KEY  = 'tis_sr_auth_lock';

    const TOKEN_TTL = TS_Provider_Base::TOKEN_TTL;
    const MAX_FAILS = TS_Provider_Base::MAX_FAILS;
    const LOCK_TTL  = TS_Provider_Base::LOCK_TTL;

    /**
     * @return TS_Provider_Shiprocket|null
     */
    protected static function provider() {
        return TS_Provider_Registry::get('shiprocket');
    }

    public static function credentials() {
        $p = self::provider();
        return $p ? $p->credentials() : ['email' => '', 'password' => '', 'source' => 'settings'];
    }

    public static function has_credentials() {
        $p = self::provider();
        return $p ? $p->has_credentials() : false;
    }

    public static function cached_token() {
        $p = self::provider();
        return $p ? $p->cached_token() : false;
    }

    public static function token() {
        $p = self::provider();
        return $p ? $p->token() : ['error' => 'Shiprocket provider is not registered.'];
    }

    public static function test_connection() {
        $p = self::provider();
        return $p ? $p->test_connection() : ['error' => 'Shiprocket provider is not registered.'];
    }

    public static function login($force = false) {
        $p = self::provider();
        return $p ? $p->login($force) : ['error' => 'Shiprocket provider is not registered.'];
    }

    public static function forget() {
        $p = self::provider();
        if ($p) {
            $p->forget_token();
        }
    }

    public static function clear_failures() {
        $p = self::provider();
        if ($p) {
            $p->clear_failures();
        }
    }

    public static function status() {
        $p = self::provider();
        return $p ? $p->auth_status() : [
            'configured'  => false,
            'source'      => 'settings',
            'has_token'   => false,
            'fails'       => 0,
            'blocked_for' => 0,
        ];
    }
}
