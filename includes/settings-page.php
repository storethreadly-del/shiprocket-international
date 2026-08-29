<?php
/**
 * Admin screen: credentials, connection status, and secondary diagnostics.
 *
 * The store owner should only need to do two things here — enter Shiprocket
 * credentials and press one button. Everything technical (tokens, circuit
 * breaker state, response envelopes) is either handled automatically or
 * written to the WooCommerce log; none of it is required reading.
 *
 * Saving runs a real end-to-end check: authenticate, then fetch one live rate.
 * The response shape is logged so the API contract can be verified from
 * WooCommerce > Status > Logs without anyone running a script by hand.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        __('International Shipping', 'threadly-intl-shipping'),
        __('Intl Shipping', 'threadly-intl-shipping'),
        'manage_woocommerce',
        'tis-settings',
        'tis_render_settings_page'
    );
});

/**
 * Summarise a rate response for the log, so the API contract can be checked
 * from WooCommerce > Status > Logs without anyone running a script.
 *
 * Records only structure and non-secret values: the token travels in a header
 * and is never part of this data.
 *
 * @return array Loggable description of the response shape.
 */
function tis_describe_rate_response($result) {

    $raw = $result['raw'] ?? null;

    $shape = [
        'http_code'    => $result['http_code'] ?? 200,
        'parsed_rates' => count($result['rates'] ?? []),
        'body_type'    => gettype($raw),
    ];

    if (is_array($raw)) {

        $shape['top_level_keys'] = implode(',', array_slice(array_keys($raw), 0, 12));

        if (isset($raw['message']) && is_scalar($raw['message'])) {
            $shape['api_message'] = (string) $raw['message'];
        }

        // The response carries currency at the top level, not per courier.
        if (isset($raw['currency']) && is_scalar($raw['currency'])) {
            $shape['response_currency'] = (string) $raw['currency'];
        }
        if (isset($raw['status']) && is_scalar($raw['status'])) {
            $shape['api_status'] = (string) $raw['status'];
        }

        // Locate whichever envelope the courier list actually arrived in.
        foreach ([['data', 'available_courier_companies'], ['available_courier_companies'], ['data']] as $path) {
            $cur = $raw;
            foreach ($path as $k) {
                if (!is_array($cur) || !array_key_exists($k, $cur)) {
                    $cur = null;
                    break;
                }
                $cur = $cur[$k];
            }
            if (is_array($cur) && isset($cur[0]) && is_array($cur[0])) {
                $shape['envelope']      = implode('.', $path);
                $shape['courier_count'] = count($cur);
                $shape['courier_keys']  = implode(',', array_keys($cur[0]));
                foreach (['rate', 'currency', 'etd', 'estimated_delivery_days', 'transit_time'] as $k) {
                    if (array_key_exists($k, $cur[0]) && is_scalar($cur[0][$k])) {
                        $shape['sample_' . $k] = (string) $cur[0][$k];
                    }
                }
                break;
            }
        }
    }

    return $shape;
}

/**
 * Real end-to-end connectivity test: authenticate, then fetch one live rate on
 * the primary destination lane.
 *
 * Authentication alone is not enough to promise the admin that checkout will
 * show rates, so this exercises the rate endpoint too. The administrator only
 * ever sees one of four plain states; everything diagnostic goes to the log.
 *
 * @return string connected|auth_failed|missing|unavailable
 */
