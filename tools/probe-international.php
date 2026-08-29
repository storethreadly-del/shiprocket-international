<?php
/**
 * Shiprocket X international-rate contract probe.
 *
 * Standalone and read-only: no WordPress bootstrap, no database, no writes to
 * any plugin option or transient. It authenticates once and issues a small,
 * spaced series of GET serviceability calls to discover the real request and
 * response contract, because Shiprocket does not publish readable docs for the
 * international endpoint.
 *
 * Credentials are read from wp-config.php constants (preferred) or environment
 * variables. They are never printed, and the auth token is redacted from every
 * line of output and from the saved transcript.
 *
 * Usage:
 *   php tools/probe-international.php
 *
 * Credentials, in order of precedence:
 *   1. wp-config.php:  define('TIS_SR_EMAIL', '...'); define('TIS_SR_PASSWORD', '...');
 *   2. environment:    TIS_SR_EMAIL=... TIS_SR_PASSWORD=... php tools/probe-international.php
 *
 * Required:
 *   TIS_PICKUP_PINCODE=<origin pincode>   (env, or --pincode=)
 * Optional:
 *   TIS_PROBE_WEIGHT=0.5                  (kg, default 0.5)
 *
 * @package Threadly_Intl_Shipping
 */

if (PHP_SAPI !== 'cli') {
    exit("This probe is CLI-only.\n");
}

const API_BASE = 'https://apiv2.shiprocket.in/v1/external/';
const ENDPOINT = 'courier/international/serviceability/';
const PAUSE_US = 600000; // 0.6s between calls — stay well clear of rate limits.

/* ------------------------------------------------------------------ *
 * Credentials (never printed)
 * ------------------------------------------------------------------ */

function wpconfig_const($name) {
    $paths = [
        __DIR__ . '/../../../../wp-config.php',
        __DIR__ . '/../../../../../wp-config.php',
    ];
    foreach ($paths as $p) {
        if (!is_readable($p)) {
            continue;
        }
        $src = file_get_contents($p);
        if (preg_match("/define\(\s*['\"]" . preg_quote($name, '/') . "['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $src, $m)) {
            return $m[1];
        }
    }
    return null;
}

$email = wpconfig_const('TIS_SR_EMAIL')    ?: getenv('TIS_SR_EMAIL')    ?: '';
$pass  = wpconfig_const('TIS_SR_PASSWORD') ?: getenv('TIS_SR_PASSWORD') ?: '';
$source = wpconfig_const('TIS_SR_EMAIL') ? 'wp-config.php' : 'environment';

$pincode = getenv('TIS_PICKUP_PINCODE') ?: '';
$weight  = (float) (getenv('TIS_PROBE_WEIGHT') ?: 0.5);
foreach ($argv as $a) {
    if (str_starts_with($a, '--pincode=')) $pincode = substr($a, 10);
    if (str_starts_with($a, '--weight='))  $weight  = (float) substr($a, 9);
}

if ('' === $email || '' === $pass) {
    exit("ERROR: no Shiprocket credentials found.\n"
       . "Add TIS_SR_EMAIL / TIS_SR_PASSWORD to wp-config.php, or pass them as env vars.\n"
       . "They are never printed by this probe.\n");
}
if ('' === $pincode) {
    exit("ERROR: no origin pincode. Pass --pincode=110001 or set TIS_PICKUP_PINCODE.\n");
}

/* ------------------------------------------------------------------ *
 * Redaction — applied to everything before it is shown or saved
 * ------------------------------------------------------------------ */

$SECRETS = array_filter([$email, $pass]);

function redact($text) {
    global $SECRETS;
    foreach ($SECRETS as $s) {
        if ('' !== $s) {
            $text = str_replace($s, '<redacted>', $text);
        }
    }
    // Any JWT-shaped blob is a token; never let one reach the transcript.
    return preg_replace('/eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', '<token redacted>', $text);
}

$TRANSCRIPT = [];
function out($line = '') {
    global $TRANSCRIPT;
    $line = redact((string) $line);
    $TRANSCRIPT[] = $line;
    echo $line . "\n";
}

/* ------------------------------------------------------------------ *
 * HTTP
 * ------------------------------------------------------------------ */

function http($method, $url, $headers = [], $body = null, $timeout = 30) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if (null !== $body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['status' => $code, 'body' => $raw, 'error' => $err];
}

/* ------------------------------------------------------------------ *
 * Phase 1 — authenticate (once)
 * ------------------------------------------------------------------ */

out("=========================================================");
out(" Shiprocket X international rate probe");
out(" credential source : {$source}");
out(" origin pincode    : {$pincode}");
out(" probe weight      : {$weight} kg");
out(" started           : " . gmdate('c'));
out("=========================================================");
out();
out("### PHASE 1 — authentication");
out("POST " . API_BASE . "auth/login   body: {\"email\":\"<redacted>\",\"password\":\"<redacted>\"}");

$auth = http('POST', API_BASE . 'auth/login',
    ['Content-Type: application/json', 'Accept: application/json'],
    json_encode(['email' => $email, 'password' => $pass])
);

out("HTTP {$auth['status']}" . ($auth['error'] ? "  transport error: {$auth['error']}" : ''));

$authBody = json_decode($auth['body'], true);
$token = is_array($authBody) && !empty($authBody['token']) ? $authBody['token'] : null;

if (!$token) {
    out("Response (redacted): " . redact(substr((string) $auth['body'], 0, 600)));
    out();
    out("RESULT: authentication FAILED — cannot probe the rate endpoint.");
    file_put_contents(__DIR__ . '/probe-result.txt', implode("\n", $TRANSCRIPT) . "\n");
    exit(1);
}

$SECRETS[] = $token; // ensure the token is redacted from here on
out("Token received: yes (length " . strlen($token) . ", redacted)");
out("Auth response top-level keys: " . implode(', ', array_keys($authBody)));
if (isset($authBody['company_id'])) out("company_id present: yes");
out();

/* ------------------------------------------------------------------ *
 * Phase 2+ — serviceability request matrix
 * ------------------------------------------------------------------ */

$H = ['Authorization: Bearer ' . $token, 'Accept: application/json'];

/**
 * Each case is a candidate request shape. The first is EXACTLY what
 * class-tis-api.php builds today — that one answers "is the current code
 * correct?". The rest are alternates, tried only to discover the real
 * contract. Nothing here changes plugin code.
 */
$cases = [
    'A. CURRENT PLUGIN SHAPE (delivery_country=AE)' => [
        'pickup_postcode' => $pincode, 'delivery_country' => 'AE', 'weight' => $weight, 'cod' => 0,
    ],
    'B. country name instead of ISO-2' => [
        'pickup_postcode' => $pincode, 'delivery_country' => 'United Arab Emirates', 'weight' => $weight, 'cod' => 0,
    ],
    'C. delivery_postcode alongside country' => [
        'pickup_postcode' => $pincode, 'delivery_country' => 'AE', 'delivery_postcode' => '00000', 'weight' => $weight, 'cod' => 0,
    ],
    'D. param named "country"' => [
        'pickup_postcode' => $pincode, 'country' => 'AE', 'weight' => $weight, 'cod' => 0,
    ],
    'E. param named "destination_country"' => [
        'pickup_postcode' => $pincode, 'destination_country' => 'AE', 'weight' => $weight, 'cod' => 0,
    ],
    'F. current shape + dimensions' => [
        'pickup_postcode' => $pincode, 'delivery_country' => 'AE', 'weight' => $weight, 'cod' => 0,
        'length' => 25, 'breadth' => 20, 'height' => 5,
    ],
    'G. current shape, cod=1' => [
        'pickup_postcode' => $pincode, 'delivery_country' => 'AE', 'weight' => $weight, 'cod' => 1,
    ],
    'H. EDGE: invalid country code (ZZ)' => [
        'pickup_postcode' => $pincode, 'delivery_country' => 'ZZ', 'weight' => $weight, 'cod' => 0,
    ],
    'I. EDGE: valid but almost certainly unserviceable (AQ)' => [
        'pickup_postcode' => $pincode, 'delivery_country' => 'AQ', 'weight' => $weight, 'cod' => 0,
    ],
];

/** Pull out whatever courier list shape the response uses. */
function courier_list($body) {
    if (!is_array($body)) return null;
    foreach ([['data', 'available_courier_companies'], ['available_courier_companies'], ['data']] as $path) {
        $cur = $body;
        foreach ($path as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) { $cur = null; break; }
            $cur = $cur[$k];
        }
        if (is_array($cur) && $cur && isset($cur[0]) && is_array($cur[0])) {
            return [implode('.', $path), $cur];
        }
    }
    return null;
}

