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

add_action('plugins_loaded', function () {

    if (!class_exists('WooCommerce')) {
        return;
    }

    require_once TIS_PATH . 'includes/class-tis-auth.php';
    require_once TIS_PATH . 'includes/class-tis-api.php';
    require_once TIS_PATH . 'includes/class-tis-shipping-method.php';
    require_once TIS_PATH . 'includes/rate-warmer.php';
    require_once TIS_PATH . 'includes/checkout-display.php';

    if (is_admin()) {
        require_once TIS_PATH . 'includes/settings-page.php';
    }

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

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled(TIS_CRON_HOOK)) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', TIS_CRON_HOOK);
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(TIS_CRON_HOOK);
});
