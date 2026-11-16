<?php
/**
 * Admin screen: iCarry.in credentials and the discovery probe.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        __('iCarry.in probe', 'threadly-intl-shipping'),
        __('iCarry probe', 'threadly-intl-shipping'),
        'manage_woocommerce',
        'tis-icarry-probe',
        'tis_render_icarry_probe_page'
    );
});

/** Handle a post. Returns [notice_html, probe_result|null]. */
function tis_handle_icarry_post($transport = null) {

    if (empty($_POST['tis_ic_action'])) {
        return ['', null];
    }

    if (!current_user_can('manage_woocommerce')) {
        return ['<div class="notice notice-error"><p>' . esc_html__('You do not have permission to do that.', 'threadly-intl-shipping') . '</p></div>', null];
    }

    check_admin_referer('tis_icarry');

    $action = sanitize_key(wp_unslash($_POST['tis_ic_action']));

    if ('save' === $action) {

        update_option(TS_ICarry_Credentials::OPT_USER, sanitize_text_field(wp_unslash($_POST['tis_ic_user'] ?? '')));

        // Never echoed back into the form; only overwritten when typed.
        $pw = (string) wp_unslash($_POST['tis_ic_password'] ?? '');
        if ('' !== $pw) {
            update_option(TS_ICarry_Credentials::OPT_PASSWORD, $pw);
            delete_transient(TS_ICarry_Probe::FAIL_KEY);
            delete_transient(TS_ICarry_Probe::BLOCK_KEY);
        }

        $modes = array_values(array_unique(array_filter(
            array_map('trim', explode(',', strtoupper((string) wp_unslash($_POST['tis_ic_modes'] ?? 'S')))),
            function ($m) { return in_array($m, ['S', 'E', 'H'], true); }
        )));
        update_option('tis_ic_modes', $modes ? implode(',', $modes) : 'S');
        update_option('tis_ic_include_gst', empty($_POST['tis_ic_include_gst']) ? 'no' : 'yes');

        // The registry has already booted without iCarry if this is the first save.
        TS_Provider_Registry::register_optional_providers();

        return ['<div class="notice notice-success is-dismissible"><p>' . esc_html__('iCarry settings saved.', 'threadly-intl-shipping') . '</p></div>', null];
    }

    if ('verify' === $action) {

        TS_Provider_Registry::register_optional_providers();
        $provider = TS_Provider_Registry::get('icarry');

        if (!$provider) {
            return ['<div class="notice notice-error"><p>' . esc_html__('Save the iCarry credentials first.', 'threadly-intl-shipping') . '</p></div>', null];
        }

        return ['', tis_icarry_verify(
            $provider,
            sanitize_text_field(wp_unslash($_POST['tis_ic_v_pincode'] ?? '')),
            strtoupper(sanitize_text_field(wp_unslash($_POST['tis_ic_v_country'] ?? 'US')))
        )];
    }

    if ('clear' === $action) {
        delete_option(TS_ICarry_Credentials::OPT_PASSWORD);
        return ['<div class="notice notice-success is-dismissible"><p>' . esc_html__('Stored iCarry password cleared.', 'threadly-intl-shipping') . '</p></div>', null];
    }

    if ('probe' === $action) {

        $in = [
            'url'         => wp_unslash($_POST['tis_ic_url'] ?? ''),
            'method'      => wp_unslash($_POST['tis_ic_method'] ?? 'GET'),
            'auth'        => wp_unslash($_POST['tis_ic_auth'] ?? 'none'),
            'header_name' => wp_unslash($_POST['tis_ic_header_name'] ?? ''),
            'user_field'  => wp_unslash($_POST['tis_ic_user_field'] ?? ''),
            'pass_field'  => wp_unslash($_POST['tis_ic_pass_field'] ?? ''),
            'body'        => wp_unslash($_POST['tis_ic_body'] ?? ''),
        ];

        $creds = TS_ICarry_Credentials::get();
        $probe = new TS_ICarry_Probe($transport);

        return ['', $probe->run($in, $creds)];
    }

    return ['', null];
}

/**
 * Exercise the real provider against the live service: log in, then one domestic
 * and one international estimate. Read-only: estimates book nothing and spend
 * nothing. Reports what came back so the unverified parts of the contract
 * (session cookie, GST handling, token separator) can be settled from evidence.
 *
 * @return array
 */
