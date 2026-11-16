<?php
/**
 * Smoke harness for the iCarry.in provider.
 *
 *     php tests/smoke-icarry-provider.php
 *
 * Drives the real provider with a stubbed HTTP client and payloads shaped like
 * iCarry's documented responses. It proves the code follows the documented
 * contract; it cannot prove the live service behaves as documented. The parts
 * of the contract that need a live check are marked VERIFY in the provider, and
 * the "Verify against the live service" action on the iCarry screen settles them.
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
require $root . '/includes/token-refresh.php';

class TS_Provider_ICarry_Test extends TS_Provider_ICarry { use TS_Test_Memory_Flush; }

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str . ' at ' . basename($file) . ':' . $line, 0, $no, $file, $line);
});

$pass = 0; $fail = 0;
function ok($l, $c, $d = '') { global $pass, $fail; if ($c) { $pass++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $fail++; echo "  \033[31mFAIL\033[0m  $l" . ($d ? "\n          $d" : '') . "\n"; } }
function is_same($l, $a, $e) { ok($l, $a === $e, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function section($t) { echo "\n\033[1m$t\033[0m\n"; }

const KEY  = 'K3y-256-chars-stand-in-secret';
const USR  = 'ic_user1';
const TOK  = 'tok0123456789abcdef';
const SESS = 'sess-cookie-value';

function setup_creds() {
    $GLOBALS['_options'] = [];
    $GLOBALS['_transients'] = [];
    update_option('tis_ic_user', USR);
    update_option('tis_ic_password', KEY);
    update_option('tis_pickup_pincode', '642001');
}
setup_creds();

$http = new TS_Http_Fake();
$p    = new TS_Provider_ICarry_Test($http, new TS_Logger_Null());

$login_ok = ['code' => 200, 'body' => ['success' => 'ok', 'api_token' => TOK], 'cookies' => ['OCSESSID' => SESS]];

$domestic = ['code' => 200, 'body' => ['success' => 1, 'error' => '', 'estimate' => [
    'Alpha Surface' => ['courier_group_name' => 'Alpha Surface', 'courier_name' => 'Alpha Surface 0.5Kg', 'courier_id' => '159',
        'courier_cost' => '80.00', 'cod_cost' => '0', 'freight_cost' => '80.00', 'rto_cost' => '70.00', 'gst' => '14.40'],
    'Beta Surface'  => ['courier_group_name' => 'Beta Surface', 'courier_name' => 'Beta Surface 0.5Kg', 'courier_id' => '88',
        'courier_cost' => '65.50', 'cod_cost' => '0', 'freight_cost' => '65.50', 'rto_cost' => '65.50', 'gst' => '11.79'],
]]];

$international = ['code' => 200, 'body' => ['success' => 1, 'estimates' => [
    ['courier_name' => 'Gamma Intl', 'courier_id' => '17-34', 'total' => 2156.7, 'shipping' => 1827.71, 'tax' => 328.99, 'surcharge' => 0, 'fsc' => 0],
    ['courier_name' => 'Delta Intl', 'courier_id' => '9-2',   'total' => 1900.0, 'shipping' => 1610.17, 'tax' => 289.83, 'surcharge' => 0, 'fsc' => 0],
]]];

/* =================================================================== */
section('Identity and capability');
is_same('id', $p->id(), 'icarry');
ok('label says beta', false !== strpos($p->label(), 'beta'));
ok('serves domestic', $p->supports('domestic'));
ok('serves international', $p->supports('international'));
ok('order sync not built yet', !$p->supports('order_sync'));
ok('has credentials', $p->has_credentials());
ok('credentials missing -> none', (function () { $GLOBALS['_options'] = []; $r = !(new TS_Provider_ICarry(new TS_Http_Fake()))->has_credentials(); setup_creds(); return $r; })());

