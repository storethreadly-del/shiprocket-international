<?php
/**
 * Admin screen: configuration, cache warming, and the live API probe.
 *
 * The probe exists because Shiprocket's international parameter names and
 * response envelope are not publicly documented. It dumps the raw JSON so the
 * contract constants in class-tis-api.php can be corrected from evidence
 * rather than guesswork.
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
 * Handle a posted action. Returns notice markup, or ''.
 */
function tis_handle_settings_post(&$probe) {

    if (empty($_POST['tis_action'])) {
        return '';
    }

    if (!current_user_can('manage_woocommerce')) {
        return '<div class="notice notice-error"><p>' . esc_html__('You do not have permission to do that.', 'threadly-intl-shipping') . '</p></div>';
    }

    check_admin_referer('tis_settings');

    $action   = sanitize_key(wp_unslash($_POST['tis_action']));
    $provider = TS_Provider_Registry::active();

    if (!$provider) {
        return '<div class="notice notice-error"><p>' . esc_html__('No shipping provider is registered.', 'threadly-intl-shipping') . '</p></div>';
    }

    if ('save' === $action) {

        update_option('tis_sr_email', sanitize_email(wp_unslash($_POST['tis_sr_email'] ?? '')));

        // Password field is left blank in the form when a value is already saved
        // (never echo a stored secret back into a form). Only overwrite it when
        // the admin actually typed something.
        $password = (string) wp_unslash($_POST['tis_sr_password'] ?? '');
        if ('' !== $password) {
            update_option('tis_sr_password', $password);
            $provider->clear_failures();
            $provider->forget_token();
        }

        update_option('tis_pickup_pincode', sanitize_text_field(wp_unslash($_POST['tis_pickup_pincode'] ?? '')));
        update_option('tis_warm_countries', sanitize_text_field(wp_unslash($_POST['tis_warm_countries'] ?? '')));
        update_option('tis_warm_slabs', sanitize_text_field(wp_unslash($_POST['tis_warm_slabs'] ?? '')));
        update_option('tis_cache_hours', max(1, (int) ($_POST['tis_cache_hours'] ?? 26)));

        return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'threadly-intl-shipping') . '</p></div>';
    }

    if ('switch_provider' === $action) {

        $target = sanitize_key(wp_unslash($_POST['ts_provider'] ?? ''));
        $result = TS_Provider_Registry::activate($target);

        if (empty($result['ok'])) {
            return '<div class="notice notice-error"><p>' . esc_html($result['error']) . '</p></div>';
        }

        if (empty($result['changed'])) {
            return '<div class="notice notice-info is-dismissible"><p>'
                . esc_html__('That aggregator was already active — nothing changed.', 'threadly-intl-shipping')
                . '</p></div>';
        }

        $from = TS_Provider_Registry::get($result['from']);
        $to   = TS_Provider_Registry::get($result['to']);

        $out = '<div class="notice notice-success is-dismissible"><p>' . sprintf(
            /* translators: 1: previous aggregator, 2: new aggregator */
            esc_html__('Switched from %1$s to %2$s. The previous aggregator\'s cached rates and token were cleared; its credentials were kept. A rate warm has been scheduled to run shortly.', 'threadly-intl-shipping'),
            '<strong>' . esc_html($from ? $from->label() : $result['from']) . '</strong>',
            '<strong>' . esc_html($to ? $to->label() : $result['to']) . '</strong>'
        ) . '</p>';

        foreach ($result['warnings'] as $warning) {
            $out .= '<p><em>' . esc_html($warning) . '</em></p>';
        }

        return $out . '</div>';
    }

    if ('clear_credentials' === $action) {
        delete_option('tis_sr_password');
        $provider->forget_token();
        $provider->clear_failures();
        return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Stored Shiprocket password cleared.', 'threadly-intl-shipping') . '</p></div>';
    }

    if ('clear_token' === $action) {
        $provider->forget_token();
        return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cached Shiprocket token cleared. The next warm or test will re-authenticate.', 'threadly-intl-shipping') . '</p></div>';
    }

    if ('test_connection' === $action) {

        $result = $provider->test_connection();

        if (is_array($result) && isset($result['error'])) {
            return '<div class="notice notice-error"><p><strong>' . esc_html__('Connection test failed:', 'threadly-intl-shipping') . '</strong> '
                . esc_html($result['error']) . '</p></div>';
        }

        return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Connection test succeeded — Shiprocket accepted the credentials and a token is cached.', 'threadly-intl-shipping') . '</p></div>';
    }

    if ('warm' === $action) {

        $summary = tis_warm_rates_now();

        $msg = sprintf(
            /* translators: 1: cached lanes, 2: empty lanes, 3: failed lanes */
            esc_html__('Warm complete. %1$d lanes cached, %2$d returned no couriers, %3$d failed.', 'threadly-intl-shipping'),
            (int) $summary['ok'],
            (int) $summary['empty'],
            (int) $summary['failed']
        );

        $class = $summary['failed'] > 0 ? 'notice-warning' : 'notice-success';
        $out   = '<div class="notice ' . $class . '"><p>' . $msg . '</p>';

        foreach ($summary['errors'] as $err) {
            $out .= '<p><code>' . esc_html($err) . '</code></p>';
        }

        return $out . '</div>';
    }

    if ('probe' === $action) {

        $country = strtoupper(sanitize_text_field(wp_unslash($_POST['tis_probe_country'] ?? 'US')));
        $weight  = (float) ($_POST['tis_probe_weight'] ?? 0.5);

        $token = $provider->token();

        if (is_array($token) && isset($token['error'])) {
            return '<div class="notice notice-error"><p>' . esc_html__('Authentication failed: ', 'threadly-intl-shipping')
                . esc_html($token['error']) . '</p></div>';
        }

        $probe = $provider->request(
            ['country' => $country, 'postcode' => ''],
            $weight,
            ['cod' => false],
            $token,
            30
        );
        $probe['country'] = $country;
        $probe['weight']  = $weight;

        return '';
    }

    return '';
}

