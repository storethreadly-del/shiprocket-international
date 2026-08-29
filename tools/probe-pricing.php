<?php
/**
 * Shiprocket X international PRICING investigation.
 *
 * The serviceability endpoint returns Aramex International for AE with
 * rate = null, tariff = 0 and tariff_data = []. This script determines, by
 * controlled experiment, which request inputs (if any) cause Shiprocket to
 * populate a real price, and exactly where that price lives.
 *
 * Standalone: no WordPress, no database, no plugin state. Every call is a
 * read-only GET apart from a single auth POST. Credentials are read from
 * wp-config.php constants or the environment and are never printed; the token
 * is redacted from all output.
 *
 * Usage:
 *   php tools/probe-pricing.php --pincode=642001
 *
 * @package Threadly_Intl_Shipping
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

const API_BASE = 'https://apiv2.shiprocket.in/v1/external/';
const PAUSE_US = 700000;

/* ---------- credentials (never printed) ---------- */

function wpconfig_const($name) {
    foreach ([__DIR__ . '/../../../../wp-config.php', __DIR__ . '/../../../../../wp-config.php'] as $p) {
        if (!is_readable($p)) { continue; }
        $src = file_get_contents($p);
        if (preg_match("/define\(\s*['\"]" . preg_quote($name, '/') . "['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $src, $m)) {
            return $m[1];
        }
    }
    return null;
}

$email = wpconfig_const('TIS_SR_EMAIL')    ?: getenv('TIS_SR_EMAIL')    ?: '';
$pass  = wpconfig_const('TIS_SR_PASSWORD') ?: getenv('TIS_SR_PASSWORD') ?: '';

$pincode = getenv('TIS_PICKUP_PINCODE') ?: '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--pincode=')) { $pincode = substr($a, 10); }
}

if ('' === $email || '' === $pass) {
    exit("ERROR: no credentials. Add TIS_SR_EMAIL / TIS_SR_PASSWORD to wp-config.php or the environment.\nThey are never printed.\n");
}
if ('' === $pincode) {
    exit("ERROR: pass --pincode=642001\n");
}

$SECRETS = array_filter([$email, $pass]);

function redact($t) {
    global $SECRETS;
    foreach ($SECRETS as $s) { if ('' !== $s) { $t = str_replace($s, '<redacted>', $t); } }
    return preg_replace('/eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', '<token redacted>', $t);
}

$TRANSCRIPT = [];
function out($l = '') {
    global $TRANSCRIPT;
    $l = redact((string) $l);
    $TRANSCRIPT[] = $l;
    echo $l . "\n";
}

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
    if (null !== $body) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['status' => $code, 'body' => $raw, 'error' => $err];
}

/* ---------- auth ---------- */

out("=====================================================================");
out(" Shiprocket X — international PRICING investigation");
out(" origin pincode : {$pincode}   destination : AE");
out(" started        : " . gmdate('c'));
out("=====================================================================");
out();

$auth = http('POST', API_BASE . 'auth/login',
    ['Content-Type: application/json', 'Accept: application/json'],
    json_encode(['email' => $email, 'password' => $pass]));

$ab = json_decode($auth['body'], true);
$token = (is_array($ab) && !empty($ab['token'])) ? $ab['token'] : null;

if (!$token) {
    out("AUTH FAILED — HTTP {$auth['status']}");
    out(redact(substr((string) $auth['body'], 0, 400)));
    file_put_contents(__DIR__ . '/probe-result.txt', implode("\n", $TRANSCRIPT) . "\n");
    exit(1);
}
$SECRETS[] = $token;
out("Auth OK (token redacted, length " . strlen($token) . ")");
out();

$H = ['Authorization: Bearer ' . $token, 'Accept: application/json'];

/* ---------- the experiment matrix ----------
 * A is the exact request the plugin sends today (the control). Each later
 * variant adds ONE dimension of information, so any change in the pricing
 * fields can be attributed to that input alone.
 */

$W = 0.5;
$base = ['pickup_postcode' => $pincode, 'delivery_country' => 'AE', 'weight' => $W, 'cod' => 0];

/*
 * These figures reproduce a shipment Shiprocket has already quoted
 * successfully (Sharjah UAE: 0.5kg dead weight, 33x4x4cm, 0.105kg volumetric,
 * Rs.920 declared). Replaying a known-priced shipment is far stronger evidence
 * than invented values. They are test inputs only — no order or customer
 * record is referenced here.
 */
$dims = ['length' => 33, 'breadth' => 4, 'height' => 4];
$pin  = ['delivery_postcode' => '122127'];
$val  = ['order_value' => 920];

$cases = [
    'A. CONTROL — exactly what the plugin sends' => $base,
    'B. + dimensions only (33x4x4)'              => $base + $dims,
    'C. + delivery_postcode only (122127)'       => $base + $pin,
    'D. + order_value only (920)'                => $base + $val,
    'E. + declared_value only (920)'             => $base + ['declared_value' => 920],
    'F. dimensions + delivery_postcode'          => $base + $dims + $pin,
    'G. FULL REPRODUCTION of the priced order'   => $base + $dims + $pin + $val,
    'H. full, but declared_value naming'         => $base + $dims + $pin + ['declared_value' => 920],
    'I. dimensions + order_value (no postcode)'  => $base + $dims + $val,
    'J. heavier apparel: 2kg, 40x30x20'          => ['pickup_postcode' => $pincode, 'delivery_country' => 'AE', 'weight' => 2, 'cod' => 0]
                                                    + ['length' => 40, 'breadth' => 30, 'height' => 20] + $pin + ['order_value' => 3000],
];

