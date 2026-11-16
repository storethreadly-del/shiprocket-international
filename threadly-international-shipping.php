<?php
/**
 * Plugin Name: Threadly International Shipping
 * Plugin URI: https://www.hibou.in
 * Description: Live cross-border courier rates, transit times and EDD at WooCommerce checkout, served by a pluggable shipping aggregator (Shiprocket X, with iCarry to follow).
 * Version: 0.3.0-dev
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
 * - Rates are served by a PLUGGABLE PROVIDER. Each aggregator implements
 *   TS_Provider (includes/providers/), and exactly one provider is active at a
 *   time, resolved by TS_Provider_Registry::active(). Nothing above the provider
 *   layer — the shipping method, the warmer, the checkout display — knows any
 *   vendor's API shape; they are written against the normalised rate row
 *   documented in includes/providers/interface-ts-provider.php. See SCOPE.md.
 * - Fully independent of the HIBOU Intelligence Engine. Each provider
 *   authenticates with its OWN API user, on purpose: an aggregator's
 *   failed-login lockout applies per API user, so a problem with HIBOU's
 *   credentials cannot block checkout, and a problem here cannot interrupt
 *   HIBOU's order sync. Create a dedicated API user per aggregator and enter it
 *   under WooCommerce > Intl Shipping.
 * - Checkout never performs a blocking login. It reads the already-cached token
 *   or serves cached/fallback rates. Authentication only happens in cron/admin
 *   context, via the provider's token().
 * - TIS_Auth and TIS_API remain as deprecated back-compat shims over the
 *   Shiprocket provider; new code should use TS_Provider_Registry.
 */

if (!defined('ABSPATH')) exit;

define('TIS_VERSION', '0.3.0-dev');
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

    // Order matters: the seams and the provider contract load before the
    // providers, the providers before the registry, and the deprecated shims
    // last — they reference TS_Provider_Base constants at class-definition time.
    require_once TIS_PATH . 'includes/class-ts-http.php';
    require_once TIS_PATH . 'includes/class-ts-logger.php';
    require_once TIS_PATH . 'includes/providers/interface-ts-provider.php';
    require_once TIS_PATH . 'includes/providers/abstract-class-ts-provider-base.php';
    require_once TIS_PATH . 'includes/providers/class-ts-provider-shiprocket.php';
    require_once TIS_PATH . 'includes/class-ts-provider-registry.php';

    require_once TIS_PATH . 'includes/class-tis-auth.php';
    require_once TIS_PATH . 'includes/class-tis-api.php';

    require_once TIS_PATH . 'includes/class-tis-shipping-method.php';
    require_once TIS_PATH . 'includes/rate-warmer.php';
    require_once TIS_PATH . 'includes/checkout-display.php';

    if (is_admin()) {
        require_once TIS_PATH . 'includes/settings-page.php';
    }

    TS_Provider_Registry::boot();

    $active = TS_Provider_Registry::active();

    if ($active && !$active->has_credentials()) {
        add_action('admin_notices', function () {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }
            $active = TS_Provider_Registry::active();

            if (!$active) {
                return;
            }

            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Threadly Shipping', 'threadly-intl-shipping'),
                wp_kses(
                    sprintf(
                        /* translators: 1: provider label, 2: settings URL */
                        __('has no %1$s API user configured yet. Add one under <a href="%2$s">WooCommerce &gt; Intl Shipping</a> (use a dedicated API user, separate from any other integration on that account). Until then, only the fallback rate will be shown at checkout.', 'threadly-intl-shipping'),
                        esc_html($active->label()),
                        esc_url(admin_url('admin.php?page=tis-settings'))
                    ),
                    ['a' => ['href' => []]]
                )
            );
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