function tis_run_connection_test() {

    if (!TIS_Auth::has_credentials()) {
        tis_connection_state('missing');
        return 'missing';
    }

    $token = TIS_Auth::test_connection();

    if (is_array($token) && isset($token['error'])) {

        $code = $token['code'] ?? 'transport';

        // 'locked' is reported in its own right rather than folded into a
        // generic auth failure: the admin's action is to wait, not to go
        // re-check a password that may be perfectly correct.
        $map   = [
            'locked'         => 'locked',
            'auth_rejected'  => 'auth_failed',
            'no_credentials' => 'missing',
        ];
        $state = $map[$code] ?? 'unavailable';

        tis_log('error', 'Connection test failed during authentication.', [
            'state'  => $state,
            'code'   => $code,
            'detail' => $token['error'],
        ]);

        tis_connection_state($state);
        return $state;
    }

    // Authentication succeeded. Now prove the rate endpoint actually answers,
    // and record its shape so the request contract can be verified from logs.
    $countries = tis_warm_countries();
    $country   = $countries[0] ?? 'AE';
    $weight    = 0.5;

    $result = TIS_API::request($country, $weight, 0, $token, 20);

    tis_log('info', 'Connection test: live rate request shape.', array_merge(
        ['country' => $country, 'weight' => $weight, 'request_params' => TIS_API::describe_request_params($country, $weight, 0)],
        tis_describe_rate_response($result)
    ));

    if (!empty($result['error'])) {
        tis_log('error', 'Connection test: authentication worked but the rate request failed.', [
            'country' => $country,
            'detail'  => $result['error'],
        ]);
        tis_connection_state('unavailable');
        return 'unavailable';
    }

    tis_connection_state('connected');
    return 'connected';
}

/**
 * Read or write the last known connection state.
 *
 * @param string|null $set State to record, or null to read.
 * @return array { state, time }
 */
function tis_connection_state($set = null) {

    if (null !== $set) {
        update_option('tis_conn_state', ['state' => $set, 'time' => time()], false);
        return ['state' => $set, 'time' => time()];
    }

    $stored = get_option('tis_conn_state', []);
    return [
        'state' => $stored['state'] ?? '',
        'time'  => (int) ($stored['time'] ?? 0),
    ];
}

/**
 * Handle a posted action. Returns notice markup, or ''.
 */