function tis_icarry_verify(TS_Provider $provider, $dest_pincode, $country) {

    $report = ['note' => 'Read-only: estimates only, nothing is booked or charged.', 'steps' => []];

    $t0    = microtime(true);
    $token = $provider->test_connection();
    $ms    = (int) round((microtime(true) - $t0) * 1000);

    if (is_array($token) && isset($token['error'])) {
        $report['steps'][] = ['step' => 'login', 'ok' => false, 'ms' => $ms, 'error' => $token['error']];
        $report['stopped'] = 'Login failed, so no estimate was attempted. If the error mentions an IP address, add this server\'s outbound IP to the allow-list in your iCarry account.';
        return $report;
    }

    $report['steps'][] = ['step' => 'login', 'ok' => true, 'ms' => $ms];

    $lanes = [];
    if (preg_match('/^\d{6}$/', $dest_pincode)) {
        $lanes['domestic'] = ['country' => 'IN', 'postcode' => $dest_pincode];
    }
    if ('' !== $country && 'IN' !== $country) {
        $lanes['international'] = ['country' => $country, 'postcode' => ''];
    }

    foreach ($lanes as $label => $destination) {

        $t0 = microtime(true);
        $r  = $provider->request($destination, 0.5, ['cod' => false, 'value' => 0], $token, 20);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        $report['steps'][] = [
            'step'     => $label . ' estimate',
            'ok'       => empty($r['error']),
            'ms'       => $ms,
            'error'    => $r['error'] ?? null,
            'couriers' => count($r['rates'] ?? []),
            'cheapest' => !empty($r['rates']) ? $r['rates'][0] : null,
            'raw'      => substr((string) wp_json_encode($r['raw'] ?? null), 0, 1500),
        ];
    }

    if (!$lanes) {
        $report['steps'][] = ['step' => 'estimates', 'ok' => false, 'error' => 'Enter a six-digit pincode and/or a country to estimate.'];
    }

    return $report;
}

