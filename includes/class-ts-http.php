<?php
/**
 * HTTP transport seam.
 *
 * Providers never call wp_remote_*() directly. They are handed a TS_Http and
 * talk to that, for two reasons:
 *
 *  1. The HIBOU Intelligence Engine needs to reuse the provider adapters
 *     outside WordPress (see SCOPE.md §6, seam 3). A provider that depends on
 *     this interface rather than on WordPress's HTTP API can be lifted as-is
 *     and handed a Guzzle- or cURL-backed implementation instead.
 *  2. It makes the providers testable without a live aggregator — a stub
 *     TS_Http returns canned payloads.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

interface TS_Http {

    /**
     * @param string $url
     * @param array  $headers Header name => value.
     * @param float  $timeout Seconds.
     * @return array {
     *     @type string|null $error   Transport-level error, or null on an HTTP
     *                                round trip that completed (even a 500).
     *     @type int         $code    HTTP status, 0 when $error is set.
     *     @type mixed       $body    JSON-decoded body, or null.
     *     @type string      $raw     Undecoded body.
     *     @type array       $headers Response headers, lower-cased names.
     *     @type array       $cookies Cookies set by the response, name => value.
     * }
     */
    public function get($url, array $headers = [], $timeout = 20);

    /**
     * @param string $url
     * @param array  $headers
     * @param mixed  $body    Encoded as JSON.
     * @param float  $timeout Seconds.
     * @return array Same shape as get().
     */
    public function post($url, array $headers = [], $body = null, $timeout = 20);

    /**
     * POST an application/x-www-form-urlencoded body.
     *
     * Some aggregators (iCarry.in) take form fields rather than JSON, including
     * nested arrays such as boxes[0][quantity]. Passing the fields as an array
     * and letting the transport encode them keeps that out of every provider.
     *
     * @param string $url
     * @param array  $headers
     * @param array  $fields  Field name => value; values may be nested arrays.
     * @param float  $timeout Seconds.
     * @return array Same shape as get().
     */
    public function post_form($url, array $headers = [], array $fields = [], $timeout = 20);
}

/**
 * WordPress-backed implementation — the one the plugin itself uses.
 */
class TS_Http_WP implements TS_Http {

    public function get($url, array $headers = [], $timeout = 20) {
        return $this->normalise(wp_remote_get($url, $this->args($headers, $timeout)));
    }

    public function post($url, array $headers = [], $body = null, $timeout = 20) {

        $args = $this->args($headers, $timeout);

        if (null !== $body) {
            $args['body'] = wp_json_encode($body);
        }

        return $this->normalise(wp_remote_post($url, $args));
    }

    public function post_form($url, array $headers = [], array $fields = [], $timeout = 20) {

        $args = $this->args($headers, $timeout);

        // An array body is sent as application/x-www-form-urlencoded by WP_Http.
        $args['body'] = $fields;

        return $this->normalise(wp_remote_post($url, $args));
    }

    protected function args(array $headers, $timeout) {
        return [
            'headers'    => array_merge(['Accept' => 'application/json'], $headers),
            'timeout'    => (float) $timeout,
            'sslverify'  => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
        ];
    }

    /**
     * Collapse WP_Error vs. response into the one shape providers expect, so
     * no provider has to know what a WP_Error is.
     */
    protected function normalise($response) {

        if (is_wp_error($response)) {
            return [
                'error'   => $response->get_error_message(),
                'code'    => 0,
                'body'    => null,
                'raw'     => '',
                'headers' => [],
                'cookies' => [],
            ];
        }

        $raw = wp_remote_retrieve_body($response);

        $headers = wp_remote_retrieve_headers($response);
        $headers = (is_object($headers) && method_exists($headers, 'getAll')) ? $headers->getAll() : (array) $headers;
        $headers = array_change_key_case($headers, CASE_LOWER);

        $cookies = [];
        foreach ((array) wp_remote_retrieve_cookies($response) as $cookie) {
            if (is_object($cookie) && isset($cookie->name)) {
                $cookies[(string) $cookie->name] = (string) $cookie->value;
            }
        }

        return [
            'error'   => null,
            'code'    => (int) wp_remote_retrieve_response_code($response),
            'body'    => json_decode($raw, true),
            'raw'     => $raw,
            'headers' => $headers,
            'cookies' => $cookies,
        ];
    }
}
