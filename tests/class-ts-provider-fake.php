<?php
/**
 * A second provider for the smoke harness.
 *
 * Phase 2 builds the switch lifecycle, but only one real provider exists until
 * phase 3 — so there would be nothing to switch to and the lifecycle would ship
 * unexercised. This stands in for iCarry: it declares domestic + international
 * + order_sync, so it also proves that capability-driven serviceability really
 * does change which lanes are quoted.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

/**
 * In-memory prefix flush.
 *
 * cache_delete_prefixed() is the one storage primitive that reaches for $wpdb.
 * The harness runs without a database, so both test providers override just
 * that primitive — nothing else about their behaviour changes.
 */
trait TS_Test_Memory_Flush {

    public $flushes = 0;

    protected function cache_delete_prefixed($prefix) {

        $this->flushes++;

        foreach (array_keys($GLOBALS['_transients']) as $key) {
            if (0 === strpos($key, $prefix)) {
                unset($GLOBALS['_transients'][$key]);
            }
        }
    }
}

/**
 * The real Shiprocket provider, with only the database-backed flush replaced.
 */
class TS_Provider_Shiprocket_Test extends TS_Provider_Shiprocket {
    use TS_Test_Memory_Flush;
}

/**
 * A stand-in for a second aggregator.
 */
class TS_Provider_Fake extends TS_Provider_Base {

    use TS_Test_Memory_Flush;

    public $credentials_configured = true;

    public function id() {
        return 'fakecarry';
    }

    public function label() {
        return 'FakeCarry';
    }

    public function capabilities() {
        return [
            'domestic'      => true,
            'international' => true,
            'order_sync'    => true,
        ];
    }

    public function credentials() {
        return [
            'key'    => $this->credentials_configured ? 'test-key' : '',
            'source' => 'settings',
        ];
    }

    public function has_credentials() {
        return $this->credentials_configured;
    }

    protected function authenticate(array $credentials) {
        return ['token' => 'fake_token'];
    }

    protected function rate_url(array $destination, $weight, array $opts) {
        return 'https://fake.test/rates?to=' . rawurlencode($this->destination_label($destination))
            . '&weight=' . $weight;
    }

    public function parse_rates($body) {

        if (!is_array($body) || empty($body['rates'])) {
            return [];
        }

        $rows = [];

        foreach ($body['rates'] as $r) {
            $rows[] = [
                'courier_id'    => (string) $r['id'],
                'courier_name'  => (string) $r['name'],
                'rate'          => (float) $r['rate'],
                'transit_days'  => isset($r['days']) ? $r['days'] : null,
                'edd'           => null,
                'provider'      => $this->id(),
                'service_type'  => '',
                'cod_available' => !empty($r['cod']),
            ];
        }

        return $rows;
    }

    /**
     * Pincode-level: a bounded lane list, not countries x slabs. This is the
     * shape domestic warming will take (SCOPE.md §4).
     */
    public function warm_lanes() {
        return [
            ['destination' => ['country' => 'IN', 'postcode' => '110001'], 'weight' => 0.5, 'cod' => true],
            ['destination' => ['country' => 'IN', 'postcode' => '400001'], 'weight' => 0.5, 'cod' => true],
            ['destination' => ['country' => 'US', 'postcode' => ''],       'weight' => 0.5, 'cod' => false],
        ];
    }
}