function tis_render_icarry_probe_page() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'threadly-intl-shipping'));
    }

    list($notice, $result) = tis_handle_icarry_post();
    $creds = TS_ICarry_Credentials::get();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('iCarry.in probe', 'threadly-intl-shipping'); ?></h1>
        <?php echo $notice; // Escaped at construction. ?>

        <div class="notice notice-info"><p>
            <?php esc_html_e('iCarry publishes endpoint names but not paths or parameters, so this sends one request you build and shows the raw response. Use it to discover the real contract. Requests are limited to icarry.in. Repeated authentication failures pause the probe to protect the account from lockout.', 'threadly-intl-shipping'); ?>
        </p></div>

        <form method="post">
            <?php wp_nonce_field('tis_icarry'); ?>
            <h2><?php esc_html_e('Credentials', 'threadly-intl-shipping'); ?></h2>
            <table class="form-table" role="presentation">
                <?php if ('wp-config' === $creds['source']) : ?>
                <tr><th><?php esc_html_e('Source', 'threadly-intl-shipping'); ?></th>
                    <td><?php esc_html_e('Defined by TIS_IC_USER / TIS_IC_PASSWORD in wp-config.php.', 'threadly-intl-shipping'); ?></td></tr>
                <?php else : ?>
                <tr><th><label for="tis_ic_user"><?php esc_html_e('Username', 'threadly-intl-shipping'); ?></label></th>
                    <td><input name="tis_ic_user" id="tis_ic_user" type="text" class="regular-text" autocomplete="off"
                               value="<?php echo esc_attr($creds['user']); ?>"></td></tr>
                <tr><th><label for="tis_ic_password"><?php esc_html_e('API password', 'threadly-intl-shipping'); ?></label></th>
                    <td><input name="tis_ic_password" id="tis_ic_password" type="password" class="regular-text" autocomplete="new-password"
                               placeholder="<?php echo '' !== $creds['password'] ? esc_attr__('•••••••• (saved — leave blank to keep)', 'threadly-intl-shipping') : ''; ?>">
                        <p class="description"><?php esc_html_e('Prefer wp-config.php constants TIS_IC_USER and TIS_IC_PASSWORD to keep this out of the database.', 'threadly-intl-shipping'); ?></p></td></tr>
                <?php endif; ?>
            </table>
            <h2><?php esc_html_e('Rating', 'threadly-intl-shipping'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="tis_ic_modes"><?php esc_html_e('Domestic modes to quote', 'threadly-intl-shipping'); ?></label></th>
                    <td><input name="tis_ic_modes" id="tis_ic_modes" type="text" class="small-text" value="<?php echo esc_attr(get_option('tis_ic_modes', 'S')); ?>">
                        <p class="description"><?php esc_html_e('S = surface, E = express/air, H = hyperlocal. Comma-separated. Each mode is one extra call on a cold lane, so S alone is the fastest.', 'threadly-intl-shipping'); ?></p></td></tr>
                <tr><th><?php esc_html_e('GST', 'threadly-intl-shipping'); ?></th>
                    <td><label><input name="tis_ic_include_gst" type="checkbox" value="1" <?php checked('no' !== get_option('tis_ic_include_gst', 'yes')); ?>>
                        <?php esc_html_e('Add the GST figure to the quoted rate', 'threadly-intl-shipping'); ?></label>
                        <p class="description"><?php esc_html_e('iCarry returns GST as a separate figure. Leave this on until a real shipment shows the wallet is charged the rate without it.', 'threadly-intl-shipping'); ?></p></td></tr>
            </table>

            <?php if ('wp-config' !== $creds['source']) : ?>
                <p><button type="submit" name="tis_ic_action" value="save" class="button button-primary"><?php esc_html_e('Save', 'threadly-intl-shipping'); ?></button>
                   <button type="submit" name="tis_ic_action" value="clear" class="button"><?php esc_html_e('Clear stored password', 'threadly-intl-shipping'); ?></button></p>
            <?php else : ?>
                <p><button type="submit" name="tis_ic_action" value="save" class="button button-primary"><?php esc_html_e('Save rating settings', 'threadly-intl-shipping'); ?></button></p>
            <?php endif; ?>

            <h2><?php esc_html_e('Verify against the live service', 'threadly-intl-shipping'); ?></h2>
            <p class="description"><?php esc_html_e('Logs in, then asks for one domestic and one international estimate. Read-only: nothing is booked or charged. iCarry can restrict API access by IP, so run this from this server.', 'threadly-intl-shipping'); ?></p>
            <table class="form-table" role="presentation">
                <tr><th><label for="tis_ic_v_pincode"><?php esc_html_e('Destination pincode', 'threadly-intl-shipping'); ?></label></th>
                    <td><input name="tis_ic_v_pincode" id="tis_ic_v_pincode" type="text" class="small-text" maxlength="6" placeholder="560001"></td></tr>
                <tr><th><label for="tis_ic_v_country"><?php esc_html_e('Destination country', 'threadly-intl-shipping'); ?></label></th>
                    <td><input name="tis_ic_v_country" id="tis_ic_v_country" type="text" class="small-text" maxlength="2" value="US"></td></tr>
            </table>
            <p><button type="submit" name="tis_ic_action" value="verify" class="button button-primary"><?php esc_html_e('Run verification', 'threadly-intl-shipping'); ?></button></p>

            <h2><?php esc_html_e('Request', 'threadly-intl-shipping'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="tis_ic_url">URL</label></th>
                    <td><input name="tis_ic_url" id="tis_ic_url" type="url" class="large-text" placeholder="https://www.icarry.in/..."></td></tr>
                <tr><th><label for="tis_ic_method"><?php esc_html_e('Method', 'threadly-intl-shipping'); ?></label></th>
                    <td><select name="tis_ic_method" id="tis_ic_method"><option>GET</option><option>POST</option></select></td></tr>
                <tr><th><label for="tis_ic_auth"><?php esc_html_e('Send credentials as', 'threadly-intl-shipping'); ?></label></th>
                    <td><select name="tis_ic_auth" id="tis_ic_auth">
                        <option value="none"><?php esc_html_e('None', 'threadly-intl-shipping'); ?></option>
                        <option value="basic"><?php esc_html_e('HTTP Basic (username:password)', 'threadly-intl-shipping'); ?></option>
                        <option value="bearer"><?php esc_html_e('Bearer (password as token)', 'threadly-intl-shipping'); ?></option>
                        <option value="header"><?php esc_html_e('Custom header (password as value)', 'threadly-intl-shipping'); ?></option>
                        <option value="body"><?php esc_html_e('JSON body fields (POST)', 'threadly-intl-shipping'); ?></option>
                    </select></td></tr>
                <tr><th><label for="tis_ic_header_name"><?php esc_html_e('Header name', 'threadly-intl-shipping'); ?></label></th>
                    <td><input name="tis_ic_header_name" id="tis_ic_header_name" type="text" class="regular-text" placeholder="X-Api-Key"></td></tr>
                <tr><th><?php esc_html_e('Body field names', 'threadly-intl-shipping'); ?></th>
                    <td><input name="tis_ic_user_field" type="text" class="regular-text" placeholder="username"> &nbsp;
                        <input name="tis_ic_pass_field" type="text" class="regular-text" placeholder="password"></td></tr>
                <tr><th><label for="tis_ic_body"><?php esc_html_e('Extra JSON body', 'threadly-intl-shipping'); ?></label></th>
                    <td><textarea name="tis_ic_body" id="tis_ic_body" rows="5" class="large-text code" placeholder="{}"></textarea></td></tr>
            </table>
            <p><button type="submit" name="tis_ic_action" value="probe" class="button"><?php esc_html_e('Send probe', 'threadly-intl-shipping'); ?></button></p>
        </form>

        <?php if (is_array($result)) : ?>
            <h2><?php esc_html_e('Result', 'threadly-intl-shipping'); ?></h2>
            <?php if (!empty($result['error'])) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($result['error']); ?></p></div>
            <?php endif; ?>
            <?php if (isset($result['code'])) : ?>
                <p><strong>HTTP <?php echo esc_html((string) $result['code']); ?></strong></p>
            <?php endif; ?>
            <textarea readonly rows="22" class="large-text code"><?php
                echo esc_textarea(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            ?></textarea>
        <?php endif; ?>
    </div>
    <?php
}
