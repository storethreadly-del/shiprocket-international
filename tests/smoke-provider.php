<?php
/**
 * Standalone smoke harness for the provider layer.
 *
 *     php tests/smoke-provider.php
 *
 * No PHPUnit, no WordPress, no network — see tests/wp-stubs.php for why.
 * Exits non-zero on the first failure so it can gate a commit or a CI job.
 *
 * The headline check is CACHE-KEY PARITY: the Shiprocket provider must produce
 * byte-identical cache keys to v0.2.0, or upgrading throws away a warm rate
 * cache and every international checkout goes cold at once.
 *
 * @package Threadly_Intl_Shipping
 */

require __DIR__ . '/wp-stubs.php';

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

function is_same($label, $actual, $expected) {
    ok($label, $actual === $expected, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function section($t) { echo "\n\033[1m$t\033[0m\n"; }

/* ===================================================================
 * v0.2.0 reference implementations, copied verbatim from the tag.
 * These are the oracle: the refactor must agree with them.
 * =================================================================== */

function v020_cache_key($pickup, $country, $weight, $cod) {
    return 'tis_rates_' . md5($pickup . '|' . $country . '|' . $weight . '|' . $cod);
}

$http     = new TS_Http_Fake();
$provider = new TS_Provider_Shiprocket_Test($http, new TS_Logger_Null());

// Install it before anything can boot the real one, so that every path —
// including the deprecated shims, which resolve through the registry — is
// driven by the stubbed HTTP client rather than reaching for the network.
TS_Provider_Registry::reset();
TS_Provider_Registry::register($provider);

update_option('tis_pickup_pincode', '110001');

/* =================================================================== */
section('Identity and capability');

is_same('id is stable', $provider->id(), 'shiprocket');
ok('declares international', $provider->supports('international'));
ok('declines domestic (official Shiprocket plugin owns India)', !$provider->supports('domestic'));
ok('declines order_sync (not built until phase 5)', !$provider->supports('order_sync'));

section('serves() reproduces the v0.2.0 country guard');

ok('serves US', $provider->serves(['country' => 'US', 'postcode' => '']));
ok('refuses IN', !$provider->serves(['country' => 'IN', 'postcode' => '']));
ok('refuses empty country (no address entered yet)', !$provider->serves(['country' => '', 'postcode' => '']));
ok('refuses missing country key', !$provider->serves([]));
ok('case-insensitive on IN', !$provider->serves(['country' => 'in']));

section('Cache-key parity with v0.2.0');

foreach ([['US', 0.5], ['GB', 1.0], ['AE', 2.5], ['SG', 5.0]] as list($country, $weight)) {
    is_same(
        "key matches for $country @ {$weight}kg",
        $provider->cache_key(['country' => $country, 'postcode' => ''], $weight, ['cod' => false]),
        v020_cache_key('110001', $country, $weight, 0)
    );
}

is_same(
    'key matches with cod on',
    $provider->cache_key(['country' => 'US', 'postcode' => ''], 1.5, ['cod' => true]),
    v020_cache_key('110001', 'US', 1.5, 1)
);

is_same(
    'postcode is ignored (international rating is country-level)',
    $provider->cache_key(['country' => 'US', 'postcode' => '90210'], 0.5, ['cod' => false]),
    $provider->cache_key(['country' => 'US', 'postcode' => ''], 0.5, ['cod' => false])
);

is_same(
    'legacy TIS_API::cache_key() shim agrees',
    TIS_API::cache_key('US', 0.5, 0),
    v020_cache_key('110001', 'US', 0.5, 0)
);

section('Legacy storage keys are preserved');

$reflect = new ReflectionMethod('TS_Provider_Shiprocket_Test', 'key');
$reflect->setAccessible(true);
foreach ([
    'token'        => 'tis_sr_token',
    'fails'        => 'tis_sr_auth_fails',
    'blocked'      => 'tis_sr_auth_blocked',
    'lock'         => 'tis_sr_auth_lock',
    'circuit'      => 'tis_circuit_open',
    'cache'        => 'tis_rates_',
    'last_success' => 'tis_last_success',
    'last_error'   => 'tis_last_error',
] as $logical => $expected) {
    is_same("key('$logical')", $reflect->invoke($provider, $logical), $expected);
}

section('Rate parsing');

$payload = ['data' => ['available_courier_companies' => [
    ['courier_company_id' => 51, 'courier_name' => 'DHL Express', 'rate' => 2400, 'estimated_delivery_days' => '5', 'etd' => '2026-11-25', 'service_type' => 'express', 'cod' => 1],
    ['courier_company_id' => 12, 'courier_name' => 'Aramex',      'rate' => 1800, 'transit_time' => 7],
    ['courier_company_id' => 99, 'courier_name' => 'Unavailable', 'rate' => 900,  'available' => false],
    ['courier_company_id' => 77, 'courier_name' => 'Zero Rate',   'rate' => 0],
]]];

$rows = $provider->parse_rates($payload);

is_same('drops unavailable and zero-rate couriers', count($rows), 2);
is_same('sorted cheapest first', $rows[0]['courier_name'], 'Aramex');
is_same('rate is a float', $rows[0]['rate'], 1800.0);
is_same('transit falls back across key aliases', $rows[0]['transit_days'], 7);
is_same('edd read when present', $rows[1]['edd'], '2026-11-25');
is_same('provider stamped on every row', $rows[0]['provider'], 'shiprocket');
is_same('service_type captured', $rows[1]['service_type'], 'express');
is_same('service_type defaults to empty string', $rows[0]['service_type'], '');
is_same('cod_available read', $rows[1]['cod_available'], true);
is_same('cod_available defaults false', $rows[0]['cod_available'], false);

foreach (['courier_id','courier_name','rate','transit_days','edd','provider','service_type','cod_available'] as $k) {
    ok("row carries '$k'", array_key_exists($k, $rows[0]));
}

$flat = $provider->parse_rates(['available_courier_companies' => [
    ['courier_company_id' => 3, 'courier_name' => 'Flat Envelope', 'freight_charge' => 1200],
]]);
is_same('handles the un-nested envelope', count($flat), 1);
is_same('falls back to freight_charge', $flat[0]['rate'], 1200.0);

is_same('garbage in, empty list out', $provider->parse_rates('nonsense'), []);
is_same('null in, empty list out', $provider->parse_rates(null), []);

section('Checkout must never trigger a login');

delete_transient('tis_sr_token');
update_option('tis_sr_email', 'intl-api@example.test');
update_option('tis_sr_password', 'secret');

$before = count($http->requests);
$rates  = $provider->rates(['country' => 'US', 'postcode' => ''], 0.5, ['cod' => false]);

is_same('no rates served without a warm token', $rates, []);
is_same('and no HTTP call was made at all', count($http->requests), $before);

section('Rate URL construction');

$url_method = new ReflectionMethod('TS_Provider_Shiprocket_Test', 'rate_url');
$url_method->setAccessible(true);
$url = $url_method->invoke($provider, ['country' => 'US', 'postcode' => ''], 0.5, ['cod' => false]);

ok('hits the international serviceability endpoint', false !== strpos($url, 'courier/international/serviceability/'));
ok('sends pickup_postcode', false !== strpos($url, 'pickup_postcode=110001'));
ok('sends delivery_country', false !== strpos($url, 'delivery_country=US'));
ok('sends weight', false !== strpos($url, 'weight=0.5'));
ok('sends cod=0', false !== strpos($url, 'cod=0'));

update_option('tis_pickup_pincode', '');
update_option('woocommerce_store_postcode', '');
$err = $url_method->invoke($provider, ['country' => 'US'], 0.5, ['cod' => false]);
ok('refuses to build a URL with no pickup pincode', is_array($err) && !empty($err['error']));
update_option('tis_pickup_pincode', '110001');

section('Authentication, lockout and the login mutex');

$provider->clear_failures();
$provider->forget_token();

$http->push(['code' => 200, 'body' => ['token' => 'tok_abc']]);
$token = $provider->login(true);
is_same('login returns the token', $token, 'tok_abc');
is_same('token is cached under the legacy key', get_transient('tis_sr_token'), 'tok_abc');
ok('mutex was released', false === get_option('tis_sr_auth_lock', false));

$provider->forget_token();
is_same('forget_token clears it', $provider->cached_token(), false);

// Three consecutive rejections must trip the lockout.
$provider->clear_failures();
for ($i = 0; $i < 3; $i++) {
    $http->push(['code' => 403, 'body' => ['message' => 'Wrong email or password']]);
    $result = $provider->login(true);
    ok('rejected login #' . ($i + 1) . ' returns an error', is_array($result) && isset($result['error']));
}

$status = $provider->auth_status();
is_same('failure counter reached MAX_FAILS', $status['fails'], 3);
ok('lockout is now active', $status['blocked_for'] > 0);

$before = count($http->requests);
$result = $provider->token();
ok('token() refuses to call out while locked out', is_array($result) && isset($result['error']));
is_same('and made no HTTP request', count($http->requests), $before);

$provider->clear_failures();
is_same('clear_failures lifts the lockout', $provider->auth_status()['blocked_for'], 0);

section('Circuit breaker');

$provider->reset_circuit();
ok('starts closed', !$provider->circuit_is_open());

$trip = new ReflectionMethod('TS_Provider_Base', 'trip_circuit');
$trip->setAccessible(true);
$trip->invoke($provider);
ok('opens when tripped', $provider->circuit_is_open());
is_same('stored under the legacy key', (int) get_transient('tis_circuit_open'), 1);

$http->push(['code' => 200, 'body' => ['token' => 'tok_xyz']]);
$provider->login(true);
$before = count($http->requests);
is_same('an open circuit suppresses live rating', $provider->rates(['country' => 'US'], 0.5, []), []);
is_same('with no HTTP call', count($http->requests), $before);

$provider->reset_circuit();
ok('closes on reset', !$provider->circuit_is_open());

section('Warm lanes');

update_option('tis_warm_countries', 'US,GB,AE');
update_option('tis_warm_slabs', '0.5,1,2');
$lanes = $provider->warm_lanes();

is_same('countries x slabs', count($lanes), 9);
is_same('lane carries a destination array', $lanes[0]['destination'], ['country' => 'US', 'postcode' => '']);
ok('international lanes are never COD', !$lanes[0]['cod']);

section('Registry');

is_same('resolves shiprocket by default', TS_Provider_Registry::active_id(), 'shiprocket');
ok('boot() did not clobber the pre-registered provider', TS_Provider_Registry::get('shiprocket') === $provider);
ok('active() returns a provider', TS_Provider_Registry::active() instanceof TS_Provider);

update_option('ts_active_provider', 'icarry');
is_same('falls back when the configured provider is unregistered', TS_Provider_Registry::active_id(), 'shiprocket');
ok('and says so in the log', (bool) array_filter($GLOBALS['_logs'], function ($l) {
    return false !== strpos($l[1], 'not registered');
}));
delete_option('ts_active_provider');

add_filter('ts_active_provider', function () { return 'shiprocket'; });
is_same('filter is honoured', TS_Provider_Registry::active_id(), 'shiprocket');
remove_all_filters('ts_active_provider');

section('Deprecated shims still work');

$http->push(['code' => 200, 'body' => ['token' => 'tok_shim']]);
TIS_Auth::forget();
is_same('TIS_Auth::token() delegates', TIS_Auth::token(), 'tok_shim');
is_same('TIS_Auth::cached_token() delegates', TIS_Auth::cached_token(), 'tok_shim');
ok('TIS_Auth::has_credentials() delegates', TIS_Auth::has_credentials());
ok('TIS_Auth::status() returns the auth status shape', array_key_exists('blocked_for', TIS_Auth::status()));
is_same('TIS_Auth::TOKEN_KEY unchanged', TIS_Auth::TOKEN_KEY, 'tis_sr_token');
is_same('TIS_API::pickup_pincode() delegates', TIS_API::pickup_pincode(), '110001');
ok('TIS_API::circuit_is_open() delegates', !TIS_API::circuit_is_open());
is_same('TIS_API::parse_rates() delegates', count(TIS_API::parse_rates($payload)), 2);

section('Switch lifecycle (phase 2)');

$fake = new TS_Provider_Fake(new TS_Http_Fake(), new TS_Logger_Null());
TS_Provider_Registry::register($fake);

// Unknown target must be refused outright: a typo cannot be allowed to leave
// the store with no provider at all.
$before_id = TS_Provider_Registry::active_id();
$result    = TS_Provider_Registry::activate('nope');
ok('refuses an unregistered target', empty($result['ok']) && !empty($result['error']));
is_same('and leaves the active provider alone', TS_Provider_Registry::active_id(), $before_id);

// Switching to the already-active provider is a no-op, not a destructive
// re-apply — it must not flush a warm cache.
$provider->flushes = 0;
$result = TS_Provider_Registry::activate('shiprocket');
ok('no-op when already active', !empty($result['ok']) && empty($result['changed']));
is_same('and flushes nothing', $provider->flushes, 0);

// Seed state on the outgoing provider so we can watch it stand down.
$http->push(['code' => 200, 'body' => ['token' => 'tok_outgoing']]);
$provider->login(true);
$provider->set_cached(['country' => 'US', 'postcode' => ''], 0.5, ['cod' => false], [['courier_name' => 'X']]);
$trip->invoke($provider);

ok('outgoing has a token before the switch', (bool) $provider->cached_token());
ok('outgoing has a cached rate before the switch', is_array($provider->get_cached(['country' => 'US'], 0.5, ['cod' => false])));
ok('outgoing circuit is open before the switch', $provider->circuit_is_open());

$GLOBALS['_cron']    = [];
$GLOBALS['_actions'] = [];
$provider->flushes   = 0;

$result = TS_Provider_Registry::activate('fakecarry');

ok('switch applied', !empty($result['ok']) && !empty($result['changed']));
is_same('reports where it came from', $result['from'], 'shiprocket');
is_same('reports where it went', $result['to'], 'fakecarry');
is_same('active provider is now the new one', TS_Provider_Registry::active_id(), 'fakecarry');
is_same('option was persisted', get_option('ts_active_provider'), 'fakecarry');
is_same('no warnings when the target is configured', $result['warnings'], []);

is_same('outgoing rate cache was flushed', $provider->flushes, 1);
is_same('outgoing cached rate is gone', $provider->get_cached(['country' => 'US'], 0.5, ['cod' => false]), false);
is_same('outgoing token was dropped', $provider->cached_token(), false);
ok('outgoing circuit was reset', !$provider->circuit_is_open());

ok('outgoing credentials were KEPT (switching back needs no re-entry)', $provider->has_credentials());
is_same('outgoing email still stored', get_option('tis_sr_email'), 'intl-api@example.test');

$next = wp_next_scheduled(TIS_CRON_HOOK);
ok('warm cron was brought forward', $next && $next <= time() + (2 * MINUTE_IN_SECONDS));
is_same('as a daily recurrence', $GLOBALS['_cron'][TIS_CRON_HOOK]['recurrence'], 'daily');

$switched = array_values(array_filter($GLOBALS['_actions'], function ($a) {
    return 'ts_provider_switched' === $a['hook'];
}));
is_same('ts_provider_switched fired once', count($switched), 1);
is_same('with ($from, $to)', $switched[0]['args'], ['shiprocket', 'fakecarry']);

section('Mutual exclusion holds by construction');

$active = TS_Provider_Registry::active();
is_same('exactly one provider is active', $active->id(), 'fakecarry');
ok('the other is simply not consulted', TS_Provider_Registry::get('shiprocket')->id() !== $active->id());

section('Capability-driven serviceability changes with the provider');

ok('new provider serves domestic India', $active->serves(['country' => 'IN', 'postcode' => '110001']));
ok('previous provider still refuses it', !$provider->serves(['country' => 'IN', 'postcode' => '110001']));
ok('both serve international', $active->serves(['country' => 'US']) && $provider->serves(['country' => 'US']));

section('Pincode-level warm lanes');

$lanes = $active->warm_lanes();
ok('lanes are a bounded list, not countries x slabs', count($lanes) === 3);
is_same('domestic lane carries a postcode', $lanes[0]['destination']['postcode'], '110001');
ok('domestic lanes can be COD', !empty($lanes[0]['cod']));
ok('international lane is not COD', empty($lanes[2]['cod']));
ok('pincode is part of the cache key for a pincode-level provider',
    $active->cache_key(['country' => 'IN', 'postcode' => '110001'], 0.5, ['cod' => false])
    !== $active->cache_key(['country' => 'IN', 'postcode' => '400001'], 0.5, ['cod' => false]));

section('Switching to a provider with no credentials warns but still applies');

$fake->credentials_configured = false;
TS_Provider_Registry::activate('shiprocket');
$result = TS_Provider_Registry::activate('fakecarry');

ok('switch still applied', !empty($result['ok']) && !empty($result['changed']));
is_same('one warning raised', count($result['warnings']), 1);
ok('naming the fallback consequence', false !== strpos($result['warnings'][0], 'fallback'));
$fake->credentials_configured = true;

section('A ts_active_provider filter outranking the setting is reported');

TS_Provider_Registry::activate('shiprocket');
add_filter('ts_active_provider', function () { return 'shiprocket'; });
$result = TS_Provider_Registry::activate('fakecarry');

ok('the setting was still saved', !empty($result['ok']) && 'fakecarry' === get_option('ts_active_provider'));
ok('but the override is surfaced as a warning', (bool) array_filter($result['warnings'], function ($w) {
    return false !== strpos($w, 'filter is forcing');
}));
is_same('and checkout follows the filter, not the setting', TS_Provider_Registry::active_id(), 'shiprocket');
remove_all_filters('ts_active_provider');

section('Bounded live call on a cold lane');

// A provider with a handle on its HTTP double, so the live path can be driven.
$live_http = new TS_Http_Fake();
$live      = new TS_Provider_Fake($live_http, new TS_Logger_Null());

/** TTL actually recorded for a cache key, in seconds. */
function ttl_of($key) {
    if (!isset($GLOBALS['_transients'][$key])) {
        return null;
    }
    return $GLOBALS['_transients'][$key][1] - time();
}

// NB: TS_Provider_Fake::authenticate() returns a token without any HTTP call,
// so nothing is queued for the login — every queued response below belongs to a
// rate request.
$live->login(true);

$dest = ['country' => 'IN', 'postcode' => '560001'];
$opts = ['cod' => true];
$key  = $live->cache_key($dest, 0.5, $opts);

// Cold lane, couriers returned: one live call, cached for the full TTL.
$live_http->push(['code' => 200, 'body' => ['rates' => [
    ['id' => 'a', 'name' => 'Courier A', 'rate' => 60, 'days' => 3, 'cod' => true],
]]]);

$before = count($live_http->requests);
$rows   = $live->rates($dest, 0.5, $opts);

is_same('cold lane makes exactly one live call', count($live_http->requests) - $before, 1);
is_same('and returns the couriers', count($rows), 1);
ok('cached for roughly the full rate TTL', ttl_of($key) > 20 * HOUR_IN_SECONDS);

// Second read is served from cache: no further call.
$before = count($live_http->requests);
$rows   = $live->rates($dest, 0.5, $opts);
is_same('warm lane makes no call', count($live_http->requests) - $before, 0);
is_same('and still returns the couriers', count($rows), 1);

section('An empty live result is only remembered briefly');

$dest2 = ['country' => 'IN', 'postcode' => '700001'];
$key2  = $live->cache_key($dest2, 0.5, $opts);

$live_http->push(['code' => 200, 'body' => ['rates' => []]]);
$rows = $live->rates($dest2, 0.5, $opts);

is_same('no couriers returned', $rows, []);
ok('the empty result IS cached (so it does not re-call on every render)',
    is_array($live->get_cached($dest2, 0.5, $opts)));
ok('but for about an hour, not the full rate TTL', ttl_of($key2) <= HOUR_IN_SECONDS && ttl_of($key2) > 0,
    'ttl was ' . var_export(ttl_of($key2), true));
ok('which is far shorter than a served lane', ttl_of($key2) < ttl_of($key));

$before = count($live_http->requests);
$live->rates($dest2, 0.5, $opts);
is_same('and it does suppress an immediate re-call', count($live_http->requests) - $before, 0);

section('The live call stays bounded');

// A transport failure must trip the breaker, not retry in the customer's thread.
$dest3 = ['country' => 'IN', 'postcode' => '600001'];
$live->reset_circuit();
$live_http->push(['error' => 'cURL timeout after 5000ms']);

$before = count($live_http->requests);
$rows   = $live->rates($dest3, 0.5, $opts);

is_same('returns nothing rather than hanging', $rows, []);
is_same('made a single attempt, no retry', count($live_http->requests) - $before, 1);
ok('tripped the circuit breaker', $live->circuit_is_open());
is_same('did NOT cache the failure as an empty lane', $live->get_cached($dest3, 0.5, $opts), false);

$before = count($live_http->requests);
is_same('a later cold lane is suppressed while the breaker is open',
    $live->rates(['country' => 'IN', 'postcode' => '411001'], 0.5, $opts), []);
is_same('with no call attempted', count($live_http->requests) - $before, 0);
$live->reset_circuit();

section('Policy is per-provider and filterable');

remove_all_filters('ts_negative_cache_ttl');
is_same('negative TTL defaults to an hour', $live->negative_cache_ttl(), HOUR_IN_SECONDS);

add_filter('ts_negative_cache_ttl', function ($ttl, $provider_id) {
    return 'fakecarry' === $provider_id ? 300 : $ttl;
}, 10, 2);

is_same('a filter can tighten it per provider', $live->negative_cache_ttl(), 300);
is_same('and leave another provider alone', $provider->negative_cache_ttl(), HOUR_IN_SECONDS);
remove_all_filters('ts_negative_cache_ttl');

$timeout = new ReflectionMethod('TS_Provider_Base', 'frontend_timeout');
$timeout->setAccessible(true);
is_same('frontend timeout defaults to 5s', $timeout->invoke($live), 5.0);

add_filter('tis_frontend_timeout', function ($t, $provider_id) {
    return 'fakecarry' === $provider_id ? 2.5 : $t;
}, 10, 2);
is_same('and is tightenable per provider', $timeout->invoke($live), 2.5);
is_same('without affecting the other', $timeout->invoke($provider), 5.0);
remove_all_filters('tis_frontend_timeout');

/* =================================================================== */
echo "\n" . str_repeat('-', 56) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
