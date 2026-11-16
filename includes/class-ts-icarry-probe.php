<?php
/**
 * iCarry.in discovery probe.
 *
 * iCarry publishes endpoint NAMES only (no paths, schemas or examples), so the
 * contract has to be discovered from the account. This sends one operator-built
 * request and returns the raw response, so the real paths, auth scheme and
 * envelopes can be pinned from evidence rather than guessed.
 *
 * Safety properties, each covered by tests/smoke-icarry-probe.php:
 *  - HTTPS to icarry.in or a subdomain ONLY. Exact host match, so
 *    icarry.in.evil.com and evilicarry.in are refused, and so is icarry.com
 *    (a different company).
 *  - The password is substituted server-side and never round-trips through the
 *    form, and is redacted from everything returned.
 *  - Auth failures are throttled. A failed-login lockout is per account, so
 *    repeated 401/403s stop the probe for a cool-down instead of risking it.
 *
 * @package Threadly_Intl_Shipping
 */

if (!defined('ABSPATH')) exit;

class TS_ICarry_Probe {

    const FAIL_KEY      = 'tis_ic_probe_fails';
    const BLOCK_KEY     = 'tis_ic_probe_blocked';
    const MAX_AUTH_FAIL = 3;
    const MAX_BODY      = 20000;

    /** @var callable function(string $method, string $url, array $headers, ?string $body, float $timeout): array */
    protected $transport;

    public function __construct(callable $transport = null) {
        $this->transport = $transport ?: [__CLASS__, 'wp_transport'];
    }

    public static function wp_transport($method, $url, array $headers, $body, $timeout) {

        $r = wp_remote_request($url, [
            'method'      => $method,
            'headers'     => $headers,
            'body'        => $body,
            'timeout'     => $timeout,
            'sslverify'   => true,
            'redirection' => 0,
        ]);

        if (is_wp_error($r)) {
            return ['error' => $r->get_error_message()];
        }

        return [
            'code'    => (int) wp_remote_retrieve_response_code($r),
            'headers' => (array) wp_remote_retrieve_headers($r),
            'body'    => (string) wp_remote_retrieve_body($r),
        ];
    }

    /** @return true|string True when allowed, else the reason. */
    public static function validate_url($url) {

        $p = parse_url((string) $url);

        if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) {
            return 'Not a valid URL.';
        }
        if ('https' !== strtolower($p['scheme'])) {
            return 'HTTPS only.';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'Credentials in the URL are not allowed.';
        }
        if (isset($p['port']) && 443 !== (int) $p['port']) {
            return 'Only the default HTTPS port is allowed.';
        }

        $host = strtolower($p['host']);

        if ('icarry.in' !== $host && '.icarry.in' !== substr($host, -10)) {
            return 'Host must be icarry.in or one of its subdomains (icarry.com is a different company).';
        }

