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

## 7. Unverified contract — must be probed before build

The existing code is honest about Shiprocket's international endpoint being
guesswork pinned behind constants. iCarry is in the same position: there is no
publicly readable developer portal, credentials come from
*My Account → API Credentials* (or `icarry.in/api`), and the endpoint set,
auth scheme, parameter names and response envelope are **unconfirmed**.

So the first task is not code:

1. Obtain iCarry API credentials.
2. Extend the existing admin "Live probe" to target iCarry and dump raw JSON for:
   domestic serviceability, international serviceability, shipment creation, tracking.
3. Pin the findings into the provider class's CONTRACT block, exactly as
   `class-tis-api.php` does today.

Until step 3, every estimate below is provisional.

---

## 8. Build order and effort

| Phase | Work | Est. |
|-------|------|------|
| 0 | iCarry API discovery + probe harness | 0.5–1 d |
| 1 | ~~Provider interface; refactor Shiprocket behind it (no behaviour change)~~ **done** | 1.5–2 d |
| 2 | ~~Active-provider setting, switch lifecycle, admin UI~~ **done** | 1 d |
| 3 | iCarry rate provider — international | 1–1.5 d |
| 4 | Domestic lane: pincode cache policy, volume-based warming, COD | 1.5–2 d |
| 5 | Order sync: records, queue, push, idempotency | 2–3 d |
| 6 | Tracking pull, customer-facing display, failed-sync admin | 1.5–2 d |
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

- **iCarry contract unknown.** Biggest unknown; phase 0 exists to retire it.
- **Domestic cache cannot be fully warmed.** Mitigated by top-N warming plus a
  bounded live call — decided, see §4. Domestic checkout will still be measurably
  slower than international on a cold lane; the mitigation is a per-provider
  timeout that can be tightened below the international default.
- **Duplicate shipments on sync retry.** Costs real money. Idempotency via the
  shipment record is a requirement, not a nice-to-have.
- **Switching providers mid-fulfilment.** Handled by storing `provider` per shipment.
- **Rate parity.** Customers quoted by one aggregator and shipped by the other will
  show margin drift; the switch confirmation should say so.
