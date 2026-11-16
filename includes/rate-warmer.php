<?php
/**
 * Scheduled rate warmer.
 *
 * Checkout must not wait on an aggregator. A daily cron pre-fetches rates for
 * the lanes the active provider nominates, so that calculate_shipping() reads a
 * warm transient and returns instantly.
 *
 * The lane list comes from the provider rather than being computed here,
 * because the two aggregators have genuinely different warming economics:
 * Shiprocket's international rating is country-level, so one cached entry
 * serves every order of that weight to that country and warming every lane is
 * cheap. Domestic Indian rating is pincode-level and cannot be warmed
 * exhaustively — that provider will nominate a bounded subset instead
 * (SCOPE.md §4).
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

/**
 * Destination countries kept warm by the Shiprocket provider (ISO-2 codes).
 *
 * @deprecated 0.3.0 Use TS_Provider_Registry::get('shiprocket')->warm_countries().
 * @return string[]
 */
function tis_warm_countries() {
    $p = TS_Provider_Registry::get('shiprocket');
    return $p ? $p->warm_countries() : [];
}

/**
 * Weight slabs kept warm by the Shiprocket provider, in kg.
 *
 * @deprecated 0.3.0 Use TS_Provider_Registry::get('shiprocket')->warm_slabs().
 * @return float[]
 */
function tis_warm_slabs() {
    $p = TS_Provider_Registry::get('shiprocket');
    return $p ? $p->warm_slabs() : [];
}

/**
 * Warm the cache for the active provider. Runs on cron, or on demand from the
 * settings screen.
 *
 * @return array Summary counters for admin feedback.
 */
function tis_warm_rates_now() {

    $summary = ['ok' => 0, 'empty' => 0, 'failed' => 0, 'errors' => []];

    $provider = TS_Provider_Registry::active();

    if (!$provider) {
        $summary['errors'][] = 'No shipping provider is registered.';
        $summary['failed']++;
        tis_log('error', 'Rate warm aborted — no provider registered.');
        update_option('tis_last_warm', ['time' => time(), 'summary' => $summary], false);
        return $summary;
    }

    tis_log('info', 'Rate warm started.', ['provider' => $provider->id()]);

    $token = $provider->token();

    if (is_array($token) && isset($token['error'])) {
        $summary['errors'][] = $token['error'];
        $summary['failed']++;
        tis_log('error', 'Rate warm aborted — authentication failed.', [
            'provider' => $provider->id(),
            'error'    => $token['error'],
        ]);
        update_option('tis_last_warm', ['time' => time(), 'summary' => $summary], false);
        return $summary;
    }

    // A successful background auth means the aggregator is reachable again.
    $provider->reset_circuit();

    $delay_us = (int) apply_filters('tis_warm_delay_microseconds', 250000);
    $timeout  = (float) apply_filters('tis_warm_timeout', 20);

    foreach ($provider->warm_lanes() as $lane) {

        $destination = isset($lane['destination']) ? (array) $lane['destination'] : [];
        $weight      = isset($lane['weight']) ? (float) $lane['weight'] : 0.5;
        $opts        = ['cod' => !empty($lane['cod'])];

        $result = $provider->request($destination, $weight, $opts, $token, $timeout);

        if (!empty($result['error'])) {

            $summary['failed']++;

            if (count($summary['errors']) < 5) {
                $summary['errors'][] = sprintf(
                    '%s @ %skg: %s',
                    isset($destination['country']) ? $destination['country'] : '?',
                    $weight,
                    $result['error']
                );
            }

        } elseif (empty($result['rates'])) {

            // Cache the empty result too, briefly, so a dead lane does not
            // trigger a live call on every checkout render.
            $provider->set_cached($destination, $weight, $opts, [], HOUR_IN_SECONDS);
            $summary['empty']++;

        } else {

            $provider->set_cached($destination, $weight, $opts, $result['rates']);
            $summary['ok']++;
        }

        if ($delay_us > 0) {
            usleep($delay_us);
        }
    }

    update_option('tis_last_warm', [
        'time'     => time(),
        'provider' => $provider->id(),
        'summary'  => $summary,
    ], false);

    tis_log('info', 'Rate warm finished.', $summary + ['provider' => $provider->id()]);

    return $summary;
}

add_action(TIS_CRON_HOOK, 'tis_warm_rates_now');
