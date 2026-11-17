<?php
/**
 * Smoke harness for the WooCommerce shipping method.
 *
 *     php tests/smoke-shipping-method.php
 *
 * calculate_shipping() is where a provider's rates become what a customer pays,
 * so it is where a mistake costs real money. It needs WooCommerce's
 * WC_Shipping_Method base class, which does not exist here, so a minimal
 * stand-in is defined below. It implements only what the plugin's class calls.
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

function absint($n) { return abs((int) $n); }

/** The slice of WC_Shipping_Method the plugin's subclass relies on. */
class WC_Shipping_Method {
    public $id; public $instance_id; public $method_title; public $method_description;
    public $supports = []; public $title; public $enabled;
    public $instance_form_fields = []; public $settings = []; public $rates = [];

    public function init_settings() {}
    public function get_option($key, $default = '') {
        if (isset($this->settings[$key])) { return $this->settings[$key]; }
        if (isset($this->instance_form_fields[$key]['default'])) { return $this->instance_form_fields[$key]['default']; }
        return $default;
    }
    public function get_rate_id($suffix = '') { return $this->id . ':' . $this->instance_id . ':' . $suffix; }
    public function add_rate($args) { $this->rates[] = $args; }
}

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str . ' at ' . basename($file) . ':' . $line, 0, $no, $file, $line);
});

require $root . '/includes/class-tis-shipping-method.php';
foreach ($GLOBALS['_registered_actions']['woocommerce_shipping_init'] as $cb) { $cb(); }

/** A provider that records what the shipping method asks of it. */
class TS_Provider_Spy extends TS_Provider_Fake {
    public $calls = [];
    public $answer = [];
    public function rates(array $destination, $weight, array $opts = []) {
        $this->calls[] = compact('destination', 'weight', 'opts');
        return $this->answer;
    }
}

