<?php
/**
 * Admin-only WordPress stubs, for rendering the settings screen headlessly.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

function current_user_can($cap) { return true; }
function wp_die($msg) { throw new RuntimeException('wp_die: ' . $msg); }
function add_submenu_page() { return ''; }

function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($t) { return (string) $t; }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_html_e($t, $d = null) { echo esc_html($t); }
function esc_attr_e($t, $d = null) { echo esc_attr($t); }
function _e($t, $d = null) { echo $t; }
function esc_attr__($t, $d = null) { return esc_attr($t); }
function esc_js($t) { return str_replace(["'", "\n"], ["\\'", ''], (string) $t); }
function esc_html_x($t, $c, $d = null) { return esc_html($t); }

function checked($a, $b = true, $echo = true) {
    $out = ((bool) $a === (bool) $b) ? ' checked="checked"' : '';
    if ($echo) { echo $out; }
    return $out;
}
function selected($a, $b = true, $echo = true) {
    $out = ($a == $b) ? ' selected="selected"' : '';
    if ($echo) { echo $out; }
    return $out;
}
function disabled($a, $b = true, $echo = true) {
    $out = ($a == $b) ? ' disabled="disabled"' : '';
    if ($echo) { echo $out; }
    return $out;
}

function wp_nonce_field($action = '', $name = '_wpnonce', $referer = true, $echo = true) {
    $out = '<input type="hidden" name="' . esc_attr($name) . '" value="testnonce">';
    if ($echo) { echo $out; }
    return $out;
}
function check_admin_referer($action = -1, $name = '_wpnonce') { return true; }

function wp_unslash($v) { return $v; }
function sanitize_email($e) { return (string) $e; }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function human_time_diff($from, $to = null) { return '5 minutes'; }
