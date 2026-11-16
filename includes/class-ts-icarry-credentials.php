<?php
/**
 * iCarry.in credentials.
 *
 * iCarry issues a USERNAME and a generated API PASSWORD (My Account > API
 * Credentials). Two ways to supply them, in order of precedence:
 *
 *   1. Constants in wp-config.php (keeps the password out of the database):
 *        define('TIS_IC_USER',     '...');
 *        define('TIS_IC_PASSWORD', '...');
 *   2. The iCarry probe screen, stored in wp_options.
 *
 * NOT to be confused with iCarry.com, an unrelated Middle East company whose
 * WooCommerce plugin lives on wordpress.org (see SCOPE.md section 7).
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

class TS_ICarry_Credentials {

    const OPT_USER     = 'tis_ic_user';
    const OPT_PASSWORD = 'tis_ic_password';

    /** @return array { user, password, source } */
    public static function get() {

        if (defined('TIS_IC_USER') && defined('TIS_IC_PASSWORD') && TIS_IC_USER && TIS_IC_PASSWORD) {
            return ['user' => (string) TIS_IC_USER, 'password' => (string) TIS_IC_PASSWORD, 'source' => 'wp-config'];
        }

        return [
            'user'     => (string) get_option(self::OPT_USER, ''),
            'password' => (string) get_option(self::OPT_PASSWORD, ''),
            'source'   => 'settings',
        ];
    }

    public static function exists() {
        $c = self::get();
        return '' !== trim($c['user']) && '' !== trim($c['password']);
    }

    /** Every secret value that must never appear in output or logs. */
    public static function secrets() {
        $c = self::get();
        return array_values(array_filter([$c['password']], 'strlen'));
    }
}