        return true;
    }

    /** @return string[] */
    public static function auth_modes() {
        return ['none', 'basic', 'bearer', 'header', 'body'];
    }

    /**
     * Run one probe.
     *
     * @param array $in { url, method, auth, header_name, user_field, pass_field, body }
     * @param array $creds { user, password }
     * @return array { error? , sent, code?, headers?, body? }
     */
    public function run(array $in, array $creds) {

        $url    = isset($in['url']) ? trim((string) $in['url']) : '';
        $method = strtoupper(isset($in['method']) ? (string) $in['method'] : 'GET');
        $auth   = isset($in['auth']) ? (string) $in['auth'] : 'none';

        $ok = self::validate_url($url);
        if (true !== $ok) {
            return ['error' => $ok];
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            return ['error' => 'Method must be GET or POST.'];
        }
        if (!in_array($auth, self::auth_modes(), true)) {
            return ['error' => 'Unknown auth mode.'];
        }

        $blocked = (int) get_transient(self::BLOCK_KEY);
        if ($blocked > time()) {
            return ['error' => sprintf(
                'Probe paused after %d authentication failures, to protect the account from lockout. Resumes in %d minute(s). Check the username and API password.',
                self::MAX_AUTH_FAIL,
                (int) ceil(($blocked - time()) / MINUTE_IN_SECONDS)
            )];
        }

        $headers = ['Accept' => 'application/json'];
        $json    = [];

        if (!empty($in['body'])) {
            $decoded = json_decode((string) $in['body'], true);
            if (!is_array($decoded)) {
                return ['error' => 'Request body must be valid JSON (an object).'];
            }
            $json = $decoded;
        }

        $user = (string) ($creds['user'] ?? '');
        $pass = (string) ($creds['password'] ?? '');

        if ('none' !== $auth && ('' === $user || '' === $pass)) {
            return ['error' => 'No iCarry credentials stored yet.'];
        }

        if ('basic' === $auth) {
            $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $pass);
        } elseif ('bearer' === $auth) {
            $headers['Authorization'] = 'Bearer ' . $pass;
        } elseif ('header' === $auth) {
            $name = isset($in['header_name']) ? trim((string) $in['header_name']) : '';
            if (!preg_match('/^[A-Za-z0-9-]{1,40}$/', $name)) {
                return ['error' => 'Header name must be letters, digits and hyphens.'];
            }
            $headers[$name] = $pass;
        } elseif ('body' === $auth) {
            $uf = trim((string) ($in['user_field'] ?? ''));
            $pf = trim((string) ($in['pass_field'] ?? ''));
            if ('' === $uf || '' === $pf) {
                return ['error' => 'Name the username and password fields to send in the body.'];
            }
            $json[$uf] = $user;
            $json[$pf] = $pass;
        }

        $body = null;
        if ('POST' === $method) {
            $headers['Content-Type'] = 'application/json';
            $body = wp_json_encode((object) $json);
        }

        $secrets = array_values(array_filter([$pass, base64_encode($user . ':' . $pass)], 'strlen'));

        $res = call_user_func($this->transport, $method, $url, $headers, $body, 20.0);

        $out = ['sent' => [
            'method'  => $method,
            'url'     => $url,
            'auth'    => $auth,
            'headers' => self::redact_headers($headers, $secrets),
            'body'    => null === $body ? null : self::redact($body, $secrets),
        ]];

        if (!empty($res['error'])) {
            $out['error'] = self::redact((string) $res['error'], $secrets);
            return $out;
        }

        $code = (int) $res['code'];
        $this->track_auth($code);

        $text = (string) $res['body'];
        $out['code']    = $code;
        $out['headers'] = self::redact_headers((array) $res['headers'], $secrets);
        $out['body']    = self::redact(substr($text, 0, self::MAX_BODY), $secrets)
                        . (strlen($text) > self::MAX_BODY ? "\n...[truncated]" : '');

        return $out;
    }

    protected function track_auth($code) {

        if (401 !== $code && 403 !== $code) {
            delete_transient(self::FAIL_KEY);
            return;
        }

        $n = (int) get_transient(self::FAIL_KEY) + 1;
        set_transient(self::FAIL_KEY, $n, 15 * MINUTE_IN_SECONDS);

        if ($n >= self::MAX_AUTH_FAIL) {
            set_transient(self::BLOCK_KEY, time() + 15 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS);
        }
    }

    public static function redact($text, array $secrets) {
        foreach ($secrets as $s) {
            if ('' !== (string) $s) {
                $text = str_replace((string) $s, '[REDACTED]', (string) $text);
            }
        }
        return $text;
    }

    public static function redact_headers(array $headers, array $secrets) {
        $out = [];
        foreach ($headers as $k => $v) {
            $v = is_array($v) ? implode(', ', $v) : (string) $v;
            $sensitive = (bool) preg_match('/^(authorization|set-cookie|cookie|x-api-key)$/i', (string) $k);
            $out[$k] = $sensitive ? '[REDACTED]' : self::redact($v, $secrets);
        }
        return $out;
    }
}
