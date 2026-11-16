# Scope: multi-aggregator shipping (Shiprocket + iCarry) with order sync

Status: proposal. Target version 0.3.0.
Current plugin: `threadly-international-shipping` v0.2.0 — Shiprocket X, international only,
rate-quoting only (no order push).

---

## 1. What changes conceptually

Three separate moves, in dependency order:

| # | Move | Why it's separate |
|---|------|-------------------|
| A | Make the plugin **provider-pluggable** (Shiprocket, iCarry) | Everything else depends on it |
| B | Add **domestic (IN→IN)** rating, which only iCarry serves | Breaks a core caching assumption — see §4 |
| C | Add **order → aggregator sync** (push shipments, pull AWB/tracking) | New concern; today the plugin is read-only |

The plugin stops being "international shipping" and becomes "Threadly Shipping".
Slug/text-domain stay (`threadly-intl-shipping`) to avoid a migration; display
name and description change.

---

## 2. Provider abstraction (move A)

Introduce one interface, two implementations:

```
includes/class-ts-http.php                            <- transport seam (done)
includes/class-ts-logger.php                          <- logging seam (done)
includes/providers/interface-ts-provider.php          <- the contract (done)
includes/providers/abstract-class-ts-provider-base.php<- shared machinery (done)
includes/providers/class-ts-provider-shiprocket.php   <- refactor of class-tis-api.php + class-tis-auth.php (done)
includes/providers/class-ts-provider-icarry.php       <- new, phase 3
includes/class-ts-provider-registry.php               <- active-provider resolution (done)
```

Shared machinery — the cache-only checkout token rule, the login mutex, the
consecutive-failure lockout, the circuit breaker, the rate cache and the
diagnostics counters — lives in `TS_Provider_Base` so iCarry inherits it rather
than reimplementing it. Storage keys are indirected through `key()`, which is
how the Shiprocket provider keeps every v0.2.0 option and transient name: a
rename would orphan stored credentials and discard a warm token and cache.

`TIS_Auth` and `TIS_API` survive as deprecated shims over the Shiprocket
provider, so site-level and third-party callers keep working. Nothing inside the
plugin uses them any more.

Interface surface (each provider declares its own capabilities):

- `id()`, `label()`
- `capabilities()` → `['domestic' => bool, 'international' => bool, 'order_sync' => bool]`
- `has_credentials()`, `cached_token()`, `token()`, `test_connection()`, `forget()`
- `rates($origin, $destination, $weight, $opts)` → normalised rate rows
- `warm_lanes()` → the lane list this provider wants kept warm
- `create_shipment($order)`, `fetch_tracking($ref)`, `cancel_shipment($ref)` (order sync)

The existing normalised rate row shape is kept verbatim as the contract
(`courier_id`, `courier_name`, `rate`, `transit_days`, `edd`) and extended with
`provider`, `service_type`, `cod_available`. Everything above the provider layer
(shipping method, checkout display, markup, fallback) is written against that row
and does not learn either vendor's API shape.

Per-provider isolation that already exists for Shiprocket is preserved per
provider: its own credentials, own token transient, own failure counter, own
lockout, own circuit breaker, own cache key namespace. One vendor being down or
locked out must not affect the other.

---

## 3. Auto activate / deactivate (the mutual-exclusion requirement)

**One setting, not two toggles.** A single `ts_active_provider` option with values
`shiprocket` | `icarry`. Selecting one deactivates the other by construction — there
is no state in which both are serving rates, so no double-quoting at checkout and
no ambiguity about which dashboard an order belongs to.

Behaviour on switch:
1. Confirm in the admin UI (it changes what customers are quoted).
2. Flush the *outgoing* provider's rate cache + token; leave its credentials intact
   so switching back is instant.
3. Reschedule the warm cron against the incoming provider's lane set.
4. Fire `ts_provider_switched` ($from, $to) and log it.
5. Admin notice if the incoming provider has no credentials — checkout falls back
   to the flat fallback rate rather than showing nothing.