section('serves(): domestic needs a six-digit pincode');
ok('IN with a valid pincode', $p->serves(['country' => 'IN', 'postcode' => '560001']));
ok('IN with spaces in the pincode', $p->serves(['country' => 'IN', 'postcode' => '560 001']));
ok('IN with no pincode yet', !$p->serves(['country' => 'IN', 'postcode' => '']));
ok('IN with a short pincode', !$p->serves(['country' => 'IN', 'postcode' => '5600']));
ok('IN with letters', !$p->serves(['country' => 'IN', 'postcode' => 'ABCDEF']));
ok('US needs no pincode', $p->serves(['country' => 'US', 'postcode' => '']));
ok('no country yet', !$p->serves(['country' => '', 'postcode' => '']));

section('Login follows the documented contract');
$http->requests = []; $http->push($login_ok);
$t = $p->login(true);
ok('returns a token', is_string($t) && '' !== $t);
$req = $http->requests[0];
is_same('one request', count($http->requests), 1);
is_same('it is a form POST', $req['method'], 'POST_FORM');
is_same('to api_login', $req['url'], 'https://www.icarry.in/api_login');
is_same('with username and key fields only', $req['body'], ['username' => USR, 'key' => KEY]);
$cached = $p->cached_token();
ok('token cached', is_string($cached));
$packed = json_decode($cached, true);
is_same('api_token kept', $packed['t'], TOK);
is_same('session cookie kept alongside it', $packed['c'], 'OCSESSID=' . SESS);
$ttl = $GLOBALS['_transients']['ts_icarry_token'][1] - time();
ok('cached for about 55 minutes (token lives 60)', $ttl > 54 * 60 && $ttl <= 55 * 60, "ttl $ttl");

section('Login failures are readable and never leak the key');
$p->forget_token(); $p->clear_failures();
$http->push(['code' => 200, 'body' => ['error' => 'Invalid username and key combination. Client not found.']]);
$r = $p->login(true);
ok('string error surfaced', is_array($r) && false !== strpos($r['error'], 'Client not found'));
$http->push(['code' => 200, 'body' => ['error' => ['ip' => 'Your IP 203.0.113.9 is not allowed to access this API!', 'key' => 'Incorrect API Key']]]);
$r = $p->login(true);
ok('object error flattened', is_array($r) && false !== strpos($r['error'], 'not allowed') && false !== strpos($r['error'], 'Incorrect API Key'));
$http->push(['code' => 200, 'body' => ['error' => 'bad key ' . KEY]]);
$r = $p->login(true);
ok('a key echoed back by the server is redacted', false === strpos(json_encode($r), KEY) && false !== strpos($r['error'], '[REDACTED]'));
$http->push(['error' => 'connect timeout using ' . KEY]);
$r = $p->login(true);
ok('transport errors are redacted too', false === strpos(json_encode($r), KEY));
$http->push(['code' => 200, 'body' => ['success' => 'ok']]);
ok('a login with no token is an error', isset($p->login(true)['error']));
$p->clear_failures();

section('Failures lock out after three, like every provider');
for ($i = 0; $i < 3; $i++) { $http->push(['code' => 200, 'body' => ['error' => 'nope']]); $p->login(true); }
ok('lockout active', $p->auth_status()['blocked_for'] > 0);
$before = count($http->requests);
$p->token();
is_same('and no further call is made', count($http->requests), $before);
$p->clear_failures();

section('Checkout never logs in');
$p->forget_token();
$before = count($http->requests);
$rows = $p->rates(['country' => 'IN', 'postcode' => '628008'], 0.5, ['cod' => false]);
is_same('no token -> no rates', $rows, []);
is_same('and no HTTP call at all', count($http->requests), $before);

