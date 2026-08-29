<?php
/**
 * Courier detail rendering under each rate on cart and checkout.
 *
 * WooCommerce only renders a rate's label and cost. Anything richer — transit
 * time, expected delivery date, billable weight — has to be printed via
 * woocommerce_after_shipping_rate.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

/**
 * Print courier metadata beneath our own rates only.
 *
 * @param WC_Shipping_Rate $rate  Rate being rendered.
 * @param int              $index Position in the list.
 */
function tis_render_rate_details($rate, $index) {

    if (!is_object($rate) || !method_exists($rate, 'get_method_id')) {
        return;
    }

    if ('tis_shiprocket_intl' !== $rate->get_method_id()) {
        return;
    }

    $meta = $rate->get_meta_data();

    if (!empty($meta['tis_fallback'])) {
        echo '<div class="tis-rate-meta tis-rate-meta--fallback">'
            . esc_html__('Live courier rates are temporarily unavailable — this is our standard international rate.', 'threadly-intl-shipping')
            . '</div>';
        return;
    }

    $bits = [];

    if (!empty($meta['tis_edd'])) {
        $bits[] = sprintf(
            /* translators: %s: expected delivery date */
            esc_html__('Est. delivery %s', 'threadly-intl-shipping'),
            esc_html((string) $meta['tis_edd'])
        );
    } elseif (!empty($meta['tis_transit_days'])) {
        $bits[] = sprintf(
            /* translators: %s: number of transit days */
            esc_html__('%s days in transit', 'threadly-intl-shipping'),
            esc_html((string) $meta['tis_transit_days'])
        );
    }

    if (!empty($meta['tis_billable_weight'])) {
        $bits[] = sprintf(
            /* translators: %s: billable weight in kg */
            esc_html__('Billable weight %s kg', 'threadly-intl-shipping'),
            esc_html(number_format((float) $meta['tis_billable_weight'], 2))
        );
    }

    if (empty($bits)) {
        return;
    }

    echo '<div class="tis-rate-meta">' . implode(' &middot; ', $bits) . '</div>';
}
add_action('woocommerce_after_shipping_rate', 'tis_render_rate_details', 10, 2);

/**
 * Minimal styling for the detail line. Inline to avoid an extra HTTP request
 * for roughly six declarations.
 */
add_action('wp_enqueue_scripts', function () {

    if (!function_exists('is_checkout') || (!is_checkout() && !is_cart())) {
        return;
    }

    $css = '.tis-rate-meta{display:block;margin:2px 0 6px 0;font-size:.85em;line-height:1.4;opacity:.75;}'
         . '.tis-rate-meta--fallback{font-style:italic;}';

    wp_register_style('tis-inline', false, [], TIS_VERSION);
    wp_enqueue_style('tis-inline');
    wp_add_inline_style('tis-inline', $css);
});