/** Pull the courier list out of whichever envelope is used. */
function couriers($body) {
    if (!is_array($body)) { return []; }
    foreach ([['data', 'available_courier_companies'], ['available_courier_companies'], ['data']] as $path) {
        $cur = $body;
        foreach ($path as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) { $cur = null; break; }
            $cur = $cur[$k];
        }
        if (is_array($cur) && isset($cur[0]) && is_array($cur[0])) { return $cur; }
    }
    return [];
}

/** Any populated, positive, price-looking field anywhere in the row. */
function positive_prices($row, $prefix = '', $depth = 0) {
    $hits = [];
    if (!is_array($row) || $depth > 4) { return $hits; }
    foreach ($row as $k => $v) {
        $path = $prefix ? "$prefix.$k" : (string) $k;
        if (is_array($v)) {
            $hits = array_merge($hits, positive_prices($v, $path, $depth + 1));
        } elseif (is_numeric($v) && (float) $v > 0 && is_string($k)
            && preg_match('/(rate|charge|amount|total|price|freight|cost|fee|tariff|duty|vcn)/i', $k)) {
            $hits[$path] = (float) $v;
        }
    }
    return $hits;
}

function typeof_val($v) {
    if (null === $v)      { return 'NULL'; }
    if (is_bool($v))      { return 'bool(' . ($v ? 'true' : 'false') . ')'; }
    if (is_array($v))     { return empty($v) ? 'EMPTY_ARRAY' : 'array(' . count($v) . ')=' . substr(json_encode($v), 0, 200); }
    if (is_string($v))    { return 'string("' . $v . '")'; }
    return gettype($v) . '(' . var_export($v, true) . ')';
}

$summary = [];

foreach ($cases as $label => $params) {

    $url = API_BASE . 'courier/international/serviceability/?' . http_build_query($params);
    $r   = http('GET', $url, $H);
    $b   = json_decode($r['body'], true);
    $cs  = couriers($b);
    $row = $cs[0] ?? null;

    out("---------------------------------------------------------------------");
    out($label);
    out("  params : " . http_build_query($params));
    out("  HTTP   : {$r['status']}");

    if (is_array($b)) {
        out("  top-level currency : " . (isset($b['currency']) ? var_export($b['currency'], true) : '(absent)'));
        out("  top-level status   : " . (isset($b['status']) ? var_export($b['status'], true) : '(absent)'));
        if (isset($b['message'])) { out("  message : " . (is_scalar($b['message']) ? $b['message'] : json_encode($b['message']))); }
    }

    $prices = [];

    if ($row) {
        out("  couriers : " . count($cs) . "  (" . ($row['courier_name'] ?? '?') . ")");
        out("  --- pricing-relevant fields, exact type and value ---");
        foreach (['rate','tariff','tariff_data','weight_cases','charge_weight','min_weight',
                  'coverage_charges','extra_charges','extra_charges_total','international_vat_amount',
                  'ioss_fee','eori_fee','lm_vcn_rate','euro_duty_data','ddp_vcn','ddp_inclusive_vcn',
                  'surchar_details','estimated_delivery_days','etd'] as $k) {
            if (array_key_exists($k, $row)) {
                out(sprintf("    %-26s %s", $k, typeof_val($row[$k])));
            }
        }
        $prices = positive_prices($row);
        out("  --- ANY positive price-like value found ---");
        out("    " . ($prices ? json_encode($prices) : 'NONE'));
    } else {
        out("  couriers : 0");
        out("  body (400 chars): " . substr((string) $r['body'], 0, 400));
    }

    $summary[$label] = [
        'http'     => $r['status'],
        'couriers' => count($cs),
        'rate'     => $row && array_key_exists('rate', $row) ? typeof_val($row['rate']) : '-',
        'prices'   => $prices,
    ];

    usleep(PAUSE_US);
}

/* ---------- full raw row for the control, so nothing is hidden ---------- */

out();
out("### FULL RAW COURIER ROW — control case A");
$r = http('GET', API_BASE . 'courier/international/serviceability/?' . http_build_query($base), $H);
$cs = couriers(json_decode($r['body'], true));
out(redact(json_encode($cs[0] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));

/* ---------- does a different endpoint exist? read-only existence check ---------- */

out();
out("### ENDPOINT EXISTENCE CHECK (read-only; 404 simply means 'not this one')");
foreach ([
    'courier/international/serviceability/',
    'courier/serviceability/',
    'courier/international/rate/',
    'courier/international/tariff/',
] as $ep) {
    $r = http('GET', API_BASE . $ep . '?' . http_build_query($base), $H, null, 15);
    out(sprintf("  HTTP %-4s  %s", $r['status'], $ep));
    usleep(300000);
}

/* ---------- verdict ---------- */

out();
out("### SUMMARY — which inputs produced a price?");
out(sprintf("  %-44s %-6s %-9s %s", 'CASE', 'HTTP', 'COURIERS', 'PRICE FOUND'));
foreach ($summary as $label => $s) {
    out(sprintf("  %-44s %-6s %-9s %s",
        substr($label, 0, 43), $s['http'], $s['couriers'],
        $s['prices'] ? json_encode($s['prices']) : 'none'));
}
out();
out("  rate field by case:");
foreach ($summary as $label => $s) {
    out(sprintf("    %-44s %s", substr($label, 0, 43), $s['rate']));
}

$path = __DIR__ . '/probe-result.txt';
file_put_contents($path, implode("\n", $TRANSCRIPT) . "\n");
out();
out("Transcript saved (credentials and tokens redacted): {$path}");