section('Domestic estimate: request');
$http->push($login_ok); $p->login(true);
$http->requests = [];
$http->push($domestic);
$rows = $p->rates(['country' => 'IN', 'postcode' => '628008'], 0.5, ['cod' => false]);
$req = $http->requests[0];
is_same('form POST', $req['method'], 'POST_FORM');
is_same('documented URL shape, token after "&"', $req['url'], 'https://www.icarry.in/api_get_estimate&api_token=' . TOK);
is_same('session cookie sent', $req['headers']['Cookie'] ?? null, 'OCSESSID=' . SESS);
$f = $req['body'];
is_same('weight in whole grams', $f['weight'], 500);
ok('dimensions are integers', is_int($f['length']) && is_int($f['breadth']) && is_int($f['height']));
is_same('origin pincode from settings', $f['origin_pincode'], '642001');
is_same('destination pincode', $f['destination_pincode'], '628008');
is_same('both countries IN', [$f['origin_country_code'], $f['destination_country_code']], ['IN', 'IN']);
is_same('surface by default', $f['shipment_mode'], 'S');
is_same('prepaid', $f['shipment_type'], 'P');
ok('a declared value is sent', isset($f['shipment_value']) && $f['shipment_value'] > 0);
ok('no international-only fields on a domestic call', !isset($f['destination_country_code']) || 'IN' === $f['destination_country_code']);

section('Domestic estimate: parsing');
is_same('two couriers', count($rows), 2);
is_same('cheapest first', $rows[0]['courier_name'], 'Beta Surface 0.5Kg');
is_same('GST added by default (65.50 + 11.79)', $rows[0]['rate'], 77.29);
is_same('provider stamped', $rows[0]['provider'], 'icarry');
is_same('service type from the mode', $rows[0]['service_type'], 'surface');
is_same('RTO charge carried', $rows[0]['rto_charge'], 65.5);
is_same('no transit time (the API gives none)', $rows[0]['transit_days'], null);
is_same('no EDD either', $rows[0]['edd'], null);
is_same('id carries the mode so two modes never collide', $rows[0]['courier_id'], '88-s');
foreach (['courier_id','courier_name','rate','transit_days','edd','provider','service_type','cod_available','rto_charge','delivery_rate'] as $k) {
    ok("row has '$k'", array_key_exists($k, $rows[0]));
}
update_option('tis_ic_include_gst', 'no');
$no_gst = $p->parse_rates($domestic['body']);
is_same('GST can be switched off', $no_gst[0]['rate'], 65.5);
update_option('tis_ic_include_gst', 'yes');

section('International estimate');
$http->requests = []; $http->push($international);
$rows = $p->rates(['country' => 'US', 'postcode' => ''], 0.5, []);
$req = $http->requests[0];
is_same('international endpoint', $req['url'], 'https://www.icarry.in/api_get_estimate_international&api_token=' . TOK);
is_same('country code sent', $req['body']['destination_country_code'], 'US');
is_same('weight in grams', $req['body']['weight'], 500);
ok('no domestic-only fields', !isset($req['body']['shipment_mode']) && !isset($req['body']['shipment_type']) && !isset($req['body']['destination_pincode']));
is_same('cheapest first', $rows[0]['courier_name'], 'Delta Intl');
is_same('total already includes tax', $rows[0]['rate'], 1900.0);
is_same('courier id kept as given', $rows[0]['courier_id'], '9-2');
$flat = $p->parse_rates(['estimates' => [['courier_name' => 'X', 'courier_id' => '1', 'shipping' => 100, 'tax' => 18, 'surcharge' => 2, 'fsc' => 5]]]);
is_same('total rebuilt from parts when absent', $flat[0]['rate'], 125.0);

section('Errors inside a 200 response are errors, not empty lanes');
$p->forget_token(); $http->push($login_ok); $p->login(true);
$http->push(['code' => 200, 'body' => ['error' => 'Destination pincode is not serviceable']]);
$r = $p->request(['country' => 'IN', 'postcode' => '999999'], 0.5, [], $p->cached_token(), 5);
ok('reported as an error', !empty($r['error']) && false !== strpos($r['error'], 'not serviceable'));
ok('NOT cached as an empty lane', false === $p->get_cached(['country' => 'IN', 'postcode' => '999999'], 0.5, ['cod' => false]));
ok('a non-auth error keeps the token', (bool) $p->cached_token());
$http->push(['code' => 200, 'body' => ['error' => 'Invalid api_token']]);
$p->request(['country' => 'IN', 'postcode' => '628008'], 0.5, [], $p->cached_token(), 5);
is_same('an auth-looking error drops the token', $p->cached_token(), false);
$http->push($login_ok); $p->login(true);
$http->push(['code' => 200, 'body' => ['success' => 1, 'error' => '', 'estimate' => []]]);
$r = $p->request(['country' => 'IN', 'postcode' => '628008'], 0.5, [], $p->cached_token(), 5);
ok('an empty error string with no couriers is a normal empty lane', empty($r['error']) && [] === $r['rates']);

