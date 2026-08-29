<?php
/**
 * Scheduled rate warmer.
 *
 * Checkout must not wait on Shiprocket. A daily cron pre-fetches rates for the
 * configured destination countries across a set of weight slabs, so that
 * calculate_shipping() reads a warm transient and returns instantly.
 *
 * International rating is country-level rather than pincode-level, which is what
 * makes this practical: one cached entry serves every order of that weight to
 * that country.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

/**
 * Destination countries to keep warm (ISO-2 codes).
 *
 * @return string[]
 */
function tis_warm_countries() {
    $raw  = (string) get_option('tis_warm_countries', 'US,GB,AE,AU,CA,SG');
    $list = array_filter(array_map('trim', explode(',', strtoupper($raw))));
    return array_values(array_unique($list));
}

/**
 * Weight slabs to keep warm, in kg.
 *
 * @return float[]
 */
function tis_warm_slabs() {
    $raw  = (string) get_option('tis_warm_slabs', '0.5,1,1.5,2,2.5,3,4,5');
    $list = array_filter(array_map('floatval', array_map('trim', explode(',', $raw))));
    sort($list);
    return array_values(array_unique($list));
}

/**
 * Warm the cache. Runs on cron, or on demand from the settings screen.
 *
 * @return array Summary counters for admin feedback.
 */
function tis_warm_rates_now() {

    tis_log('info', 'Shiprocket rate warm started.');

    $summary = ['ok' => 0, 'empty' => 0, 'failed' => 0, 'errors' => []];

    $token = TIS_API::background_token();
    if (is_array($token) && isset($token['error'])) {
        $summary['errors'][] = $token['error'];
        $summary['failed']++;
        tis_log('error', 'Shiprocket rate warm aborted — authentication failed.', ['error' => $token['error']]);
        update_option('tis_last_warm', ['time' => time(), 'summary' => $summary], false);
        return $summary;
    }

    // A successful background auth means Shiprocket is reachable again.
    TIS_API::reset_circuit();

    $delay_us = (int) apply_filters('tis_warm_delay_microseconds', 250000);
    $timeout  = (float) apply_filters('tis_warm_timeout', 20);

    foreach (tis_warm_countries() as $country) {
        foreach (tis_warm_slabs() as $weight) {

            $result = TIS_API::request($country, $weight, 0, $token, $timeout);

            if (!empty($result['error'])) {
                $summary['failed']++;
                $key = $country . ' @ ' . $weight . 'kg: ' . $result['error'];
                if (count($summary['errors']) < 5) {
                    $summary['errors'][] = $key;
                }
            } elseif (empty($result['rates'])) {
                // Cache the empty result too, briefly, so a dead lane does not
                // trigger a live call on every checkout render.
                TIS_API::set_cached($country, $weight, 0, [], HOUR_IN_SECONDS);
                $summary['empty']++;
            } else {
                TIS_API::set_cached($country, $weight, 0, $result['rates']);
                $summary['ok']++;
            }

            if ($delay_us > 0) {
                usleep($delay_us);
            }
        }
    }

    update_option('tis_last_warm', [
        'time'    => time(),
        'summary' => $summary,
    ], false);

    tis_log('info', 'Shiprocket rate warm finished.', $summary);

    return $summary;
}

add_action(TIS_CRON_HOOK, 'tis_warm_rates_now');
