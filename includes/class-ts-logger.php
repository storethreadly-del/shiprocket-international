<?php
/**
 * Logging seam.
 *
 * Same rationale as TS_Http: a provider that depends on this interface rather
 * than on wc_get_logger() can be lifted into the HIBOU Intelligence Engine and
 * handed whatever logger lives there.
 *
 * The standing rule from the original plugin carries over unchanged: $context
 * must never contain a password, token, or Authorization header. Log what
 * happened, not the secret that let it happen.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

interface TS_Logger {

    /**
     * @param string $level   emergency|alert|critical|error|warning|notice|info|debug
     * @param string $message
     * @param array  $context Never secrets.
     */
    public function log($level, $message, array $context = []);
}

/**
 * WooCommerce-backed implementation.
 *
 * Everything lands in WooCommerce > Status > Logs under the source this is
 * constructed with. The source stays "tis-shiprocket" for the Shiprocket
 * provider so existing log files, and the link on the settings screen, keep
 * pointing at the same place.
 */
class TS_Logger_WC implements TS_Logger {

    protected $source;

    public function __construct($source = 'tis-shiprocket') {
        $this->source = $source;
    }

    public function log($level, $message, array $context = []) {
        tis_log($level, $message, array_merge($context, ['source' => $this->source]));
    }
}

/**
 * Discards everything. Useful in tests and in any context where no logger has
 * been wired up yet — a provider must never fail because logging is absent.
 */
class TS_Logger_Null implements TS_Logger {
    public function log($level, $message, array $context = []) {}
}