Guard rails:
- Selecting a provider whose credentials are missing is allowed but warned, never
  silently broken.
- `ts_active_provider` is filterable (`ts_active_provider`) so the HIE tool, a
  staging constant, or a future per-zone rule can override it without a code change.
- In-flight orders already pushed to the old aggregator keep syncing against it —
  the stored shipment record carries its own `provider` field, so tracking never
  follows the global switch.

Explicitly **out of scope** for 0.3.0 (worth noting as the natural next step):
running both providers simultaneously and picking cheapest-of-both. The abstraction
is built so that becomes a change to the registry only, not a rewrite.

---

## 4. Domestic + international for iCarry (move B)

iCarry serves both lanes, so when it is active:
- the `'IN' === $country` early return in `calculate_shipping()` is lifted and routed
  to iCarry instead;
- when Shiprocket is active, that return stays — Shiprocket's own official plugin
  still owns India.

**The caching problem.** Today's warmer works because international rating is
country-level: one cached entry per `country × weight slab`. Domestic Indian rating
is **pincode-level**, which is ~19,000 destinations — the warm-every-lane strategy
does not transfer. Domestic therefore gets a different cache policy:

- key on `origin_pin × destination_pin × weight slab`, short TTL (hours);
- warm only the top-N destination pincodes by historical order volume (read from
  past WooCommerce orders), not the whole country;
- **a cold domestic lane makes one bounded live call at checkout** — decided; see
  below.

**Correction to an earlier draft of this section.** It claimed international keeps
a stricter "cache or fallback, never block" rule, and framed the bounded live call
as a relaxation for domestic. That was wrong. v0.2.0 already makes a bounded live
call on a cold international lane; what it never does is *log in* on the customer's
thread. So the decision makes domestic **consistent** with international rather
than weaker than it, and the rule across both lanes is:

> Never authenticate on the customer's thread. One bounded rate call on a cold
> lane is fine, because three things together keep it safe: a short timeout, a
> circuit breaker that stops calling a failing aggregator at all, and a token
> that is read from cache and never minted inline.

Implemented ahead of phase 4, since it is vendor-independent:

- `frontend_timeout()` and `negative_cache_ttl()` are provider methods, both
  filterable **per provider** (`tis_frontend_timeout`, `ts_negative_cache_ttl`,
  each passed the provider id). A domestic provider that expects frequent cache
  misses can tighten its budget below the international default of 5s without
  affecting the other.
- **Fixed a defect this decision made material.** The checkout path cached an
  empty live result for the full rate TTL (26h by default), so a lane that was
  briefly unserviceable kept serving the fallback rate for a day after the
  aggregator recovered. The warmer had always cached empties for an hour instead;
  the checkout path now applies that same policy, and the number lives in one
  place. Minor for a handful of countries, bad for pincode-level lanes where a
  cold miss is routine.
- A transport failure is still never cached as an empty lane; it trips the
  breaker, so the failure is not mistaken for "no couriers serve this route".

Also in this move: COD. Domestic Indian orders use it heavily, international never
does. `cod` stops being hardcoded `0` and is derived from the chosen payment method
/ cart, and becomes part of the cache key (the key already supports it).

---

## 5. Order sync to the iCarry dashboard (move C)

A new module, deliberately kept independent of the rating path:

```
includes/sync/class-ts-sync.php            <- orchestration, idempotency, retries
includes/sync/class-ts-shipment-record.php <- local record: order_id, provider, ref, awb, status
includes/sync/class-ts-sync-queue.php      <- Action Scheduler backed
```

Flow:
1. Trigger on a configurable order status transition (default: processing → push),
   plus a manual "Send to iCarry" row action and a bulk action.
2. Map WooCommerce order → provider shipment payload: pickup location, consignee,
   line items with HSN codes, declared value, billable weight (reuse the existing
   `get_billable_weight()` — max of actual vs volumetric, 0.5kg slabs), the courier
   the customer actually selected at checkout (it is already stored in rate meta as
   `tis_courier_id`), and for international the commercial-invoice fields.