out("### PHASE 2 — serviceability request matrix");
out("GET " . API_BASE . ENDPOINT . "?<params>   (Authorization: Bearer <token redacted>)");
out();

$results = [];

foreach ($cases as $label => $params) {

    $url = API_BASE . ENDPOINT . '?' . http_build_query($params);

    out("---------------------------------------------------------");
    out($label);
    out("  params : " . http_build_query($params));

    $r = http('GET', $url, $H, null, 30);
    $body = json_decode($r['body'], true);

    out("  HTTP   : {$r['status']}" . ($r['error'] ? "  (transport: {$r['error']})" : ''));

    if (is_array($body)) {
        out("  keys   : " . implode(', ', array_slice(array_keys($body), 0, 12)));
        if (isset($body['message'])) {
            out("  message: " . (is_scalar($body['message']) ? $body['message'] : json_encode($body['message'])));
        }
        $found = courier_list($body);
        if ($found) {
            [$path, $list] = $found;
            out("  couriers at '{$path}': " . count($list));
            $first = $list[0];
            out("  first courier field names: " . implode(', ', array_keys($first)));
            foreach (['rate','freight_charge','total_charge','shipping_charge','currency','etd','estimated_delivery_days','transit_time','courier_name','courier_company_id'] as $k) {
                if (array_key_exists($k, $first)) {
                    $v = $first[$k];
                    out("    {$k} = " . (is_scalar($v) ? $v : json_encode($v)));
                }
            }
        } else {
            out("  couriers: none found in any known envelope");
        }
    } else {
        out("  body (first 400 chars): " . substr((string) $r['body'], 0, 400));
    }

    $results[$label] = ['status' => $r['status'], 'body' => $body];
    usleep(PAUSE_US);
}

/* ------------------------------------------------------------------ *
 * Full raw dump of the decisive case, redacted
 * ------------------------------------------------------------------ */

out();
out("### PHASE 3 — full raw response for case A (current plugin shape)");
$a = $results['A. CURRENT PLUGIN SHAPE (delivery_country=AE)'] ?? null;
out(redact(json_encode($a['body'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));

out();
out("### SUMMARY");
foreach ($results as $label => $r) {
    $found = courier_list($r['body']);
    out(sprintf("  HTTP %-4s  couriers=%-4s  %s",
        $r['status'],
        $found ? count($found[1]) : '0',
        $label
    ));
}

$path = __DIR__ . '/probe-result.txt';
file_put_contents($path, implode("\n", $TRANSCRIPT) . "\n");
out();
out("Transcript saved (credentials and tokens redacted): {$path}");
