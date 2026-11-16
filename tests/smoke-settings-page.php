<?php
/**
 * Render the settings screen headlessly and assert it is well formed.
 *
 *     php tests/smoke-settings-page.php
 *
 * The screen is the bulk of phase 2, and a template is exactly the kind of code
 * `php -l` passes and then breaks at runtime on an undefined variable. So every
 * PHP diagnostic — notice and warning included — is promoted to a failure here:
 * the page must render completely clean.
 *
 * @package Threadly_Intl_Shipping
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/admin-stubs.php';

$root = dirname(__DIR__);
require $root . '/includes/class-ts-http.php';
require __DIR__ . '/class-ts-http-fake.php';
require $root . '/includes/class-ts-logger.php';
require $root . '/includes/providers/interface-ts-provider.php';
require $root . '/includes/providers/abstract-class-ts-provider-base.php';
require $root . '/includes/providers/class-ts-provider-shiprocket.php';
require $root . '/includes/class-ts-provider-registry.php';
require $root . '/includes/class-tis-auth.php';
require $root . '/includes/class-tis-api.php';
require __DIR__ . '/class-ts-provider-fake.php';

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str . ' at ' . basename($file) . ':' . $line, 0, $no, $file, $line);
});

require $root . '/includes/rate-warmer.php';
require $root . '/includes/settings-page.php';

$pass = 0;
$fail = 0;

function ok($label, $condition, $detail = '') {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  \033[32mPASS\033[0m  $label\n";
    } else {
        $fail++;
        echo "  \033[31mFAIL\033[0m  $label" . ($detail ? "\n          $detail" : '') . "\n";
    }
}
function section($t) { echo "\n\033[1m$t\033[0m\n"; }

/** Render and return the markup, or the exception that stopped it. */
function render() {
    ob_start();
    try {
        tis_render_settings_page();
        return ['html' => ob_get_clean()];
    } catch (Throwable $e) {
        ob_end_clean();
        return ['error' => get_class($e) . ': ' . $e->getMessage()];
    }
}

$http     = new TS_Http_Fake();
$provider = new TS_Provider_Shiprocket_Test($http, new TS_Logger_Null());

TS_Provider_Registry::reset();
TS_Provider_Registry::register($provider);

update_option('tis_pickup_pincode', '110001');

/* =================================================================== */
section('One provider registered');

$r = render();
ok('renders without error', isset($r['html']), $r['error'] ?? '');

$html = $r['html'] ?? '';
ok('shows the aggregator section', false !== strpos($html, 'Shipping aggregator'));
ok('lists Shiprocket X', false !== strpos($html, 'Shiprocket X'));
ok('states the mutual-exclusion rule', false !== strpos($html, 'Exactly one aggregator serves rates at a time'));
ok('marks it active', false !== strpos($html, '<strong>Active</strong>'));
ok('reports credentials as not configured', false !== strpos($html, 'Not configured'));
ok('declares international', false !== strpos($html, 'International'));
ok('offers NO switch button with nothing to switch to', false === strpos($html, 'Switch aggregator'));
ok('explains why', false !== strpos($html, 'Only one aggregator is available'));
ok('still renders diagnostics', false !== strpos($html, 'Diagnostics'));
ok('still renders the probe', false !== strpos($html, 'Live API probe'));
ok('includes a nonce', false !== strpos($html, 'testnonce'));

section('Two providers registered');

$fake = new TS_Provider_Fake(new TS_Http_Fake(), new TS_Logger_Null());
TS_Provider_Registry::register($fake);

$r    = render();
ok('renders without error', isset($r['html']), $r['error'] ?? '');
$html = $r['html'] ?? '';

ok('lists both aggregators', false !== strpos($html, 'Shiprocket X') && false !== strpos($html, 'FakeCarry'));
ok('now offers the switch button', false !== strpos($html, 'Switch aggregator'));
ok('warns that it changes live pricing', false !== strpos($html, 'Changes what customers are quoted immediately'));
ok('gates the switch behind a confirm', false !== strpos($html, 'tsConfirmSwitch'));
ok('shows the configured credential state', false !== strpos($html, 'Configured'));
ok('shows the domestic capability', false !== strpos($html, 'Domestic'));
ok('flags order-sync support', false !== strpos($html, 'Supports order sync'));

$radios = preg_match_all('/<input type="radio" name="ts_provider"/', $html);
ok('one radio per provider', 2 === $radios, "found $radios");

$checked = preg_match_all('/name="ts_provider"[^>]*checked="checked"/', $html);
ok('exactly one is preselected (mutual exclusion is visible in the UI)', 1 === $checked, "found $checked");

section('Renders with credentials configured');

update_option('tis_sr_email', 'intl-api@example.test');
update_option('tis_sr_password', 'secret');

$r = render();
ok('renders without error', isset($r['html']), $r['error'] ?? '');
$html = $r['html'] ?? '';
ok('reports credentials as configured', false !== strpos($html, '&#10003; Configured'));
ok('never echoes the stored password', false === strpos($html, 'secret'));
ok('shows a masked placeholder instead', false !== strpos($html, 'leave blank to keep'));

section('Status notices follow the active provider, not a hardcoded name');

update_option('ts_active_provider', 'fakecarry');
$r    = render();
ok('renders without error', isset($r['html']), $r['error'] ?? '');
$html = $r['html'] ?? '';

ok('names the active aggregator in its notice', false !== strpos($html, 'FakeCarry'));
ok('does not claim Shiprocket auth state while FakeCarry is active',
    false === strpos($html, 'Shiprocket X authentication is healthy'));
delete_option('ts_active_provider');

section('Switch POST path');

$_POST = ['tis_action' => 'switch_provider', 'ts_provider' => 'fakecarry'];
$probe = null;
try {
    $notice = tis_handle_settings_post($probe);
    ok('handles the switch without error', true);
    ok('reports the switch', false !== strpos($notice, 'Switched from'));
    ok('names both aggregators', false !== strpos($notice, 'Shiprocket X') && false !== strpos($notice, 'FakeCarry'));
    ok('says credentials were kept', false !== strpos($notice, 'credentials were kept'));
    ok('it actually took effect', 'fakecarry' === TS_Provider_Registry::active_id());
} catch (Throwable $e) {
    ok('handles the switch without error', false, get_class($e) . ': ' . $e->getMessage());
}

$_POST = ['tis_action' => 'switch_provider', 'ts_provider' => 'bogus'];
$notice = tis_handle_settings_post($probe);
ok('rejects an unknown aggregator', false !== strpos($notice, 'notice-error'));
ok('and does not change the active one', 'fakecarry' === TS_Provider_Registry::active_id());

$_POST = ['tis_action' => 'switch_provider', 'ts_provider' => 'fakecarry'];
$notice = tis_handle_settings_post($probe);
ok('treats re-selecting the active one as a no-op', false !== strpos($notice, 'already active'));

$_POST = [];

/* =================================================================== */
echo "\n" . str_repeat('-', 56) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
