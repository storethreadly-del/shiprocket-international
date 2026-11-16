<?php
/**
 * Minimal WordPress stubs for the standalone smoke harness.
 *
 * Deliberately NOT a WordPress test framework. The point is that the provider
 * layer can be exercised with no WordPress, no WooCommerce and no network — if
 * that ever stops being true, the providers have picked up a dependency that
 * would block reuse in the HIBOU Intelligence Engine (SCOPE.md §6, seam 3), and
 * this harness is where that shows up first.
 *
 * @package Threadly_Intl_Shipping
 */

define('ABSPATH', __DIR__ . '/');

define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

define('TIS_SR_API_BASE', 'https://apiv2.shiprocket.in/v1/external/');
define('TIS_VERSION', 'test');
define('TIS_CRON_HOOK', 'tis_warm_rates');

$GLOBALS['_options']    = [];
$GLOBALS['_transients'] = [];
$GLOBALS['_filters']    = [];
$GLOBALS['_logs']       = [];

function add_filter($hook, $cb, $priority = 10, $args = 1) {
    $GLOBALS['_filters'][$hook][] = $cb;
}
function apply_filters($hook, $value) {
    $extra = array_slice(func_get_args(), 2);
    foreach ($GLOBALS['_filters'][$hook] ?? [] as $cb) {
        $value = call_user_func_array($cb, array_merge([$value], $extra));
    }
    return $value;
}
function remove_all_filters($hook) {
    unset($GLOBALS['_filters'][$hook]);
}
function add_action($hook, $cb, $priority = 10, $args = 1) {}
function do_action($hook) {
    $GLOBALS['_actions'][] = ['hook' => $hook, 'args' => array_slice(func_get_args(), 1)];
}

function get_option($k, $default = false) {
    return array_key_exists($k, $GLOBALS['_options']) ? $GLOBALS['_options'][$k] : $default;
}
function update_option($k, $v, $autoload = null) {
    $GLOBALS['_options'][$k] = $v;
    return true;
}
/** add_option() is an INSERT: it must fail when the key already exists — that
 *  is precisely the property the login mutex relies on. */
function add_option($k, $v, $deprecated = '', $autoload = null) {
    if (array_key_exists($k, $GLOBALS['_options'])) {
        return false;
    }
    $GLOBALS['_options'][$k] = $v;
    return true;
}
function delete_option($k) {
    unset($GLOBALS['_options'][$k]);
    return true;
}

function get_transient($k) {
    if (!array_key_exists($k, $GLOBALS['_transients'])) {
        return false;
    }
    list($value, $expires) = $GLOBALS['_transients'][$k];
    if ($expires && $expires < time()) {
        unset($GLOBALS['_transients'][$k]);
        return false;
    }
    return $value;
}
function set_transient($k, $v, $ttl = 0) {
    $GLOBALS['_transients'][$k] = [$v, $ttl ? time() + $ttl : 0];
    return true;
}
function delete_transient($k) {
    unset($GLOBALS['_transients'][$k]);
    return true;
}

function sanitize_key($k) {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k));
}
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return $t; }
function _x($t, $c, $d = null) { return $t; }

function get_bloginfo($k) { return '6.0'; }
function home_url() { return 'https://example.test'; }
function is_wp_error($t) { return false; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function wp_kses($s, $allowed) { return $s; }

function tis_log($level, $message, $context = []) {
    $GLOBALS['_logs'][] = [$level, $message, $context];
}

$GLOBALS['_cron']    = [];
$GLOBALS['_actions'] = [];

function wp_clear_scheduled_hook($hook, $args = []) {
    unset($GLOBALS['_cron'][$hook]);
}
function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
    $GLOBALS['_cron'][$hook] = ['timestamp' => $timestamp, 'recurrence' => $recurrence];
    return true;
}
function wp_next_scheduled($hook, $args = []) {
    return isset($GLOBALS['_cron'][$hook]) ? $GLOBALS['_cron'][$hook]['timestamp'] : false;
}