3. Store the returned shipment id / AWB / label URL as order meta **and** in the
   shipment record table.
4. Pull status + tracking on a schedule; write back to order notes, and optionally
   transition the order to completed on delivery.
5. Surface tracking to the customer on the order-received page, My Account, and in
   the completed-order email.

Hard requirements:
- **Idempotent.** An order is pushed at most once per provider; the shipment record
  is the lock. A retried cron or a double status transition must not create a second
  shipment (a duplicate shipment is a real cost, not just a bug).
- **Never synchronous on a customer request.** All pushes go through the queue.
- Full audit: every push, response and status change logged to the existing
  `tis-shiprocket` logger (renamed `threadly-shipping`), secrets never logged.
- Failures are visible: a failed-sync admin list with retry, not just a log line.

---

## 6. Reuse in the HIE tool

The sync layer is written so the HIBOU Intelligence Engine consumes it without
importing WooCommerce specifics. Three seams:

1. **Event contract.** Every state change fires a documented action with a stable
   payload: `ts_shipment_created`, `ts_shipment_status_changed`,
   `ts_shipment_delivered`, `ts_shipment_failed`, `ts_provider_switched`.
   HIE subscribes rather than polling.
2. **Read API.** A namespaced REST route (`/wp-json/threadly-shipping/v1/shipments`,
   `/shipments/{id}`, `/rates`) with its own capability check and key, returning the
   same normalised shapes. HIE reads shipment state over HTTP, no DB access.
3. **Provider adapters are framework-free.** The two provider classes depend only on
   the HTTP client and the logger, both injected. The same adapters can be lifted into
   HIE to quote or book outside WordPress.

The pre-existing credential-isolation rule stands and matters more here: this plugin
authenticates with its **own** API user per aggregator, separate from HIE's, so a
lockout on one side cannot block checkout on the other.

---

## 7. iCarry contract — what phase 0 established

