<?php
/**
 * Plugin Name: Threadly International Shipping
 * Plugin URI: https://www.hibou.in
 * Description: Live Shiprocket X cross-border courier rates, transit times and EDD at WooCommerce checkout.
 * Version: 0.2.0
 * Author: Hibou Printtech Private Ltd
 * Author URI: https://www.hibou.in
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: threadly-intl-shipping
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 5.0
 * WC tested up to: 10.6
 *
 * @package Threadly_Intl_Shipping
 *
 * Architecture notes:
 * - Fully independent of the HIBOU Intelligence Engine. This plugin authenticates
 *   with its OWN Shiprocket API user (see includes/class-tis-auth.php), on
 *   purpose: Shiprocket's failed-login lockout applies per API user, so a
 *   problem with HIBOU's credentials cannot block checkout, and a problem here
 *   cannot interrupt HIBOU's order sync. Create a second API user in the
 *   Shiprocket dashboard (Settings > API > Configure) and enter it under
 *   WooCommerce > Intl Shipping.
 * - Checkout never performs a blocking login. It reads the already-cached token
 *   or serves cached/fallback rates. Authentication only happens in cron/admin
 *   context, via TIS_Auth::token().
 */

if (!defined('ABSPATH')) exit;

define('TIS_VERSION', '0.2.0');
define('TIS_PATH', plugin_dir_path(__FILE__));
define('TIS_CRON_HOOK', 'tis_warm_rates');

/** Bumped when stored data needs a one-time migration. See tis_maybe_migrate(). */
define('TIS_DB_VERSION', 1);

if (!defined('TIS_SR_API_BASE')) {
    define('TIS_SR_API_BASE', 'https://apiv2.shiprocket.in/v1/external/');
}

/**
 * Shared diagnostic logger — everything lands in WooCommerce > Status > Logs
 * under source "tis-shiprocket". $context must never contain a password,
 * token, or Authorization header; log what happened, not the secret that let
 * it happen.
 *
 * @param string $level   emergency|alert|critical|error|warning|notice|info|debug
 * @param string $message
 * @param array  $context
 */
function tis_log($level, $message, $context = []) {
    if (!function_exists('wc_get_logger')) {
        return;
    }
    $context['source'] = 'tis-shiprocket';
    wc_get_logger()->log($level, $message, $context);
}

/**
 * One-time data migrations.
 *
 * v1: force this plugin's options off autoload. Saved without an explicit
 * autoload argument, WordPress resolves the value to 'auto', which autoloads —
 * meaning the Shiprocket password sat in alloptions on every request. This
 * cannot be repaired with update_option(): that function returns early when the
 * value is unchanged and never reaches its autoload handling, and the password
 * field is intentionally left blank on re-save, so the row would never be
 * rewritten. A direct UPDATE is the only thing that fixes an existing install.
 */
function tis_maybe_migrate() {

    if ((int) get_option('tis_db_version', 0) >= TIS_DB_VERSION) {
        return;
    }

    global $wpdb;

    $names = [
        'tis_sr_email', 'tis_sr_password', 'tis_pickup_pincode',
        'tis_warm_countries', 'tis_warm_slabs', 'tis_cache_hours',
        'tis_last_warm', 'tis_last_success', 'tis_last_error',
    ];

    $placeholders = implode(',', array_fill(0, count($names), '%s'));

    $changed = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET autoload = 'off'
         WHERE option_name IN ($placeholders) AND autoload NOT IN ('off', 'no')",
        $names
    ));

    if ($changed) {
        // alloptions still holds the rows we just demoted.
        wp_cache_delete('alloptions', 'options');
        tis_log('info', 'Moved plugin options off autoload.', ['rows' => (int) $changed]);
    }

    // Autoloaded deliberately: a tiny integer read on every request is cheaper
    // than the uncached lookup this guard would otherwise cost.
    update_option('tis_db_version', TIS_DB_VERSION, true);
}

add_action('plugins_loaded', function () {

    if (!class_exists('WooCommerce')) {
        return;
    }

    tis_maybe_migrate();

    require_once TIS_PATH . 'includes/class-tis-auth.php';
    require_once TIS_PATH . 'includes/class-tis-api.php';
    require_once TIS_PATH . 'includes/class-tis-shipping-method.php';
    require_once TIS_PATH . 'includes/rate-warmer.php';
    require_once TIS_PATH . 'includes/checkout-display.php';

    if (is_admin()) {
        require_once TIS_PATH . 'includes/settings-page.php';
    }

    // Shiprocket has blocked the API user. Surface this on every admin screen:
    // the symptom an admin actually notices is "international rates stopped
    // appearing", and without this they have no way to connect that to a
    // lockout that resolves itself on a timer.
    add_action('admin_notices', function () {

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $remaining = TIS_Auth::blocked_for();
        if ($remaining <= 0) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>Threadly International Shipping:</strong> '
            . esc_html__('Shiprocket has temporarily blocked this API user after repeated failed logins. Login attempts are paused automatically, and checkout is serving your fallback rate meanwhile.', 'threadly-intl-shipping')
            . ' ' . sprintf(
                /* translators: %s: minutes remaining */
                esc_html__('Retrying in about %s minute(s).', 'threadly-intl-shipping'),
                esc_html((string) max(1, (int) ceil($remaining / MINUTE_IN_SECONDS)))
            )
            . ' <a href="' . esc_url(admin_url('admin.php?page=tis-settings')) . '">'
            . esc_html__('View details', 'threadly-intl-shipping') . '</a>'
            . '</p><p><em>' . esc_html__('If this keeps happening, check that no other integration (for example HIBOU) is using the same Shiprocket API user — the lockout applies per API user.', 'threadly-intl-shipping')
            . '</em></p></div>';
    });

    if (!TIS_Auth::has_credentials()) {
        add_action('admin_notices', function () {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }
            echo '<div class="notice notice-warning"><p><strong>Threadly International Shipping</strong> has no Shiprocket '
                . 'API user configured yet. Add one under <a href="'
                . esc_url(admin_url('admin.php?page=tis-settings')) . '">WooCommerce &gt; Intl Shipping</a> '
                . '(use a dedicated API user, separate from any other Shiprocket integration on this account). '
                . 'Until then, only the fallback rate will be shown at checkout.</p></div>';
        });
    }
}, 20);

/**
 * "Settings" shortcut on the Plugins screen, so setup is one click from
 * activation rather than a hunt through the WooCommerce menu.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('admin.php?page=tis-settings')),
        esc_html__('Settings', 'threadly-intl-shipping')
    ));
    return $links;
});

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled(TIS_CRON_HOOK)) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', TIS_CRON_HOOK);
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(TIS_CRON_HOOK);
});
