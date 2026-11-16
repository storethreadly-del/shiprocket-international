<?php
/**
 * Background token refresh.
 *
 * Checkout must never log in on a customer's thread: it reads a token that a
 * background job already minted. That works for Shiprocket, whose token lives
 * for days and is kept warm by the daily rate warm. iCarry's token lives for one
 * hour, so without this a store would have a usable token for under an hour a day,
 * and every cold lane would fall back to the flat rate for the other twenty-three.
 *
 * So a provider that declares refresh_interval() > 0 gets a recurring job that
 * logs in again before the cached token lapses. The cached copy is held for 55
 * minutes and the job runs every 40, so there is always an overlap.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

define('TIS_REFRESH_HOOK', 'tis_refresh_provider_token');

add_filter('cron_schedules', function ($schedules) {
    $schedules['tis_every_40_minutes'] = [
        'interval' => 40 * MINUTE_IN_SECONDS,
        'display'  => __('Every 40 minutes', 'threadly-intl-shipping'),
    ];
    return $schedules;
});

/**
 * Schedule the refresh when the active provider needs it, and unschedule it when
 * it does not. Safe to call repeatedly: it only touches cron when the state is
 * wrong.
 */
function tis_sync_token_refresh_schedule() {

    $provider = TS_Provider_Registry::active();
    $needed   = $provider && $provider->refresh_interval() > 0 && $provider->has_credentials();
    $next     = wp_next_scheduled(TIS_REFRESH_HOOK);

    if ($needed && !$next) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'tis_every_40_minutes', TIS_REFRESH_HOOK);
    } elseif (!$needed && $next) {
        wp_clear_scheduled_hook(TIS_REFRESH_HOOK);
    }
}

add_action('init', 'tis_sync_token_refresh_schedule');

/**
 * The job itself. Goes through login(true), so it shares the mutex and the
 * lockout with every other login: a bad credential stops after three attempts
 * instead of retrying every forty minutes forever.
 */
function tis_refresh_provider_token() {

    $provider = TS_Provider_Registry::active();

    if (!$provider || $provider->refresh_interval() <= 0 || !$provider->has_credentials()) {
        return;
    }

    $result = $provider->login(true);

    if (is_array($result) && isset($result['error'])) {
        tis_log('warning', 'Background token refresh failed.', [
            'provider' => $provider->id(),
            'error'    => $result['error'],
        ]);
    }
}

add_action(TIS_REFRESH_HOOK, 'tis_refresh_provider_token');
