<?php
/**
 * HTTP double for the smoke harness.
 *
 * Loaded after includes/class-ts-http.php, since it implements TS_Http.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

/**
 * HTTP double. Records every request and replays queued responses, so the
 * provider can be driven through success, failure, 401 and lockout paths with
 * no network.
 */
class TS_Http_Fake implements TS_Http {

    public $requests = [];
    public $queue    = [];

    public function push($response) {
        $this->queue[] = $response;
        return $this;
    }

    public function get($url, array $headers = [], $timeout = 20) {
        return $this->respond('GET', $url, $headers, null, $timeout);
    }

    public function post($url, array $headers = [], $body = null, $timeout = 20) {
        return $this->respond('POST', $url, $headers, $body, $timeout);
    }

    protected function respond($method, $url, $headers, $body, $timeout) {

        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeout');

        $response = array_shift($this->queue);

        if (null === $response) {
            return ['error' => 'no queued response', 'code' => 0, 'body' => null, 'raw' => ''];
        }

        return array_merge(['error' => null, 'code' => 200, 'body' => null, 'raw' => ''], $response);
    }
}
