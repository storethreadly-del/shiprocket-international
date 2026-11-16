<?php
/**
 * WooCommerce shipping method exposing Shiprocket international rates.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

add_filter('woocommerce_shipping_methods', function ($methods) {
    $methods['tis_shiprocket_intl'] = 'TIS_Shipping_Method';
    return $methods;
});

add_action('woocommerce_shipping_init', function () {

    if (class_exists('TIS_Shipping_Method')) {
        return;
    }

    class TIS_Shipping_Method extends WC_Shipping_Method {

        public function __construct($instance_id = 0) {
            $this->id                 = 'tis_shiprocket_intl';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = __('Shiprocket International', 'threadly-intl-shipping');
            $this->method_description = __('Live cross-border courier rates and transit times from Shiprocket X. Add this to a shipping zone covering your international destinations.', 'threadly-intl-shipping');
            $this->supports           = [
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            ];

            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', __('International Shipping', 'threadly-intl-shipping'));
            $this->enabled = $this->get_option('enabled', 'yes');

            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields() {
            $this->instance_form_fields = [
                'enabled' => [
                    'title'   => __('Enable', 'threadly-intl-shipping'),
                    'type'    => 'checkbox',
                    'label'   => __('Show Shiprocket international rates in this zone', 'threadly-intl-shipping'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title'       => __('Method title', 'threadly-intl-shipping'),
                    'type'        => 'text',
                    'description' => __('Group heading shown at checkout.', 'threadly-intl-shipping'),
                    'default'     => __('International Shipping', 'threadly-intl-shipping'),
                    'desc_tip'    => true,
                ],
                'markup_percent' => [
                    'title'             => __('Markup (%)', 'threadly-intl-shipping'),
                    'type'              => 'number',
                    'description'       => __('Added on top of the Shiprocket rate. Covers packaging, currency movement and duties handling.', 'threadly-intl-shipping'),
                    'default'           => '0',
                    'desc_tip'          => true,
                    'custom_attributes' => ['step' => '0.1', 'min' => '0'],
                ],
                'handling_flat' => [
                    'title'             => __('Flat handling fee', 'threadly-intl-shipping'),
                    'type'              => 'number',
                    'description'       => __('Fixed amount added to every international rate, in store currency.', 'threadly-intl-shipping'),
                    'default'           => '0',
                    'desc_tip'          => true,
                    'custom_attributes' => ['step' => '0.01', 'min' => '0'],
                ],
                'max_couriers' => [
                    'title'             => __('Max couriers shown', 'threadly-intl-shipping'),
                    'type'              => 'number',
                    'description'       => __('Cheapest first. Keeps checkout readable when Shiprocket returns a long list. 0 shows all.', 'threadly-intl-shipping'),
                    'default'           => '4',
                    'desc_tip'          => true,
                    'custom_attributes' => ['step' => '1', 'min' => '0'],
                ],
                'show_transit' => [
                    'title'   => __('Transit time', 'threadly-intl-shipping'),
                    'type'    => 'checkbox',
                    'label'   => __('Show estimated delivery days under each courier', 'threadly-intl-shipping'),
                    'default' => 'yes',
                ],
                'fallback_enabled' => [
                    'title'       => __('Fallback rate', 'threadly-intl-shipping'),
                    'type'        => 'checkbox',
                    'label'       => __('Show a flat rate when Shiprocket returns nothing', 'threadly-intl-shipping'),
                    'description' => __('Strongly recommended. A checkout with zero shipping options cannot be completed.', 'threadly-intl-shipping'),
                    'default'     => 'yes',
                ],
                'fallback_cost' => [
                    'title'             => __('Fallback amount', 'threadly-intl-shipping'),
                    'type'              => 'number',
                    'description'       => __('Charged per order when live rates are unavailable. Set this deliberately high enough to cover a worst-case shipment.', 'threadly-intl-shipping'),
                    'default'           => '2500',
                    'desc_tip'          => true,
                    'custom_attributes' => ['step' => '1', 'min' => '0'],
                ],
                'fallback_label' => [
                    'title'    => __('Fallback label', 'threadly-intl-shipping'),
                    'type'     => 'text',
                    'default'  => __('International Shipping', 'threadly-intl-shipping'),
                    'desc_tip' => true,
                ],
            ];
        }

        /**
         * Billable weight in kg: max(actual, volumetric), rounded up to a 0.5kg slab.
         *
         * International couriers bill on whichever is greater. Quoting on actual
         * weight alone systematically under-charges bulky-light shipments, which
         * is most of apparel.
         */
        public function get_billable_weight($package) {

            $actual     = 0.0;
            $volumetric = 0.0;

            $weight_unit = get_option('woocommerce_weight_unit', 'kg');
            $dim_unit    = get_option('woocommerce_dimension_unit', 'cm');
            $divisor     = (float) apply_filters('tis_volumetric_divisor', 5000);

            $contents = isset($package['contents']) ? (array) $package['contents'] : [];

            foreach ($contents as $item) {

                if (empty($item['data']) || !is_object($item['data'])) {
                    continue;
                }

                $product = $item['data'];
                $qty     = isset($item['quantity']) ? (int) $item['quantity'] : 1;

                if (method_exists($product, 'needs_shipping') && !$product->needs_shipping()) {
                    continue;
                }

                $actual += wc_get_weight((float) $product->get_weight(), 'kg', $weight_unit) * $qty;

                $l = wc_get_dimension((float) $product->get_length(), 'cm', $dim_unit);
                $w = wc_get_dimension((float) $product->get_width(), 'cm', $dim_unit);
                $h = wc_get_dimension((float) $product->get_height(), 'cm', $dim_unit);

                if ($l > 0 && $w > 0 && $h > 0 && $divisor > 0) {
                    $volumetric += (($l * $w * $h) / $divisor) * $qty;
                }
            }

            $billable = max($actual, $volumetric);

            // Round up to the next 0.5kg slab: matches courier billing and makes
            // the rate cache hit for every order of a similar size.
            $billable = ceil(max($billable, 0.01) * 2) / 2;

            return max(0.5, $billable);
        }

        protected function apply_markup($rate) {
            $markup   = (float) $this->get_option('markup_percent', 0);
            $handling = (float) $this->get_option('handling_flat', 0);
            return round(($rate * (1 + ($markup / 100))) + $handling, 2);
        }

        protected function add_fallback_rate($package) {

            if ('yes' !== $this->get_option('fallback_enabled', 'yes')) {
                return;
            }

            $cost = (float) $this->get_option('fallback_cost', 0);
            if ($cost <= 0) {
                return;
            }

            $country = isset($package['destination']['country']) ? $package['destination']['country'] : '';
            tis_log('notice', 'Serving fallback international rate — live Shiprocket rates unavailable.', ['country' => $country, 'fallback_cost' => $cost]);

            $this->add_rate([
                'id'        => $this->get_rate_id('fallback'),
                'label'     => $this->get_option('fallback_label', __('International Shipping', 'threadly-intl-shipping')),
                'cost'      => $cost,
                'package'   => $package,
                'meta_data' => ['tis_fallback' => 'yes'],
            ]);
        }

        public function calculate_shipping($package = []) {

            $provider = TS_Provider_Registry::active();

            if (!$provider) {
                // The plugin failed to register any provider at all. Fall back
                // rather than leaving checkout with no shipping options.
                $this->add_fallback_rate($package);
                return;
            }

            $destination = [
                'country'  => isset($package['destination']['country']) ? $package['destination']['country'] : '',
                'postcode' => isset($package['destination']['postcode']) ? $package['destination']['postcode'] : '',
            ];

            // No address yet, or a lane this provider does not serve. Whether
            // domestic India is in scope is a property of the active provider,
            // not a constant of the plugin: Shiprocket declines it because the
            // official Shiprocket plugin owns India, while iCarry will serve it.
            if (!$provider->serves($destination)) {
                return;
            }

            $weight = $this->get_billable_weight($package);

            // International shipments are prepaid, so COD is not applicable.
            // Domestic lanes will pass the real flag here once a provider that
            // serves them is active (SCOPE.md §4).
            $cod = (bool) apply_filters('ts_rate_request_cod', false, $package, $provider);

            $rates = $provider->rates($destination, $weight, [
                'cod'   => $cod,
                // Used only by a provider whose price depends on declared value.
                'value' => isset($package['contents_cost']) ? (float) $package['contents_cost'] : 0.0,
            ]);

            if (empty($rates)) {
                $this->add_fallback_rate($package);
                return;
            }

            $max = (int) $this->get_option('max_couriers', 4);
            if ($max > 0) {
                $rates = array_slice($rates, 0, $max);
            }

            $show_transit = ('yes' === $this->get_option('show_transit', 'yes'));

            foreach ($rates as $r) {

                $label = $r['courier_name'];

                if ($show_transit && !empty($r['transit_days'])) {
                    $label .= sprintf(
                        /* translators: %s: number of days */
                        __(' — %s days', 'threadly-intl-shipping'),
                        esc_html((string) $r['transit_days'])
                    );
                }

                $this->add_rate([
                    'id'        => $this->get_rate_id(sanitize_key($r['courier_id'])),
                    'label'     => $label,
                    'cost'      => $this->apply_markup($r['rate']),
                    'package'   => $package,
                    'meta_data' => [
                        'tis_courier_id'      => $r['courier_id'],
                        'tis_courier_name'    => $r['courier_name'],
                        'tis_transit_days'    => $r['transit_days'],
                        'tis_edd'             => $r['edd'],
                        'tis_billable_weight' => $weight,
                        'tis_base_rate'       => $r['rate'],
                        // Which aggregator quoted this rate. Stored so a
                        // shipment keeps syncing against the aggregator it was
                        // booked with, even after the active provider changes
                        // (SCOPE.md §3).
                        'tis_provider'        => isset($r['provider']) ? $r['provider'] : $provider->id(),
                        'tis_service_type'    => isset($r['service_type']) ? $r['service_type'] : '',
                    ],
                ]);
            }
        }
    }
});