$pass = 0; $fail = 0;
function ok($l, $c, $d = '') { global $pass, $fail; if ($c) { $pass++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $fail++; echo "  \033[31mFAIL\033[0m  $l" . ($d ? "\n          $d" : '') . "\n"; } }
function is_same($l, $a, $e) { ok($l, $a === $e, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function section($t) { echo "\n\033[1m$t\033[0m\n"; }

function method(array $settings = []) {
    $m = new TIS_Shipping_Method(1);
    $m->settings = $settings;
    return $m;
}
function package($country, $postcode = '', $cost = 0) {
    return ['destination' => ['country' => $country, 'postcode' => $postcode], 'contents' => [], 'contents_cost' => $cost];
}

$spy = new TS_Provider_Spy(new TS_Http_Fake(), new TS_Logger_Null());   // serves domestic + international
TS_Provider_Registry::reset();
TS_Provider_Registry::register($spy);
update_option('ts_active_provider', 'fakecarry');

$row = function ($id, $name, $rate) {
    return ['courier_id' => $id, 'courier_name' => $name, 'rate' => $rate, 'transit_days' => null, 'edd' => null,
            'provider' => 'fakecarry', 'service_type' => 'surface', 'cod_available' => false, 'rto_charge' => null, 'delivery_rate' => null];
};

/* =================================================================== */
section('Defaults');
$m = method();
is_same('international fallback keeps its default', $m->get_option('fallback_cost'), '2500');
is_same('domestic fallback is OFF by default', $m->get_option('fallback_cost_domestic'), '0');
ok('the field is described', false !== strpos($m->instance_form_fields['fallback_cost_domestic']['description'], 'wildly wrong'));

section('What the shipping method asks the provider');
$spy->calls = []; $spy->answer = [$row('88-s', 'Beta', 65.5)];
$m = method();
$m->calculate_shipping(package('IN', '628008', 730));
$c = $spy->calls[0];
is_same('destination country', $c['destination']['country'], 'IN');
is_same('destination postcode (domestic rating needs it)', $c['destination']['postcode'], '628008');
is_same('cart value passed (COD charges scale with it)', $c['opts']['value'], 730.0);
is_same('prepaid by default', $c['opts']['cod'], false);
ok('billable weight at least the 0.5kg slab', $c['weight'] >= 0.5);

section('A quote becomes a WooCommerce rate');
$spy->answer = [$row('88-s', 'Beta', 65.5), $row('159-s', 'Alpha', 80.0)];
$m = method(['markup_percent' => '10', 'handling_flat' => '5']);
$m->calculate_shipping(package('IN', '628008'));
is_same('two rates', count($m->rates), 2);
is_same('markup and handling applied (65.5 x 1.10 + 5)', $m->rates[0]['cost'], 77.05);
is_same('label is the courier name', $m->rates[0]['label'], 'Beta');
is_same('provider recorded in rate meta (for later booking)', $m->rates[0]['meta_data']['tis_provider'], 'fakecarry');
is_same('service type recorded', $m->rates[0]['meta_data']['tis_service_type'], 'surface');
is_same('the unmarked-up base rate is recorded too', $m->rates[0]['meta_data']['tis_base_rate'], 65.5);
$m = method(['max_couriers' => '1']);
$m->calculate_shipping(package('IN', '628008'));
is_same('max couriers respected', count($m->rates), 1);
ok('no transit time shown when the provider has none', false === strpos($m->rates[0]['label'], 'days'));

section('INTERNATIONAL fallback: unchanged');
$spy->answer = [];
$m = method();
$m->calculate_shipping(package('US', ''));
is_same('one fallback rate', count($m->rates), 1);
is_same('at the international amount', $m->rates[0]['cost'], 2500.0);
is_same('flagged as a fallback', $m->rates[0]['meta_data']['tis_fallback'], 'yes');

section('DOMESTIC fallback: never inherits the international amount');
$m = method();                                         // defaults: international 2500, domestic 0
$m->calculate_shipping(package('IN', '628008'));
is_same('an Indian customer is NOT charged the 2500 overseas fallback', count($m->rates), 0);
$m = method(['fallback_cost_domestic' => '120']);
$m->calculate_shipping(package('IN', '628008'));
is_same('a deliberate domestic fallback is used', $m->rates[0]['cost'], 120.0);
$m = method(['fallback_cost' => '9999', 'fallback_cost_domestic' => '120']);
$m->calculate_shipping(package('IN', '628008'));
is_same('and it ignores the international figure entirely', $m->rates[0]['cost'], 120.0);
$m = method(['fallback_cost' => '2500', 'fallback_cost_domestic' => '0']);
$m->calculate_shipping(package('US', ''));
is_same('international is unaffected by the domestic setting', $m->rates[0]['cost'], 2500.0);
$m = method(['fallback_enabled' => 'no', 'fallback_cost_domestic' => '120']);
$m->calculate_shipping(package('IN', '628008'));
is_same('the master switch turns both off', count($m->rates), 0);

section('No address yet, or a lane the provider does not serve');
$spy->calls = [];
$m = method(); $m->calculate_shipping(package('', ''));
is_same('no country: nothing, and no call', [count($m->rates), count($spy->calls)], [0, 0]);

section('Shiprocket active: India stays out of scope, with no fallback');
$sr = new TS_Provider_Shiprocket_Test(new TS_Http_Fake(), new TS_Logger_Null());
TS_Provider_Registry::reset(); TS_Provider_Registry::register($sr);
update_option('ts_active_provider', 'shiprocket');
$m = method(['fallback_cost_domestic' => '120']);
$m->calculate_shipping(package('IN', '110001'));
is_same('no rate for India, as in v0.2.0', count($m->rates), 0);
$m = method();
$m->calculate_shipping(package('US', ''));
is_same('overseas still gets the international fallback when there is no warm token', $m->rates[0]['cost'], 2500.0);

section('A freshly booted registry (the real Shiprocket, no token, no cache)');
TS_Provider_Registry::reset();
$GLOBALS['_options'] = [];
$GLOBALS['_transients'] = [];
$m = method();
$m->calculate_shipping(package('US', ''));
is_same('overseas gets exactly the international fallback', [count($m->rates), $m->rates[0]['cost'] ?? null], [1, 2500.0]);

echo "\n" . str_repeat('-', 56) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
