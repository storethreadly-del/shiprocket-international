<?php
/**
 * Smoke harness for the iCarry credentials and discovery probe.
 *
 *     php tests/smoke-icarry-probe.php
 *
 * The probe holds a real account password and makes outbound requests, so the
 * checks that matter most are negative ones: it must refuse the wrong host,
 * never leak the password, and stop after repeated auth failures.
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
require $root . '/includes/class-ts-icarry-credentials.php';
require $root . '/includes/providers/class-ts-provider-icarry.php';
require $root . '/includes/class-ts-provider-registry.php';
require __DIR__ . '/class-ts-provider-fake.php';
require $root . '/includes/class-ts-icarry-probe.php';
require $root . '/includes/icarry-probe-page.php';

class TS_Provider_ICarry_Test extends TS_Provider_ICarry { use TS_Test_Memory_Flush; }

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str . ' at ' . basename($file) . ':' . $line, 0, $no, $file, $line);
});

$pass = 0; $fail = 0;
function ok($l, $c, $d = '') { global $pass, $fail; if ($c) { $pass++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $fail++; echo "  \033[31mFAIL\033[0m  $l" . ($d ? "\n          $d" : '') . "\n"; } }
function is_same($l, $a, $e) { ok($l, $a === $e, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function section($t) { echo "\n\033[1m$t\033[0m\n"; }

const PW = 'S3cret-API-pass';
const USR = 'acme_user';
$creds = ['user' => USR, 'password' => PW];

$calls = [];
$reply = ['code' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => '{"ok":true}'];
$transport = function ($m, $u, $h, $b, $t) use (&$calls, &$reply) { $calls[] = compact('m', 'u', 'h', 'b'); return $reply; };
$probe = new TS_ICarry_Probe($transport);

section('Host allow-list');
foreach (['https://www.icarry.in/x', 'https://icarry.in/x', 'https://api.icarry.in/v1'] as $u) {
    ok("allows $u", true === TS_ICarry_Probe::validate_url($u));
}
foreach ([
    'https://icarry.com/x'            => 'the other company',
    'https://uae.icarry.com/x'        => 'the other company, subdomain',
    'https://icarry.in.evil.com/x'    => 'suffix trick',
    'https://evilicarry.in/x'         => 'prefix trick',
    'https://evil.com/icarry.in'      => 'in path only',
    'http://www.icarry.in/x'          => 'plain http',
    'https://user:pw@www.icarry.in/'  => 'userinfo',
    'https://www.icarry.in:8443/x'    => 'odd port',
    'ftp://www.icarry.in/x'           => 'wrong scheme',
    'not a url'                       => 'garbage',
] as $u => $why) {
    ok("refuses $u ($why)", true !== TS_ICarry_Probe::validate_url($u));
}

section('Nothing is sent for a refused request');
$calls = [];
$r = $probe->run(['url' => 'https://icarry.com/x', 'auth' => 'basic'], $creds);
ok('error returned', !empty($r['error']));
ok('and no request made', 0 === count($calls));

section('Auth modes put the credential where asked');
$cases = [
    'basic'  => fn($h, $b) => isset($h['Authorization']) && 0 === strpos($h['Authorization'], 'Basic '),
    'bearer' => fn($h, $b) => ($h['Authorization'] ?? '') === 'Bearer ' . PW,
    'header' => fn($h, $b) => ($h['X-Api-Key'] ?? '') === PW,
    'body'   => fn($h, $b) => false !== strpos((string) $b, PW) && false !== strpos((string) $b, USR),
];
foreach ($cases as $mode => $check) {
    $calls = [];
    $in = ['url' => 'https://www.icarry.in/x', 'method' => 'POST', 'auth' => $mode, 'header_name' => 'X-Api-Key', 'user_field' => 'Username', 'pass_field' => 'Password'];
    $probe->run($in, $creds);
    ok("$mode sends it correctly", 1 === count($calls) && $check($calls[0]['h'], $calls[0]['b']));
}
$calls = [];
$probe->run(['url' => 'https://www.icarry.in/x', 'auth' => 'none'], $creds);
ok('none sends no credential', !isset($calls[0]['h']['Authorization']) && false === strpos(json_encode($calls[0]), PW));

section('The password never appears in output');
$reply = ['code' => 200, 'headers' => ['x-echo' => 'got ' . PW, 'set-cookie' => 'sid=abc'], 'body' => '{"echo":"' . PW . '","b64":"' . base64_encode(USR . ':' . PW) . '"}'];
foreach (['basic', 'bearer', 'header', 'body'] as $mode) {
    $r = $probe->run(['url' => 'https://www.icarry.in/x', 'method' => 'POST', 'auth' => $mode, 'header_name' => 'X-Api-Key', 'user_field' => 'u', 'pass_field' => 'p'], $creds);
    ok("$mode: not in result", false === strpos(json_encode($r), PW) && false === strpos(json_encode($r), base64_encode(USR . ':' . PW)));
}
ok('Authorization header redacted', '[REDACTED]' === ($r['sent']['headers']['Authorization'] ?? '[REDACTED]'));
ok('set-cookie redacted', '[REDACTED]' === ($r['headers']['set-cookie'] ?? null));
$reply = ['error' => 'could not connect with ' . PW];
$r = $probe->run(['url' => 'https://www.icarry.in/x', 'auth' => 'bearer'], $creds);
ok('transport errors are redacted too', false === strpos(json_encode($r), PW) && !empty($r['error']));

section('Input validation');
$reply = ['code' => 200, 'headers' => [], 'body' => '{}'];
ok('bad method refused', !empty($probe->run(['url' => 'https://www.icarry.in/x', 'method' => 'DELETE'], $creds)['error']));
ok('unknown auth refused', !empty($probe->run(['url' => 'https://www.icarry.in/x', 'auth' => 'magic'], $creds)['error']));
ok('malformed JSON refused', !empty($probe->run(['url' => 'https://www.icarry.in/x', 'method' => 'POST', 'body' => '{nope'], $creds)['error']));
ok('header injection refused', !empty($probe->run(['url' => 'https://www.icarry.in/x', 'auth' => 'header', 'header_name' => "X-A\r\nX-B: 1"], $creds)['error']));
ok('missing credentials refused for authed modes', !empty($probe->run(['url' => 'https://www.icarry.in/x', 'auth' => 'basic'], ['user' => '', 'password' => ''])['error']));

section('Lockout protection');
delete_transient(TS_ICarry_Probe::FAIL_KEY); delete_transient(TS_ICarry_Probe::BLOCK_KEY);
$reply = ['code' => 401, 'headers' => [], 'body' => '{"message":"bad credentials"}'];
$calls = [];
for ($i = 0; $i < 3; $i++) { $probe->run(['url' => 'https://www.icarry.in/x', 'auth' => 'basic'], $creds); }
ok('three auth failures were sent', 3 === count($calls));
$r = $probe->run(['url' => 'https://www.icarry.in/x', 'auth' => 'basic'], $creds);
ok('fourth is refused', !empty($r['error']) && false !== strpos($r['error'], 'paused'));
ok('and nothing is sent', 3 === count($calls));
delete_transient(TS_ICarry_Probe::BLOCK_KEY); delete_transient(TS_ICarry_Probe::FAIL_KEY);
$reply = ['code' => 200, 'headers' => [], 'body' => '{}'];
$probe->run(['url' => 'https://www.icarry.in/x', 'auth' => 'basic'], $creds);
$reply = ['code' => 401, 'headers' => [], 'body' => ''];
$probe->run(['url' => 'https://www.icarry.in/x', 'auth' => 'basic'], $creds);
$reply = ['code' => 200, 'headers' => [], 'body' => '{}'];
$probe->run(['url' => 'https://www.icarry.in/x', 'auth' => 'basic'], $creds);
ok('a success resets the failure count', false === get_transient(TS_ICarry_Probe::FAIL_KEY));

section('Large responses are truncated');
$reply = ['code' => 200, 'headers' => [], 'body' => str_repeat('A', 50000)];
$r = $probe->run(['url' => 'https://www.icarry.in/x'], $creds);
ok('body capped', strlen($r['body']) < 21000 && false !== strpos($r['body'], 'truncated'));

section('Credentials');
$GLOBALS['_options'] = [];
ok('none stored', false === TS_ICarry_Credentials::exists());
update_option('tis_ic_user', USR); update_option('tis_ic_password', PW);
ok('stored credentials found', TS_ICarry_Credentials::exists());
ok('source is settings', 'settings' === TS_ICarry_Credentials::get()['source']);
ok('password listed as a secret', in_array(PW, TS_ICarry_Credentials::secrets(), true));

section('Admin screen');
$_POST = ['tis_ic_action' => 'save', 'tis_ic_user' => 'new_user', 'tis_ic_password' => ''];
tis_handle_icarry_post();
ok('blank password keeps the stored one', PW === get_option('tis_ic_password') && 'new_user' === get_option('tis_ic_user'));
$_POST = ['tis_ic_action' => 'save', 'tis_ic_user' => 'new_user', 'tis_ic_password' => 'rotated'];
tis_handle_icarry_post();
ok('typed password replaces it', 'rotated' === get_option('tis_ic_password'));
$_POST = ['tis_ic_action' => 'clear'];
tis_handle_icarry_post();
ok('clear removes it', false === get_option('tis_ic_password', false));
update_option('tis_ic_password', PW);
$_POST = [];
ob_start(); tis_render_icarry_probe_page(); $html = ob_get_clean();
ok('renders', false !== strpos($html, 'iCarry.in probe'));
ok('never echoes the password', false === strpos($html, PW));
ok('shows a masked placeholder', false !== strpos($html, 'leave blank to keep'));
$_POST = ['tis_ic_action' => 'probe', 'tis_ic_url' => 'https://icarry.com/x', 'tis_ic_auth' => 'basic'];
list(, $res) = tis_handle_icarry_post($transport);
ok('probe via the screen enforces the allow-list', !empty($res['error']));
$_POST = [];

section('Rating settings');
$GLOBALS['_options'] = ['tis_ic_user' => USR, 'tis_ic_password' => PW];
TS_Provider_Registry::reset();
$_POST = ['tis_ic_action' => 'save', 'tis_ic_user' => USR, 'tis_ic_password' => '', 'tis_ic_modes' => 'S, e, x, h, S', 'tis_ic_include_gst' => '1'];
tis_handle_icarry_post();
is_same('modes are sanitised, de-duplicated and upper-cased', get_option('tis_ic_modes'), 'S,E,H');
is_same('GST on', get_option('tis_ic_include_gst'), 'yes');
$_POST = ['tis_ic_action' => 'save', 'tis_ic_user' => USR, 'tis_ic_password' => '', 'tis_ic_modes' => 'zzz'];
tis_handle_icarry_post();
is_same('nonsense modes fall back to surface', get_option('tis_ic_modes'), 'S');
is_same('an unticked GST box means off', get_option('tis_ic_include_gst'), 'no');
ok('saving registers the iCarry provider straight away', TS_Provider_Registry::exists('icarry'));

section('Live verification action');
$_POST = ['tis_ic_action' => 'verify', 'tis_ic_v_pincode' => '628008', 'tis_ic_v_country' => 'US'];

$GLOBALS['_options'] = [];
TS_Provider_Registry::reset();
list($notice, $res) = tis_handle_icarry_post();
ok('refuses without credentials', false !== strpos($notice, 'Save the iCarry credentials first') && null === $res);

$GLOBALS['_options'] = ['tis_ic_user' => USR, 'tis_ic_password' => PW, 'tis_pickup_pincode' => '642001'];
$GLOBALS['_transients'] = [];
$vh = new TS_Http_Fake();
TS_Provider_Registry::reset();
TS_Provider_Registry::register(new TS_Provider_ICarry_Test($vh, new TS_Logger_Null()));

$vh->push(['code' => 200, 'body' => ['error' => ['ip' => 'Your IP 203.0.113.9 is not allowed to access this API!']]]);
list(, $res) = tis_handle_icarry_post();
ok('a failed login is reported and stops there', false === $res['steps'][0]['ok'] && 1 === count($res['steps']));
ok('with advice about the IP allow-list', !empty($res['stopped']) && false !== strpos($res['stopped'], 'allow-list'));
$GLOBALS['_transients'] = [];

$vh->push(['code' => 200, 'body' => ['api_token' => 'TOK123'], 'cookies' => ['OCSESSID' => 'S1']]);
$vh->push(['code' => 200, 'body' => ['success' => 1, 'error' => '', 'estimate' => ['A' => ['courier_name' => 'A', 'courier_id' => '1', 'courier_cost' => '50', 'cod_cost' => '0', 'freight_cost' => '50', 'rto_cost' => '50', 'gst' => '9']]]]);
$vh->push(['code' => 200, 'body' => ['success' => 1, 'estimates' => [['courier_name' => 'B', 'courier_id' => '2-3', 'total' => 900]]]]);
list(, $res) = tis_handle_icarry_post();
is_same('login + domestic + international', array_column($res['steps'], 'step'), ['login', 'domestic estimate', 'international estimate']);
ok('all steps ok', !in_array(false, array_column($res['steps'], 'ok'), true));
is_same('domestic cheapest reported', $res['steps'][1]['cheapest']['courier_name'], 'A');
is_same('international cheapest reported', $res['steps'][2]['cheapest']['rate'], 900.0);
ok('timings are recorded', isset($res['steps'][0]['ms']));
ok('the report never contains the key or the token', false === strpos(json_encode($res), PW) && false === strpos(json_encode($res), 'TOK123'));
ok('it is described as read-only', false !== strpos($res['note'], 'nothing is booked'));
$sent = array_column($vh->requests, 'url');
ok('only estimate endpoints were called, never a booking one', !array_filter($sent, function ($u) { return false !== strpos($u, 'add_shipment') || false !== strpos($u, 'cancel') || false !== strpos($u, 'reverse'); }));

$_POST = ['tis_ic_action' => 'verify', 'tis_ic_v_pincode' => '', 'tis_ic_v_country' => ''];
$vh->push(['code' => 200, 'body' => ['api_token' => 'T'], 'cookies' => []]);
$GLOBALS['_transients'] = [];
list(, $res) = tis_handle_icarry_post();
ok('nothing to estimate is explained, not silently skipped', false !== strpos(json_encode($res), 'Enter a six-digit pincode'));

section('Screen renders with the new sections');
$GLOBALS['_options'] = ['tis_ic_user' => USR, 'tis_ic_password' => PW];
$_POST = [];
ob_start(); tis_render_icarry_probe_page(); $html = ob_get_clean();
ok('rating section', false !== strpos($html, 'Domestic modes to quote'));
ok('GST option', false !== strpos($html, 'Add the GST figure'));
ok('verification section', false !== strpos($html, 'Verify against the live service') && false !== strpos($html, 'Run verification'));
ok('IP allow-list is mentioned', false !== strpos($html, 'restrict API access by IP'));
ok('still never echoes the password', false === strpos($html, PW));

echo "\n" . str_repeat('-', 56) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