Read from iCarry's own
[Shipping APIs, Plugins & Extensions](https://www.icarry.in/pages/shipping-api-plugins-extensions.html)
page and
[Courier API Integration in India](https://www.icarry.in/pages/blog/courier-api-integration-india.html).

**Confirmed.** iCarry publishes an API-first platform and *names* 19 REST
endpoints. Every capability this project needs exists:

| Need | iCarry endpoint (as named) | Phase |
|---|---|---|
| Auth | `Authentication`, `LOGIN` | 3 |
| Domestic rating | `Get ESTIMATE - Domestic shipment (within India)` | 4 |
| Domestic rating, one courier | `Get ESTIMATE - By Courier (Domestic India)` | 4 |
| International rating | `Get ESTIMATE - International Shipment (India to Foreign Country)` | 3 |
| Pincode check | `Check SERVICEABILITY by PINCODE` | 4 |
| Book | `Book Shipment - STANDARD / EXPRESS`, `- CHOOSE COURIER`, `- INTERNATIONAL` | 5 |
| Cancel | `CANCEL Shipment` | 5 |
| Returns | `REVERSE Shipment` | later |
| Tracking | `TRACK a Shipment`, `SYNC Shipment STATUS` | 6 |
| **Status webhook** | `POST Call Back on change in Shipment STATUS` | 6 |
| Cost reconciliation | `SYNC Shipment CHARGES` | later |
| Labels | `PRINT Shipment Label` | 5 |
| Pickup locations | `Pickup Address`, `ADD`, `UPDATE` | 5 |

Credentials come from **My Account → API Credentials → Generate Key**, a one-time
step. A WooCommerce connector already exists, as do Shopify, WIX, Zoho, Opencart
and PrestaShop ones.

**Three findings that change the plan for the better:**

1. **There is a status webhook.** `POST Call Back on change in Shipment STATUS`
   means phase 6 does not have to poll for tracking. Push is cheaper, fresher and
   simpler, and it maps straight onto the HIE event contract in §6 — an inbound
   callback becomes a `ts_shipment_status_changed` event with no polling layer in
   between. Polling (`SYNC Shipment STATUS`) stays as the reconciliation fallback
   for missed deliveries, not the primary mechanism.
2. **Rating is split by lane, with a courier-specific variant.** Domestic and
   international are separate endpoints, so the iCarry provider's `rate_url()`
   picks per destination rather than sending a country to one endpoint. The
   `By Courier` variant is what phase 5 needs to re-quote the exact courier the
   customer chose at checkout before booking it.
3. **`Check SERVICEABILITY by PINCODE` is cheap pre-validation.** Phase 4 can
   reject an unserviceable pincode without spending a rate call, and cache that
   verdict separately from rates — it changes far less often.

**Still unknown, and still blocking code:** the base URL, the exact paths, the
auth scheme (bearer token vs. API key header, and whether `LOGIN` returns a
token with a TTL like Shiprocket's), the parameter names, and the response
envelopes. iCarry publishes endpoint *names* only — the list is a plain `<li>`
list with no links, no schemas and no examples. The detail is behind account
login or a support ticket.

### ⚠️ The wordpress.org "iCARRY" plugin is a DIFFERENT COMPANY — do not use it

The obvious route to the contract was iCarry's open-source WooCommerce connector
on wordpress.org. It was downloaded and read. **It is not this vendor.**

The plugin at `wordpress.org/plugins/icarry/` targets **iCarry.com**, a Middle
East shipping company. Evidence, from the plugin source:

- Its only store options are `https://lb.icarry.com/` (Lebanon),
  `https://uae.icarry.com/`, `https://kw.icarry.com/` (Kuwait) and
  `https://ksa.icarry.com/` (Saudi Arabia).
- Ten references to `icarry.com`, **zero** to `icarry.in`.
- `readme.txt` points at `icarry.com/terms`, `icarry.com/privacy` and
  `developer.icarry.com` — so that developer portal is also the wrong entity.
- **No pincode concept anywhere in the codebase.** It geocodes street + city
  through the Google Maps API, which is Middle East addressing, not Indian.

Its contract — `api-frontend/Authenticate/GetTokenForCustomerApi`,
`api-frontend/SmartwareShipment/EstimateRatesByCOD`,
`api-frontend/SmartwareShipment/CreateOrder`, bearer token, PascalCase JSON
(`Email`, `Password`, `Name`, `TrackingNumber`) — belongs to that company and
must **not** be used here. Building the provider on it would have produced
something that authenticated against the wrong vendor entirely while looking
perfectly plausible in review.

(Two further reasons not to treat that plugin as a reference even by analogy: it
sends `sslverify => false` on every call, and it keeps the account password in
plain WooCommerce settings. Neither is acceptable here.)

An earlier web search had flagged exactly this risk — a forum post linking
`developer.icarry.com` for "iCARRY", with a note that it looked like a UAE
company. That hunch is now confirmed fact.

### No public contract for iCarry.in

Checked and ruled out: `/api`, `/api-docs`, `/docs`, `/pages/api.html`,
`/pages/api-documentation.html`, `/pages/developer.html`,
`/pages/shipping-api.html` — all resolve to the homepage or a soft-404. The
endpoint list on the APIs page is a plain `<li>` list with no links, schemas or
examples. The per-platform setup guides are videos with no transcript.

**So the contract has to come from the account:**

- **Preferred — the API Credentials screen.** My Account → API Credentials →
  Generate Key. Share whatever documentation that screen links to (paths,
  parameters, sample payloads). The key itself goes in the plugin's settings
  screen or a wp-config constant, never into chat.
- **Or a support ticket.** iCarry's own APIs page invites this, and support is
  reachable at the ticket desk once registered.

Once either lands: extend the admin probe to target iCarry, dump raw JSON per
endpoint, and pin the findings into the provider's CONTRACT block exactly as
`class-ts-provider-shiprocket.php` does. Until then, phase 3 would be writing
code against guesses — which, as the section above shows, is not a theoretical
risk here.

### Credentials and the discovery probe (built)

iCarry.in issues a **username plus a generated API password** (not a single key,
and not a token exchange as far as is known), and the API Credentials screen
documents no endpoints. So the contract is discovered by probe.

`TS_ICarry_Credentials` stores them (wp-config constants `TIS_IC_USER` /
`TIS_IC_PASSWORD` take precedence over options), and `TS_ICarry_Probe` plus the
**WooCommerce > iCarry probe** screen send one operator-built request and show
the raw response. The operator picks how the credential is sent: HTTP Basic,
Bearer, a custom header, or fields in a JSON body. No iCarry *provider* is
registered yet, deliberately: registering one against an unknown contract would
put it in the switch UI where it could be activated.

Safety properties, each pinned by `tests/smoke-icarry-probe.php` and mutation
checked (breaking the host check, the redaction, or the lockout each fails tests):

- **icarry.in only**, HTTPS, exact host match. Refuses `icarry.com` (the other
  company), `icarry.in.evil.com`, `evilicarry.in`, userinfo, odd ports.
- **The password never leaves the server**: substituted at send time, never
  round-tripped through the form, redacted (raw and base64) from the echoed
  request, the response headers, the body and transport errors.
- **Lockout protection.** Three consecutive 401/403s pause the probe for 15
  minutes, since a failed-login lockout is per account. Success resets the count.

### What a live probe from the build session established

Run with the real credentials (supplied via environment variables, never printed).

- **iCarry.in is an OpenCart application.** The "API key" is generated in the
  browser (a 256-character random string filled by a *Generate* button on the API
  Credentials panel), which is exactly OpenCart's API-user form: a username plus a
  generated key.
- **The dashboard panels are session-based, not the REST API.** Every `_inner`
  route (`account/ela_estimate_inner`, `ela_estimate_international_inner`,
  `ela_shipment_inner`, `ela_pickup_address_inner`, ...) redirects to `/login`
  without a browser session. The estimate forms post `sender_pincode` and
  `receiver_pincode`, which at least confirms pincode-level domestic rating.
- **One authenticated attempt was made, and it was a miss.** OpenCart 3's standard
  handshake, `POST index.php?route=api/login` with `username` and `key`, returned
  **404** (route does not exist) rather than an auth rejection, so it should not
  have counted toward any lockout. The proxy-injected Basic header was also sent.
- **Still unknown:** where the REST API actually lives, and how it authenticates.
  Nothing public reveals it.

**Outcome of capturing the dashboard's own requests:** see the next two sections.
The dashboard turned out not to use the published API at all.

### The dashboard's booking form: the data model (not the contract)

From a screenshot of *Book Shipment > By Courier*. This is what the form collects
and shows, not what the API accepts, so field names below are the labels, not
parameters. No customer data from the screenshot is recorded here.

**Booking inputs (maps onto phase 5):**

| Form field | Notes for the mapper |
|---|---|
| Pickup Address, Shipper Address on Label, Return (RTO) Address | **Three separate saved addresses**, picked from a list, not typed per order. Phase 5 must look up or create them (the `Pickup Address` / `ADD` / `UPDATE` endpoints) and cache the ids. |
| Contact name, mobile, alternate mobile, customer email | Alternate mobile is separate from mobile |
| Street address, area and landmark | Each appears capped at **128 characters** (the counters read 115 and 118 remaining after 13 and 10 characters). WooCommerce address lines must be truncated or joined accordingly, or the booking may be rejected. |
| Pincode, city, region/state | State is a dropdown, so Woo's state codes need a mapping |
| Weight with unit (default grams), length/breadth/height with unit (cm) | Weight is entered in **grams** by default |
| Mode: Surface / Air / Hyperlocal | This is the rate row's `service_type` |
| Parcel type: Prepaid / Cash on Delivery | Drives the `cod` flag (phase 4) |
| Parcel contents: Documents / Products, plus a description | |
| Client order id, invoice no, invoice date, **invoice value** | Client order id is where the Woo order number belongs; invoice value is mandatory |
| Pickup date | Defaults to the next working day, best effort |

**Volumetric divisor is 5000 cm3/kg**, read from the site's own JS. It matches this
plugin's `tis_volumetric_divisor` default, so `get_billable_weight()` needs no
change.

**Rate results carry more than a price.** Each courier row shows a freight charge
*and a separate RTO charge*, a delivery-success rate, a forward transit range
("2-4 Business Days") with an average, an average RTO time, and tags (best overall,
fastest, most reliable, high weight discrepancy). The normalised rate row has no
home for the RTO charge or the delivery rate. Worth adding `rto_charge` and
`delivery_rate` in phase 3, since RTO exposure is a real cost on COD orders.

### The public pincode lookup (verified live, no login)

`GET https://www.icarry.in/index.php?route=checkout/ela_custom_shipping/calculate_city_state&pincode=<pin>&country_id=99`
with `X-Requested-With: XMLHttpRequest` returns JSON with no cookie. India is
`country_id=99`. It is the dashboard's own helper rather than the shipment API,
so it carries no stability promise; treat it as a convenience, not a contract.

- **`success` is not a validity flag.** `628001` returns the city, a `zone_id`
  and the full list of 38 states. `000000` returns `success: 1` with an **empty
  `city`** and `zone_id: 0`. `12345` (wrong length) returns `success: 0`. A
  pincode pre-check must therefore test for a **non-empty `city`**, not `success`.
- **State codes differ from WooCommerce's.** Of ten compared, seven differ:
  Bihar (Woo `BR`, iCarry `BI`), Chhattisgarh (`CT`/`CG`), Daman and Diu
  (`DD`/`DM`), Dadra and Nagar Haveli (`DN`/`DA`), Uttarakhand (`UT`/`UK`),
  Odisha (`OR`/`OD`), Delhi (`DL`/`DE`). Only Chandigarh, Telangana and Tamil
  Nadu matched. Phase 5 needs an explicit mapping table, not a pass-through; the
  response gives iCarry's own `zone_id`s and codes to build it from.
- It resolves a pincode to city and state, so phase 4 can fill or validate the
  destination at checkout without a rate call.

### The dashboard's estimate endpoint is NOT the API: do not build on it

Captured from the dashboard (values masked): `POST index.php?route=checkout/ela_custom_shipping_inner`,
form-encoded, `submit_action=booking_recalculate_estimate`.

- **Session-authenticated.** It carries `customer_id`, `cid`, three address ids and
  a browser session cookie. The username and API password do not authenticate it.
- **Server-rendered HTML, not data.** The JSON response is
  `{best_courier, estimate, success, success_details}`, where `success_details` is an
  HTML `<table>` with no per-courier objects.
- **Hidden-field state.** `chosen_courier=<courier_id>:<price>:<freight>:<total>`
  threads the previous selection through the request.

Using it would mean storing an account email and password, faking a login, and
scraping markup that changes with the page design, probably against iCarry's terms.
**Rejected.** The published API (the 19 endpoints) is the integration surface, and
its documentation has to come from iCarry.

What the capture does usefully confirm (form model, not contract): weight is sent in
grams with a unit field, `mode` is a letter (`S` for Surface), `parcel_type` is `P`
for Prepaid, `parcel_content_type` is `ND` for non-documents, `pickup_date` is
`YYYY-MM-DD`, the estimate's courier rows carry a numeric courier id, and the cost
splits into freight and RTO.

**To get the real documentation:** open a helpdesk ticket (the APIs page invites this)
or email support. A ready-to-send request is kept in `docs/icarry-api-request.md`.

### A finding that may shrink phase 5

iCarry.in already ships a **pull-based** WooCommerce connector: you give iCarry
your store's API credentials, and *iCarry* syncs your orders into *its* dashboard
("orders appear in your iCarry dashboard where you can book shipments
individually or in bulk"). Same model as their Zoho connector.

That is most of the "sync orders to the iCarry dashboard" requirement, available
with no code at all. What it does **not** appear to give:

- AWB, tracking and label written **back** into WooCommerce;
- rates at WooCommerce checkout (that is what this plugin's provider adds);
- an event stream the HIE tool can consume (§6).

So there is a real choice to make before phase 5 is scoped:

| | Their native connector | Our push sync (phase 5) |
|---|---|---|
| Orders in iCarry dashboard | ✅ no code | ✅ |
| Rates at checkout | ❌ | ✅ (phases 3–4) |
| AWB/tracking back into Woo | ❌ | ✅ |
| Events for HIE | ❌ | ✅ |
| Build cost | zero | 2–3 d + 1.5–2 d |

Recommendation: use their native connector for dashboard visibility immediately,
and keep phase 5 scoped to what it uniquely buys — write-back and the HIE event
stream — rather than duplicating order delivery.

---

## 8. Build order and effort

| Phase | Work | Est. |
|-------|------|------|
| 0 | iCarry API discovery + probe harness — *endpoint set confirmed, paths/auth still blocked (§7)* | 0.5–1 d |
| 1 | ~~Provider interface; refactor Shiprocket behind it (no behaviour change)~~ **done** | 1.5–2 d |
| 2 | ~~Active-provider setting, switch lifecycle, admin UI~~ **done** | 1 d |
| 3 | iCarry rate provider — international | 1–1.5 d |
| 4 | Domestic lane: pincode cache policy, volume-based warming, COD | 1.5–2 d |
| 5 | Order sync: records, queue, push, idempotency | 2–3 d |
| 6 | Tracking **via iCarry's status webhook** (§7), customer-facing display, failed-sync admin | 1.5–2 d |
| 7 | REST read API + event contract for HIE | 1 d |
| 8 | Hardening: provider-switch tests, duplicate-push tests, docs | 1–1.5 d |

Roughly **11–15 working days**, phase 0 gating the rest.

Phases 1–2 are shippable on their own (same Shiprocket behaviour, new structure),
which is the safe first merge.

---

## 9. Phase 1 as built

Landed: the two injection seams, the provider contract, the shared base, the
Shiprocket provider, the registry, and the deprecated shims. Consumers were
moved onto the contract: the shipping method, the warmer and the settings screen
all now talk to `TS_Provider_Registry::active()`.

Two changes above the provider layer are worth calling out:

- The hardcoded `'IN' === $country` guard in `calculate_shipping()` is gone,
  replaced by `$provider->serves($destination)`. Shiprocket declares
  `domestic => false`, so the behaviour is identical today — but whether India is
  in scope is now a property of the active provider rather than a constant of
  the plugin, which is what phase 4 needs.
- The warmer no longer computes `countries × slabs` itself; it asks the provider
  for `warm_lanes()`. That is the hook domestic warming needs, since it cannot
  enumerate its lanes the same way.

Rate rows gained `provider`, `service_type` and `cod_available`; rate meta gained
`tis_provider` and `tis_service_type`, so a shipment can later be synced against
the aggregator that actually quoted it.

**Verification.** `tests/smoke-provider.php` is a standalone harness — no
PHPUnit, no WordPress, no network — run with `php tests/smoke-provider.php`
(89 checks). It pins the behaviour that the refactor had to preserve:

- cache keys are byte-identical to the v0.2.0 md5 formula, checked against a
  verbatim copy of it, so upgrading inherits the warm cache instead of going
  cold;
- every legacy storage key still resolves to its v0.2.0 name;
- `serves()` reproduces the old country guard exactly, India included;
- checkout serves nothing and makes **no** HTTP call when no warm token exists —
  the rule that it must never log in on a customer's thread;
- the lockout trips after three rejections and then makes no further calls, and
  an open circuit suppresses live rating;
- the deprecated shims still delegate correctly.

That the harness needs no WordPress is itself the check on seam 3 of §6: if a
provider ever picks up a framework dependency, this is where it surfaces.

**Seam left open.** Storage is still WordPress-native (`get_transient`,
`get_option`). Every read and write funnels through the five storage primitives
at the foot of `TS_Provider_Base`, so abstracting it later is a change to those
five rather than a hunt through the class. HTTP and logging are already injected.

---

## 10. Phase 2 as built

`TS_Provider_Registry::activate($id)` is the whole switch lifecycle, and the
settings screen drives it. In order: refuse an unregistered target, store the new
id, stand the outgoing provider down, bring the warm cron forward, fire
`ts_provider_switched`.

**Standing the outgoing provider down** drops its rate cache, its token and its
circuit-breaker state, and deliberately keeps its credentials, so switching back
needs no re-entry.

**Bringing the cron forward** matters more than it looks. The warm event is
daily; without rescheduling, a switch would leave the incoming provider on a cold
cache — and therefore checkout on fallback rates — for up to 24 hours.

**Guard rails:**

- An unregistered target is refused outright rather than applied; a typo cannot
  leave the store with no provider.
- Re-selecting the active provider is a no-op, not a destructive re-apply — in
  particular it does not flush a warm cache.
- Switching to a provider with no credentials is allowed but warns, naming the
  consequence (checkout falls back).
- A `ts_active_provider` filter outranks the stored option, so a forced override
  would silently undo the operator's choice. `activate()` detects that the
  effective provider is not the one just chosen and says so.

**Admin UI.** A table of registered aggregators with capabilities, credential
state and which is active, as radio buttons — one selection, so mutual exclusion
is visible rather than merely enforced. The switch is gated behind a JS
confirmation because it changes live checkout pricing. With one provider
registered there is nothing to switch to, so the button is replaced by a note
saying so. Status notices now name the active aggregator instead of hardcoding
"Shiprocket".

Credential fields are still Shiprocket-specific; iCarry's belong with the iCarry
provider in phase 3.

**Verification.** `tests/run.sh` runs both harnesses and a syntax sweep.
`tests/smoke-provider.php` grew to 128 checks, covering the lifecycle against a
stand-in second provider (`tests/class-ts-provider-fake.php`, which declares
domestic + international + order_sync — without it the lifecycle would ship
unexercised, since only one real provider exists until phase 3). It pins: the
outgoing provider's cache, token and breaker are cleared while its credentials
survive; the cron is brought forward; `ts_provider_switched` fires once with
`($from, $to)`; an unknown target changes nothing; a no-op switch flushes nothing;
and the filter-override warning fires.

`tests/smoke-settings-page.php` (37 checks) renders the screen headlessly with
**every PHP notice and warning promoted to a failure**, since a template is
exactly the kind of code that lints clean and then breaks at runtime on an
undefined variable. It asserts one radio per provider, exactly one preselected,
the switch button appearing only when there is somewhere to switch to, the
confirmation being wired up, that the stored password is never echoed back, and
that notices follow the active provider.

---

## 11. Risks

- **iCarry contract partly unknown.** The endpoint set is now confirmed and every
  capability this project needs exists (§7). What is still missing is the base
  URL, exact paths, auth scheme, parameter names and response envelopes — enough
  to block writing the provider, but no longer enough to put the shape of the
  plan in doubt.
- **Name collision with a different company.** `iCarry.com` is an unrelated
  Middle East shipping company with its own API and its own WooCommerce plugin.
  Any future contributor reaching for "the iCarry docs" or "the iCarry plugin"
  will most likely find that one first. §7 records the distinguishing evidence;
  the provider's CONTRACT block should name `icarry.in` explicitly.
- **Domestic cache cannot be fully warmed.** Mitigated by top-N warming plus a
  bounded live call — decided, see §4. Domestic checkout will still be measurably
  slower than international on a cold lane; the mitigation is a per-provider
  timeout that can be tightened below the international default.
- **Duplicate shipments on sync retry.** Costs real money. Idempotency via the
  shipment record is a requirement, not a nice-to-have.
- **Switching providers mid-fulfilment.** Handled by storing `provider` per shipment.
- **Rate parity.** Customers quoted by one aggregator and shipped by the other will
  show margin drift; the switch confirmation should say so.