section('The token never reaches a log, a result or a diagnostic');
$http->push($login_ok); $p->login(true);
$http->push($domestic);
$r = $p->request(['country' => 'IN', 'postcode' => '628008'], 0.5, [], $p->cached_token(), 5);
ok('returned URL carries no token', false === strpos($r['url'], TOK) && false === strpos($r['url'], 'api_token'));
ok('last_success holds no token', false === strpos(json_encode($p->last_success()), TOK));
$http->push(['code' => 500, 'body' => null]);
$p->request(['country' => 'IN', 'postcode' => '628008'], 0.5, [], $p->cached_token(), 5);
ok('last_error holds no token or key', false === strpos(json_encode($p->last_error()), TOK) && false === strpos(json_encode($p->last_error()), KEY));
ok('no log line contains the token or key', !array_filter($GLOBALS['_logs'], function ($l) { return false !== strpos(json_encode($l), TOK) || false !== strpos(json_encode($l), KEY); }));

section('Multiple modes');
update_option('tis_ic_modes', 'S,E');
$p->forget_token(); $http->push($login_ok); $p->login(true);
$http->requests = [];
$http->push($domestic)->push(['code' => 200, 'body' => ['success' => 1, 'error' => '', 'estimate' => [
    'Alpha Air' => ['courier_name' => 'Alpha Air', 'courier_id' => '159', 'courier_cost' => '120.00', 'cod_cost' => '0', 'freight_cost' => '120.00', 'rto_cost' => '100', 'gst' => '21.60'],
]]]);
$rows = $p->rates(['country' => 'IN', 'postcode' => '411001'], 1.0, []);
is_same('one call per mode', count($http->requests), 2);
is_same('modes sent', [$http->requests[0]['body']['shipment_mode'], $http->requests[1]['body']['shipment_mode']], ['S', 'E']);
is_same('rows from both modes', count($rows), 3);
$ids = array_column($rows, 'courier_id');
is_same('same courier in two modes gets distinct ids', count($ids), count(array_unique($ids)));
ok('express labelled', in_array('express', array_column($rows, 'service_type'), true));
$http->requests = [];
$http->push($domestic)->push(['code' => 200, 'body' => ['error' => 'express unavailable']]);
$rows = $p->rates(['country' => 'IN', 'postcode' => '411002'], 1.0, []);
is_same('one mode failing still quotes with the other', count($rows), 2);
$http->push(['code' => 200, 'body' => ['error' => 'down']])->push(['code' => 200, 'body' => ['error' => 'down']]);
$r = $p->request(['country' => 'IN', 'postcode' => '411003'], 1.0, [], $p->cached_token(), 5);
ok('all modes failing is a failure', !empty($r['error']));
update_option('tis_ic_modes', 'S');
$p->reset_circuit();