function tis_handle_settings_post() {

    if (empty($_POST['tis_action'])) {
        return '';
    }

    if (!current_user_can('manage_woocommerce')) {
        return '<div class="notice notice-error"><p>' . esc_html__('You do not have permission to do that.', 'threadly-intl-shipping') . '</p></div>';
    }

    check_admin_referer('tis_settings');

    $action = sanitize_key(wp_unslash($_POST['tis_action']));

    if ('save' === $action) {

        // Every option below is written with autoload = false on purpose. With
        // no autoload argument WordPress resolves it to 'auto', which IS an
        // autoloading value — so the credentials, including the password in
        // plain text, would be read into alloptions on every single request,
        // front end included. None of these are needed outside checkout rate
        // lookups, cron and this screen.
        update_option('tis_sr_email', sanitize_email(wp_unslash($_POST['tis_sr_email'] ?? '')), false);

        // Password field is left blank in the form when a value is already saved
        // (never echo a stored secret back into a form). Only overwrite it when
        // the admin actually typed something.
        $password = (string) wp_unslash($_POST['tis_sr_password'] ?? '');
        if ('' !== $password) {
            update_option('tis_sr_password', $password, false);
            TIS_Auth::clear_failures();
            delete_transient(TIS_Auth::TOKEN_KEY);
        }

        update_option('tis_pickup_pincode', sanitize_text_field(wp_unslash($_POST['tis_pickup_pincode'] ?? '')), false);
        update_option('tis_warm_countries', sanitize_text_field(wp_unslash($_POST['tis_warm_countries'] ?? '')), false);
        update_option('tis_warm_slabs', sanitize_text_field(wp_unslash($_POST['tis_warm_slabs'] ?? '')), false);
        update_option('tis_cache_hours', max(1, (int) ($_POST['tis_cache_hours'] ?? 26)), false);

        // Saving always verifies. A "saved" message that does not say whether
        // the credentials actually work is the thing that makes setup feel
        // uncertain, so the two steps are deliberately one button.
        $state = tis_run_connection_test();

        return '<div class="notice ' . (('connected' === $state) ? 'notice-success' : 'notice-warning') . ' is-dismissible"><p>'
            . esc_html__('Settings saved.', 'threadly-intl-shipping') . ' ' . tis_state_message($state)
            . '</p></div>';
    }

    if ('clear_credentials' === $action) {
        delete_option('tis_sr_password');
        delete_transient(TIS_Auth::TOKEN_KEY);
        TIS_Auth::clear_failures();
        return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Stored Shiprocket password cleared.', 'threadly-intl-shipping') . '</p></div>';
    }

    if ('clear_token' === $action) {
        TIS_Auth::forget();
        return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cached Shiprocket token cleared. The next warm or test will re-authenticate.', 'threadly-intl-shipping') . '</p></div>';
    }

    if ('test_connection' === $action) {

        $state = tis_run_connection_test();

        return '<div class="notice ' . (('connected' === $state) ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>'
            . tis_state_message($state) . '</p></div>';
    }

    if ('warm' === $action) {

        $summary = tis_warm_rates_now();

        $msg = sprintf(
            /* translators: 1: cached lanes, 2: empty lanes, 3: failed lanes, 4: lanes skipped on time budget */
            esc_html__('Warm complete. %1$d lanes cached, %2$d returned no couriers, %3$d failed, %4$d skipped (time budget).', 'threadly-intl-shipping'),
            (int) $summary['ok'],
            (int) $summary['empty'],
            (int) $summary['failed'],
            (int) ($summary['skipped'] ?? 0)
        );

        $class = ($summary['failed'] > 0 || !empty($summary['skipped'])) ? 'notice-warning' : 'notice-success';
        $out   = '<div class="notice ' . $class . '"><p>' . $msg . '</p>';

        foreach ($summary['errors'] as $err) {
            $out .= '<p><code>' . esc_html($err) . '</code></p>';
        }

        return $out . '</div>';
    }

    return '';
}

/**
 * Plain-language message for a connection state.
 *
 * Deliberately says nothing about tokens, HTTP codes or API responses — those
 * belong in the log, not in front of a store owner.
 */
function tis_state_message($state) {

    switch ($state) {
        case 'connected':
            return esc_html__('✅ Shiprocket connection successful.', 'threadly-intl-shipping');
        case 'locked':
            return esc_html__('⏳ Shiprocket has temporarily blocked this API user after repeated failed logins. Logins are paused automatically — no action needed except to wait.', 'threadly-intl-shipping')
                . ' ' . tis_countdown_html();
        case 'auth_failed':
            return esc_html__('❌ Unable to connect to Shiprocket. Check your credentials or try again.', 'threadly-intl-shipping');
        case 'missing':
            return esc_html__('Enter your Shiprocket API email and password to get started.', 'threadly-intl-shipping');
        default:
            return esc_html__('Shiprocket is temporarily unavailable. Checkout will use your fallback rate until it recovers.', 'threadly-intl-shipping');
    }
}

/**
 * Live countdown to when logins resume.
 *
 * Rendered from the server-side remaining seconds and ticked down in the
 * browser, so the admin sees time actually elapsing rather than a static
 * number that is stale the moment the page renders.
 */
function tis_countdown_html() {

    $remaining = TIS_Auth::blocked_for();

    if ($remaining <= 0) {
        return '<strong>' . esc_html__('You can try again now.', 'threadly-intl-shipping') . '</strong>';
    }

    $id = 'tis-countdown-' . wp_rand(1000, 9999);

    ob_start();
    ?><strong><?php esc_html_e('Retrying in', 'threadly-intl-shipping'); ?>
    <span id="<?php echo esc_attr($id); ?>" data-remaining="<?php echo esc_attr($remaining); ?>"><?php
        echo esc_html(sprintf('%02d:%02d', intdiv($remaining, 60), $remaining % 60));
    ?></span></strong>
    <script>
    (function () {
        var el = document.getElementById(<?php echo wp_json_encode($id); ?>);
        if (!el) { return; }
        var left = parseInt(el.getAttribute('data-remaining'), 10) || 0;
        var tick = setInterval(function () {
            left -= 1;
            if (left <= 0) {
                clearInterval(tick);
                el.textContent = <?php echo wp_json_encode(__('now — reload to retry', 'threadly-intl-shipping')); ?>;
                return;
            }
            var m = Math.floor(left / 60), s = left % 60;
            el.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }, 1000);
    })();
    </script><?php
    return ob_get_clean();
}

/**
 * Derive the state to show, preferring live signals over the last stored test.
 */
function tis_current_state($status) {

    if (!$status['configured']) {
        return 'missing';
    }
    if ($status['blocked_for'] > 0) {
        return 'locked';
    }
    if (TIS_API::circuit_is_open()) {
        return 'unavailable';
    }
    if ($status['has_token']) {
        return 'connected';
    }

    $stored = tis_connection_state();
    return $stored['state'] ?: 'untested';
}

/**
 * Names of shipping zones where this method is present and enabled.
 *
 * A store owner can enter working credentials and still see no rates simply
 * because the method was never added to a zone — the most likely reason for a
 * "connected but nothing at checkout" report, so it is surfaced directly.
 *
 * @return string[]
 */
function tis_zones_with_method() {

    if (!class_exists('WC_Shipping_Zones')) {
        return [];
    }

    $found = [];
    $zones = WC_Shipping_Zones::get_zones();

    // get_zones() omits "Rest of the World" (zone 0); check it explicitly.
    $rest = WC_Shipping_Zones::get_zone(0);
    if ($rest) {
        $zones[] = [
            'zone_name'        => $rest->get_zone_name(),
            'shipping_methods' => $rest->get_shipping_methods(),
        ];
    }

    foreach ($zones as $zone) {
        foreach ((array) ($zone['shipping_methods'] ?? []) as $method) {
            if (isset($method->id) && 'tis_shiprocket_intl' === $method->id && $method->is_enabled()) {
                $found[] = (string) ($zone['zone_name'] ?? '');
                break;
            }
        }
    }

    return $found;
}

function tis_render_settings_page() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'threadly-intl-shipping'));
    }

    $notice = tis_handle_settings_post();

    $last   = get_option('tis_last_warm', []);
    $status = TIS_Auth::status();
    $state  = tis_current_state($status);
    $zones  = tis_zones_with_method();

    $dots = [
        'connected'   => '🟢',
        'auth_failed' => '🔴',
        'locked'      => '⏳',
        'unavailable' => '🟠',
        'missing'     => '⚪',
        'untested'    => '⚪',
    ];

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('International Shipping (Shiprocket X)', 'threadly-intl-shipping'); ?></h1>

        <?php echo $notice; // Escaped at construction. ?>

        <p style="font-size:15px;margin:14px 0 4px">
            <strong><?php esc_html_e('Status:', 'threadly-intl-shipping'); ?></strong>
            <?php echo esc_html($dots[$state] ?? '⚪'); ?>
            <?php
            echo ('untested' === $state)
                ? esc_html__('Not tested yet — save your credentials to check the connection.', 'threadly-intl-shipping')
                : tis_state_message($state); // Escaped at construction.
            ?>
            <?php if ('wp-config' === $status['source']) : ?>
                <em style="font-weight:normal;opacity:.7">(<?php esc_html_e('using wp-config.php constants', 'threadly-intl-shipping'); ?>)</em>
            <?php endif; ?>
        </p>

        <?php if ($status['configured'] && empty($zones)) : ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e('Rates will not appear at checkout yet.', 'threadly-intl-shipping'); ?></strong>
                    <?php esc_html_e('This method has not been added to a shipping zone. Add "Shiprocket International" to a zone covering your international destinations.', 'threadly-intl-shipping'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=shipping')); ?>"><?php esc_html_e('Open shipping zones', 'threadly-intl-shipping'); ?></a>
                </p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('tis_settings'); ?>

            <h2><?php esc_html_e('Shiprocket API user', 'threadly-intl-shipping'); ?></h2>
            <table class="form-table" role="presentation">
                <?php if ('wp-config' === $status['source']) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Email / Password', 'threadly-intl-shipping'); ?></th>
                    <td><p class="description"><?php esc_html_e('Defined via TIS_SR_EMAIL / TIS_SR_PASSWORD constants in wp-config.php — the fields below are disabled while those constants are set.', 'threadly-intl-shipping'); ?></p></td>
                </tr>
                <?php else : ?>
                <tr>
                    <th scope="row"><label for="tis_sr_email"><?php esc_html_e('API user email', 'threadly-intl-shipping'); ?></label></th>
                    <td>
                        <input name="tis_sr_email" id="tis_sr_email" type="email" class="regular-text"
                               value="<?php echo esc_attr(get_option('tis_sr_email', '')); ?>" autocomplete="off">
                        <p class="description"><?php esc_html_e('Create this as its own API user in the Shiprocket dashboard (Settings > API > Configure) — do not reuse an API user another plugin or integration already authenticates with.', 'threadly-intl-shipping'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tis_sr_password"><?php esc_html_e('API user password', 'threadly-intl-shipping'); ?></label></th>
                    <td>
                        <input name="tis_sr_password" id="tis_sr_password" type="password" class="regular-text"
                               placeholder="<?php echo $status['configured'] ? esc_attr__('•••••••• (saved — leave blank to keep)', 'threadly-intl-shipping') : ''; ?>"
                               autocomplete="new-password">
                        <?php if ($status['configured']) : ?>
                            <button type="submit" name="tis_action" value="clear_credentials" class="button-link-delete" style="margin-left:8px"
                                    onclick="return confirm('<?php echo esc_js(__('Remove the stored Shiprocket password?', 'threadly-intl-shipping')); ?>');">
                                <?php esc_html_e('Clear', 'threadly-intl-shipping'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <p>
                <button type="submit" name="tis_action" value="save" class="button button-primary">
                    <?php esc_html_e('Save &amp; Test Connection', 'threadly-intl-shipping'); ?>
                </button>
            </p>

            <h2><?php esc_html_e('Configuration', 'threadly-intl-shipping'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tis_pickup_pincode"><?php esc_html_e('Pickup pincode', 'threadly-intl-shipping'); ?></label></th>
                    <td>
                        <input name="tis_pickup_pincode" id="tis_pickup_pincode" type="text" class="regular-text"
                               value="<?php echo esc_attr(get_option('tis_pickup_pincode', '')); ?>"
                               placeholder="<?php echo esc_attr(get_option('woocommerce_store_postcode', '')); ?>">
                        <p class="description"><?php esc_html_e('Origin for rate lookups. Leave blank to use the WooCommerce store postcode. Not every Indian pincode is internationally serviceable.', 'threadly-intl-shipping'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tis_warm_countries"><?php esc_html_e('Destination countries', 'threadly-intl-shipping'); ?></label></th>
                    <td>
                        <input name="tis_warm_countries" id="tis_warm_countries" type="text" class="regular-text"
                               value="<?php echo esc_attr(get_option('tis_warm_countries', 'AE')); ?>">
                        <p class="description"><?php esc_html_e('Comma-separated ISO-2 codes to pre-cache daily. Countries outside this list still work — they fall through to a live call on first request.', 'threadly-intl-shipping'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tis_warm_slabs"><?php esc_html_e('Weight slabs (kg)', 'threadly-intl-shipping'); ?></label></th>
                    <td>
                        <input name="tis_warm_slabs" id="tis_warm_slabs" type="text" class="regular-text"
                               value="<?php echo esc_attr(get_option('tis_warm_slabs', '0.5,1,1.5,2,2.5,3,3.5,4,4.5,5')); ?>">
                        <p class="description"><?php esc_html_e('Billable weight is rounded up to the next 0.5kg, so these should be multiples of 0.5 to guarantee cache hits.', 'threadly-intl-shipping'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tis_cache_hours"><?php esc_html_e('Cache lifetime (hours)', 'threadly-intl-shipping'); ?></label></th>
                    <td>
                        <input name="tis_cache_hours" id="tis_cache_hours" type="number" min="1" step="1" class="small-text"
                               value="<?php echo esc_attr(get_option('tis_cache_hours', 26)); ?>">
                        <p class="description"><?php esc_html_e('Keep this above 24 so the daily warm overlaps rather than leaving a gap. International rate cards do not change intraday.', 'threadly-intl-shipping'); ?></p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" name="tis_action" value="save" class="button button-primary"><?php esc_html_e('Save &amp; Test Connection', 'threadly-intl-shipping'); ?></button>
            </p>

            <hr>

            <details<?php echo ('connected' === $state) ? '' : ' open'; ?> style="margin-top:18px">
                <summary style="cursor:pointer;font-weight:600"><?php esc_html_e('Diagnostics &amp; maintenance', 'threadly-intl-shipping'); ?></summary>

                <table class="widefat" style="max-width:70em;margin-top:12px">
                    <tbody>
                        <tr>
                            <td style="width:220px"><strong><?php esc_html_e('Last successful request', 'threadly-intl-shipping'); ?></strong></td>
                            <td>
                                <?php $ok = get_option('tis_last_success'); ?>
                                <?php if (!empty($ok['time'])) : ?>
                                    <?php
                                    printf(
                                        /* translators: 1: time ago, 2: country, 3: weight, 4: courier count */
                                        esc_html__('%1$s ago — %2$s at %3$skg, %4$d courier(s) returned.', 'threadly-intl-shipping'),
                                        esc_html(human_time_diff((int) $ok['time'], time())),
                                        esc_html($ok['country'] ?? '—'),
                                        esc_html((string) ($ok['weight'] ?? '—')),
                                        (int) ($ok['couriers'] ?? 0)
                                    );
                                    ?>
                                <?php else : ?>
                                    <?php esc_html_e('None recorded yet.', 'threadly-intl-shipping'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Last API error', 'threadly-intl-shipping'); ?></strong></td>
                            <td>
                                <?php $err = get_option('tis_last_error'); ?>
                                <?php if (!empty($err['time'])) : ?>
                                    <?php
                                    printf(
                                        /* translators: 1: time ago, 2: country, 3: weight, 4: error message */
                                        esc_html__('%1$s ago — %2$s at %3$skg: %4$s', 'threadly-intl-shipping'),
                                        esc_html(human_time_diff((int) $err['time'], time())),
                                        esc_html($err['country'] ?? '—'),
                                        esc_html((string) ($err['weight'] ?? '—')),
                                        esc_html($err['message'] ?? '')
                                    );
                                    ?>
                                <?php else : ?>
                                    <?php esc_html_e('None recorded yet.', 'threadly-intl-shipping'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Circuit breaker', 'threadly-intl-shipping'); ?></strong></td>
                            <td>
                                <?php if (TIS_API::circuit_is_open()) : ?>
                                    <?php esc_html_e('Open — live calls paused after a failure. Cached and fallback rates are still served. Warming resets it.', 'threadly-intl-shipping'); ?>
                                <?php else : ?>
                                    <?php esc_html_e('Closed', 'threadly-intl-shipping'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Shipping zones', 'threadly-intl-shipping'); ?></strong></td>
                            <td>
                                <?php if ($zones) : ?>
                                    <?php echo esc_html(implode(', ', array_filter($zones))); ?>
                                <?php else : ?>
                                    <?php esc_html_e('Not added to any shipping zone.', 'threadly-intl-shipping'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Last cache warm', 'threadly-intl-shipping'); ?></strong></td>
                            <td>
                                <?php if (!empty($last['time'])) : ?>
                                    <?php
                                    printf(
                                        /* translators: %s: human readable time difference */
                                        esc_html__('%s ago', 'threadly-intl-shipping'),
                                        esc_html(human_time_diff((int) $last['time'], time()))
                                    );
                                    ?>
                                <?php else : ?>
                                    <?php esc_html_e('Never', 'threadly-intl-shipping'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p style="margin-top:12px">
                    <button type="submit" name="tis_action" value="warm" class="button"><?php esc_html_e('Warm rate cache now', 'threadly-intl-shipping'); ?></button>
                    <?php if ($status['has_token']) : ?>
                        <button type="submit" name="tis_action" value="clear_token" class="button"><?php esc_html_e('Clear cached token', 'threadly-intl-shipping'); ?></button>
                    <?php endif; ?>
                </p>

                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: link to WooCommerce logs */
                        wp_kses(__('Full request and response detail — never including passwords or tokens — is written to the <a href="%s">WooCommerce log</a> under source "tis-shiprocket".', 'threadly-intl-shipping'), ['a' => ['href' => []]]),
                        esc_url(admin_url('admin.php?page=wc-status&tab=logs'))
                    );
                    ?>
                </p>
            </details>
        </form>
    </div>
    <?php
}
