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
require $root . '/includes/class-ts-icarry-credentials.php';
require $root . '/includes/class-ts-icarry-probe.php';
require $root . '/includes/icarry-probe-page.php';

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str . ' at ' . basename($file) . ':' . $line, 0, $no, $file, $line);
});

$pass = 0; $fail = 0;
function ok($l, $c, $d = '') { global $pass, $fail; if ($c) { $pass++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $fail++; echo "  \033[31mFAIL\033[0m  $l" . ($d ? "\n          $d" : '') . "\n"; } }
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

echo "\n" . str_repeat('-', 56) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