section('COD and declared value');
$http->requests = [];
$http->push($domestic);
$p->rates(['country' => 'IN', 'postcode' => '600001'], 0.5, ['cod' => true, 'value' => 730]);
$f = $http->requests[0]['body'];
is_same('COD sent', $f['shipment_type'], 'C');
is_same('value rounded up to a 500 slab', $f['shipment_value'], 1000);
$k1 = $p->cache_key(['country' => 'IN', 'postcode' => '600001'], 0.5, ['cod' => true, 'value' => 730]);
$k2 = $p->cache_key(['country' => 'IN', 'postcode' => '600001'], 0.5, ['cod' => true, 'value' => 990]);
$k3 = $p->cache_key(['country' => 'IN', 'postcode' => '600001'], 0.5, ['cod' => true, 'value' => 1100]);
is_same('values in the same slab share a cache entry', $k1, $k2);
ok('a different slab does not', $k1 !== $k3);
$kp1 = $p->cache_key(['country' => 'IN', 'postcode' => '600001'], 0.5, ['cod' => false, 'value' => 100]);
$kp2 = $p->cache_key(['country' => 'IN', 'postcode' => '600001'], 0.5, ['cod' => false, 'value' => 9000]);
is_same('prepaid ignores value (it does not change freight)', $kp1, $kp2);
ok('COD and prepaid never share an entry', $kp1 !== $k1);

section('Cache keys');
$d1 = $p->cache_key(['country' => 'IN', 'postcode' => '600001'], 0.5, []);
$d2 = $p->cache_key(['country' => 'IN', 'postcode' => '600002'], 0.5, []);
ok('domestic is pincode level', $d1 !== $d2);
$i1 = $p->cache_key(['country' => 'US', 'postcode' => '90210'], 0.5, []);
$i2 = $p->cache_key(['country' => 'US', 'postcode' => '10001'], 0.5, []);
is_same('international is country level', $i1, $i2);
update_option('tis_ic_modes', 'S,E');
ok('modes change the key', $p->cache_key(['country' => 'IN', 'postcode' => '600001'], 0.5, []) !== $d1);
update_option('tis_ic_modes', 'S');
update_option('tis_ic_include_gst', 'no');
ok('GST setting changes the key', $p->cache_key(['country' => 'IN', 'postcode' => '600001'], 0.5, []) !== $d1);
update_option('tis_ic_include_gst', 'yes');
ok('key is namespaced away from Shiprocket', 0 === strpos($d1, 'ts_icarry_cache'));

section('Bounded live call and negative caching still apply');
$http->push(['code' => 200, 'body' => ['success' => 1, 'error' => '', 'estimate' => []]]);
$p->rates(['country' => 'IN', 'postcode' => '700001'], 0.5, []);
$kk = $p->cache_key(['country' => 'IN', 'postcode' => '700001'], 0.5, ['cod' => false, 'allow_live' => true, 'value' => 0]);
$left = $GLOBALS['_transients'][$kk][1] - time();
ok('an empty lane is remembered for about an hour, not 26', $left <= HOUR_IN_SECONDS && $left > 0, "ttl $left");
$http->requests = [];
$http->push(['error' => 'cURL timeout']);
$r = $p->rates(['country' => 'IN', 'postcode' => '700002'], 0.5, []);
is_same('a timeout returns nothing', $r, []);
is_same('after a single attempt', count($http->requests), 1);
ok('and trips the breaker', $p->circuit_is_open());
$p->reset_circuit();

section('Guard rails on the request itself');
update_option('tis_pickup_pincode', ''); update_option('woocommerce_store_postcode', '');
$r = $p->request(['country' => 'IN', 'postcode' => '628008'], 0.5, [], TOK, 5);
ok('no pickup pincode -> a clear error, no call', !empty($r['error']) && false !== strpos($r['error'], 'pickup'));
update_option('tis_pickup_pincode', '642001');
$before = count($http->requests);
$r = $p->request(['country' => 'IN', 'postcode' => '12'], 0.5, [], TOK, 5);
ok('a bad domestic pincode is refused locally', !empty($r['error']));
is_same('without calling iCarry', count($http->requests), $before);

section('Warming');
update_option('tis_warm_countries', 'US,IN,GB'); update_option('tis_warm_slabs', '0.5,1');
$lanes = $p->warm_lanes();
is_same('international lanes only (domestic cannot be warmed exhaustively)', count($lanes), 4);
ok('India is not warmed', !in_array('IN', array_map(function ($l) { return $l['destination']['country']; }, $lanes), true));