function tis_render_settings_page() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'threadly-intl-shipping'));
    }

    $probe  = null;
    $notice = tis_handle_settings_post($probe);

    $last     = get_option('tis_last_warm', []);
    $provider = TS_Provider_Registry::active();

    if (!$provider) {
        echo '<div class="wrap"><div class="notice notice-error"><p>'
            . esc_html__('No shipping provider is registered.', 'threadly-intl-shipping')
            . '</p></div></div>';
        return;
    }

    $status = $provider->auth_status();

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Threadly Shipping', 'threadly-intl-shipping'); ?></h1>

        <?php echo $notice; // Escaped at construction. ?>

        <?php if (!$status['configured']) : ?>
            <div class="notice notice-warning">
                <p><?php printf(
                    /* translators: %s: aggregator label */
                    esc_html__('No %s API user configured. Add one below — a dedicated API user for this plugin, separate from any other integration on that account, is recommended so a login failure here cannot lock out anything else and vice versa.', 'threadly-intl-shipping'),
                    esc_html($provider->label())
                ); ?></p>
            </div>
        <?php elseif ($status['blocked_for'] > 0) : ?>
            <div class="notice notice-error">
                <p><?php
                    printf(
                        /* translators: 1: aggregator label, 2: minutes remaining */
                        esc_html__('%1$s login is paused after repeated failures. Retrying automatically in %2$s minute(s). Check the API user credentials below.', 'threadly-intl-shipping'),
                        esc_html($provider->label()),
                        esc_html((string) ceil($status['blocked_for'] / MINUTE_IN_SECONDS))
                    );
                ?></p>
            </div>
        <?php elseif (!$status['has_token']) : ?>
            <div class="notice notice-info">
                <p><?php printf(
                    /* translators: %s: aggregator label */
                    esc_html__('No warm %s token is cached right now. Checkout will serve cached or fallback rates until the next warm runs — by design, it never performs a blocking login while a customer waits. Click "Warm rate cache now" to log in immediately.', 'threadly-intl-shipping'),
                    esc_html($provider->label())
                ); ?></p>
            </div>
        <?php else : ?>
            <div class="notice notice-success">
                <p><?php printf(
                    /* translators: %s: aggregator label */
                    esc_html__('%s authentication is healthy — a valid token is cached.', 'threadly-intl-shipping'),
                    esc_html($provider->label())
                ); ?>
                <?php if ('wp-config' === $status['source']) : ?>
                    <?php esc_html_e('Credentials are sourced from wp-config.php constants.', 'threadly-intl-shipping'); ?>
                <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($provider->circuit_is_open()) : ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('The rate-fetch circuit breaker is currently open — live calls are paused after a failure. Cached and fallback rates are still being served. Running a warm below will reset it.', 'threadly-intl-shipping'); ?></p>
            </div>
        <?php endif; ?>

        <h2><?php esc_html_e('Shipping aggregator', 'threadly-intl-shipping'); ?></h2>

        <?php
        $all       = TS_Provider_Registry::all();
        $active_id = $provider->id();
        ?>

        <p class="description" style="max-width:70em">
            <?php esc_html_e('Exactly one aggregator serves rates at a time. Selecting one deactivates the other — there is no state in which both quote at checkout, so an order can never be ambiguous about which dashboard it belongs to.', 'threadly-intl-shipping'); ?>
        </p>

        <form method="post" onsubmit="return tsConfirmSwitch(this);">
            <?php wp_nonce_field('tis_settings'); ?>

            <table class="widefat" style="max-width:70em;margin-top:1em">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th><?php esc_html_e('Aggregator', 'threadly-intl-shipping'); ?></th>
                        <th><?php esc_html_e('Serves', 'threadly-intl-shipping'); ?></th>
                        <th><?php esc_html_e('Credentials', 'threadly-intl-shipping'); ?></th>
                        <th><?php esc_html_e('Status', 'threadly-intl-shipping'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all as $pid => $p) : ?>
                    <?php
                    $caps  = $p->capabilities();
                    $serves = [];
                    if (!empty($caps['domestic'])) {
                        $serves[] = __('Domestic', 'threadly-intl-shipping');
                    }
                    if (!empty($caps['international'])) {
                        $serves[] = __('International', 'threadly-intl-shipping');
                    }
                    $is_active = ($pid === $active_id);
                    ?>
                    <tr>
                        <td>
                            <input type="radio" name="ts_provider" id="ts_provider_<?php echo esc_attr($pid); ?>"
                                   value="<?php echo esc_attr($pid); ?>"
                                   data-label="<?php echo esc_attr($p->label()); ?>"
                                   <?php checked($is_active); ?>>
                        </td>
                        <td>
                            <label for="ts_provider_<?php echo esc_attr($pid); ?>">
                                <strong><?php echo esc_html($p->label()); ?></strong>
                            </label>
                            <?php if (!empty($caps['order_sync'])) : ?>
                                <br><span class="description"><?php esc_html_e('Supports order sync', 'threadly-intl-shipping'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($serves ? implode(' + ', $serves) : __('Nothing configured', 'threadly-intl-shipping')); ?></td>
                        <td>
                            <?php if ($p->has_credentials()) : ?>
                                <span style="color:#1a7f37">&#10003; <?php esc_html_e('Configured', 'threadly-intl-shipping'); ?></span>
                            <?php else : ?>
                                <span style="color:#b32d2e">&#10007; <?php esc_html_e('Not configured', 'threadly-intl-shipping'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_active) : ?>
                                <strong><?php esc_html_e('Active', 'threadly-intl-shipping'); ?></strong>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e('Inactive', 'threadly-intl-shipping'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (count($all) < 2) : ?>
                <p class="description" style="margin-top:1em">
                    <?php esc_html_e('Only one aggregator is available in this build, so there is nothing to switch to yet. The iCarry provider — which adds domestic Indian lanes and order sync — is not implemented yet.', 'threadly-intl-shipping'); ?>
                </p>
            <?php else : ?>
                <p style="margin-top:1em">
                    <button type="submit" name="tis_action" value="switch_provider" class="button button-primary">
                        <?php esc_html_e('Switch aggregator', 'threadly-intl-shipping'); ?>
                    </button>
                    <span class="description" style="margin-left:8px">
                        <?php esc_html_e('Changes what customers are quoted immediately. The outgoing aggregator\'s cached rates and token are cleared; its credentials are kept, so switching back needs no re-entry.', 'threadly-intl-shipping'); ?>
                    </span>
                </p>
            <?php endif; ?>
        </form>

        <script>
        /* A switch changes live checkout pricing, so make it deliberate. */
        function tsConfirmSwitch(form) {
            var checked = form.querySelector('input[name="ts_provider"]:checked');
            if (!checked) {
                return false;
            }
            if (checked.value === <?php echo wp_json_encode($active_id); ?>) {
                return true;
            }
            return window.confirm(
                <?php echo wp_json_encode(__('Switch the active shipping aggregator to', 'threadly-intl-shipping')); ?>
                + ' "' + checked.getAttribute('data-label') + '"?\n\n'
                + <?php echo wp_json_encode(__('This changes the rates customers are quoted at checkout straight away, and clears the current aggregator\'s cached rates.', 'threadly-intl-shipping')); ?>
            );
        }
        </script>

        <h2><?php esc_html_e('Diagnostics', 'threadly-intl-shipping'); ?></h2>
        <table class="widefat" style="max-width:70em">
            <tbody>
                <tr>
                    <td style="width:220px"><strong><?php esc_html_e('Last successful request', 'threadly-intl-shipping'); ?></strong></td>
                    <td>
                        <?php $ok = $provider->last_success(); ?>
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
                        <?php $err = $provider->last_error(); ?>
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
            </tbody>
        </table>
        <p class="description">
            <?php
            printf(
                /* translators: %s: link to WooCommerce logs */
                wp_kses(__('Full request/response detail (never including passwords or tokens) is written to the <a href="%s">WooCommerce log</a> under source "tis-shiprocket".', 'threadly-intl-shipping'), ['a' => ['href' => []]]),
                esc_url(admin_url('admin.php?page=wc-status&tab=logs'))
            );
            ?>
        </p>

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
                <button type="submit" name="tis_action" value="test_connection" class="button">
                    <?php esc_html_e('Test connection', 'threadly-intl-shipping'); ?>
                </button>
                <span class="description"><?php esc_html_e('Authenticates immediately with the credentials above. Does not touch checkout or the rate cache.', 'threadly-intl-shipping'); ?></span>
            </p>
            <?php if ($status['has_token']) : ?>
            <p>
                <button type="submit" name="tis_action" value="clear_token" class="button">
                    <?php esc_html_e('Clear cached token', 'threadly-intl-shipping'); ?>
                </button>
                <span class="description"><?php esc_html_e('Forces the next warm or test to re-authenticate. Credentials are kept.', 'threadly-intl-shipping'); ?></span>
            </p>
            <?php endif; ?>

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
                               value="<?php echo esc_attr(get_option('tis_warm_countries', 'US,GB,AE,AU,CA,SG')); ?>">
                        <p class="description"><?php esc_html_e('Comma-separated ISO-2 codes to pre-cache daily. Countries outside this list still work — they fall through to a live call on first request.', 'threadly-intl-shipping'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tis_warm_slabs"><?php esc_html_e('Weight slabs (kg)', 'threadly-intl-shipping'); ?></label></th>
                    <td>
                        <input name="tis_warm_slabs" id="tis_warm_slabs" type="text" class="regular-text"
                               value="<?php echo esc_attr(get_option('tis_warm_slabs', '0.5,1,1.5,2,2.5,3,4,5')); ?>">
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
                <button type="submit" name="tis_action" value="save" class="button button-primary"><?php esc_html_e('Save settings', 'threadly-intl-shipping'); ?></button>
                <button type="submit" name="tis_action" value="warm" class="button"><?php esc_html_e('Warm rate cache now', 'threadly-intl-shipping'); ?></button>
            </p>

            <?php if (!empty($last['time'])) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: human readable time difference */
                        esc_html__('Last warm: %s ago.', 'threadly-intl-shipping'),
                        esc_html(human_time_diff((int) $last['time'], time()))
                    );
                    ?>
                </p>
            <?php endif; ?>

            <hr>

            <h2><?php esc_html_e('Live API probe', 'threadly-intl-shipping'); ?></h2>
            <p class="description" style="max-width:46em">
                <?php esc_html_e('Shiprocket does not publish readable documentation for the international serviceability endpoint, so this plugin ships with its best guess at the parameter names and response shape. Run this probe once against real credentials, read the raw JSON, and correct the CONTRACT constants at the top of includes/class-tis-api.php if they differ.', 'threadly-intl-shipping'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tis_probe_country"><?php esc_html_e('Country (ISO-2)', 'threadly-intl-shipping'); ?></label></th>
                    <td><input name="tis_probe_country" id="tis_probe_country" type="text" class="small-text" value="US"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tis_probe_weight"><?php esc_html_e('Weight (kg)', 'threadly-intl-shipping'); ?></label></th>
                    <td><input name="tis_probe_weight" id="tis_probe_weight" type="number" step="0.1" min="0.1" class="small-text" value="0.5"></td>
                </tr>
            </table>

            <p>
                <button type="submit" name="tis_action" value="probe" class="button"><?php esc_html_e('Run live probe', 'threadly-intl-shipping'); ?></button>
            </p>
        </form>

        <?php if (is_array($probe)) : ?>
            <h3><?php esc_html_e('Probe result', 'threadly-intl-shipping'); ?></h3>

            <p><strong><?php esc_html_e('Request URL', 'threadly-intl-shipping'); ?>:</strong><br>
                <code style="word-break:break-all"><?php echo esc_html($probe['url'] ?? '—'); ?></code></p>

            <?php if (!empty($probe['error'])) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($probe['error']); ?></p></div>
            <?php endif; ?>

            <p><strong><?php esc_html_e('Parsed rates', 'threadly-intl-shipping'); ?>:</strong>
                <?php echo esc_html(count($probe['rates'] ?? [])); ?></p>

            <?php if (!empty($probe['rates'])) : ?>
                <table class="widefat striped" style="max-width:70em">
                    <thead><tr>
                        <th><?php esc_html_e('Courier', 'threadly-intl-shipping'); ?></th>
                        <th><?php esc_html_e('ID', 'threadly-intl-shipping'); ?></th>
                        <th><?php esc_html_e('Rate', 'threadly-intl-shipping'); ?></th>
                        <th><?php esc_html_e('Transit', 'threadly-intl-shipping'); ?></th>
                        <th><?php esc_html_e('EDD', 'threadly-intl-shipping'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($probe['rates'] as $r) : ?>
                        <tr>
                            <td><?php echo esc_html($r['courier_name']); ?></td>
                            <td><?php echo esc_html($r['courier_id']); ?></td>
                            <td><?php echo esc_html($r['rate']); ?></td>
                            <td><?php echo esc_html((string) $r['transit_days']); ?></td>
                            <td><?php echo esc_html((string) $r['edd']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p><strong><?php esc_html_e('Raw response', 'threadly-intl-shipping'); ?>:</strong></p>
            <textarea readonly rows="18" style="width:100%;font-family:monospace;font-size:12px"><?php
                echo esc_textarea(wp_json_encode($probe['raw'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            ?></textarea>
        <?php endif; ?>
    </div>
    <?php
}