section('Token refresh');
is_same('iCarry asks for a 40 minute refresh', $p->refresh_interval(), 40 * MINUTE_IN_SECONDS);
is_same('Shiprocket does not', (new TS_Provider_Shiprocket_Test(new TS_Http_Fake(), new TS_Logger_Null()))->refresh_interval(), 0);
ok('cached copy outlives the refresh interval (overlap)', TS_Provider_ICarry::TOKEN_TTL > $p->refresh_interval());
ok('and stays inside the 60 minutes iCarry documents', TS_Provider_ICarry::TOKEN_TTL < 60 * MINUTE_IN_SECONDS);
ok('the schedule is registered', isset(apply_filters('cron_schedules', [])['tis_every_40_minutes']));

$GLOBALS['_cron'] = [];
TS_Provider_Registry::reset(); TS_Provider_Registry::register($p);
update_option('ts_active_provider', 'icarry');
tis_sync_token_refresh_schedule();
ok('scheduled when iCarry is active', false !== wp_next_scheduled(TIS_REFRESH_HOOK));
is_same('on the 40 minute schedule', $GLOBALS['_cron'][TIS_REFRESH_HOOK]['recurrence'], 'tis_every_40_minutes');
$before = $GLOBALS['_cron'][TIS_REFRESH_HOOK]['timestamp'];
tis_sync_token_refresh_schedule();
is_same('calling again does not reschedule', $GLOBALS['_cron'][TIS_REFRESH_HOOK]['timestamp'], $before);

$http->requests = []; $http->push($login_ok);
tis_refresh_provider_token();
is_same('the job logs in', count($http->requests), 1);
$http->push(['code' => 200, 'body' => ['error' => 'bad']]);
tis_refresh_provider_token();
ok('a failed refresh is logged, never fatal', (bool) array_filter($GLOBALS['_logs'], function ($l) { return false !== strpos($l[1], 'Background token refresh failed'); }));
$p->clear_failures();

$sr = new TS_Provider_Shiprocket_Test(new TS_Http_Fake(), new TS_Logger_Null());
TS_Provider_Registry::reset(); TS_Provider_Registry::register($sr); TS_Provider_Registry::register($p);
delete_option('ts_active_provider');
tis_sync_token_refresh_schedule();
ok('unscheduled when a provider that does not need it is active', false === wp_next_scheduled(TIS_REFRESH_HOOK));

section('Registry only offers iCarry once it has credentials');
TS_Provider_Registry::reset();
$GLOBALS['_options'] = [];
// A database-free Shiprocket, installed first so boot() does not create the real one.
TS_Provider_Registry::register(new TS_Provider_Shiprocket_Test(new TS_Http_Fake(), new TS_Logger_Null()));
TS_Provider_Registry::boot();
ok('absent without credentials', !TS_Provider_Registry::exists('icarry'));
ok('Shiprocket still there', TS_Provider_Registry::exists('shiprocket'));
$before = TS_Provider_Registry::activate('icarry');
ok('and cannot be switched to', empty($before['ok']));
setup_creds();
TS_Provider_Registry::register_optional_providers();
ok('present once credentials exist', TS_Provider_Registry::exists('icarry'));
$sw = TS_Provider_Registry::activate('icarry');
ok('then it can be activated', !empty($sw['ok']) && 'icarry' === TS_Provider_Registry::active_id());

section('Base class change is invisible to Shiprocket');
ok('Shiprocket still sends a GET with a Bearer header', (function () {
    $h = new TS_Http_Fake();
    $s = new TS_Provider_Shiprocket_Test($h, new TS_Logger_Null());
    update_option('tis_pickup_pincode', '110001');
    $h->push(['code' => 200, 'body' => ['data' => ['available_courier_companies' => []]]]);
    $s->request(['country' => 'US'], 0.5, [], 'sr_tok', 5);
    return 'GET' === $h->requests[0]['method'] && 'Bearer sr_tok' === ($h->requests[0]['headers']['Authorization'] ?? '');
})());

echo "\n" . str_repeat('-', 56) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
