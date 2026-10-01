# Minimal V2 Payment persistence — 2026-10-01

The 2026-10-01 [explicit full local reset](BILLING-DOMAIN-CUTOVER.md) deletes all five
processing tables without a provider exit manifest and starts every customer on Free.
Required existing financial/Admin history and carried credits remain intact. The
compact QR/event contract below remains active; newly created Payments in that epoch
are always compact. Unknown/old callbacks cannot recreate orphan evidence, Payments,
entitlements or credit refills. Older preserve-access cutover statements below are
compatibility history, not the final reset contract.


This is a source persistence contract, not approval to execute a migration, cutover,
provider operation or production deployment. Existing historical rows are not rewritten.
The three native checkout actions mark **new V2** Payments with
`meta.persistence_version = 2`. Unversioned/V1 rows retain their legacy readers and
writers until that application is retired. Removing this marker from a loaded compact
Payment cannot disable its persistence contract.

## Dependency audit and schema decision

The initial Payments migration (`2026_04_16_100000_create_payments_table.php`) and
subsequent subscription/application-review migrations already provide the required
normalized columns. No new column or migration is needed. Keep the physical schema
through historical cutover review; do not drop evidence ahead of reviewed retirement.

| Data | Consumers and decision for new V2 rows |
| --- | --- |
| Identity, purchase/provider type and mode, local/idempotency/provider references, purchasable identity | Keep normalized columns. Checkout replay, authorization, fulfillment, recovery and provider matching need these. |
| Amount/currency, original/discount/final subtotal and coupon identity | Keep columns. Amount is the provider gross total; discounted subtotal is not always the same amount. Financial comparisons, fees, orders and coupon history use distinct meanings. |
| Status/internal status/provider statuses | Keep. Collection, subscription renewal and local application are distinct state machines. |
| Lifecycle dates including callback receipt time | Keep. Coverage, expiration, recovery, polling and audit need their distinct timestamps. |
| `readable_code`, `provider_links` | Keep once for the owned checkout UI. Links remain private; no duplicate link structure in raw responses. Links limited to 8 KiB. |
| `qr_code` | Always NULL. QR presentation uses a disposable non-database cache described below. |
| `callback_payload` | Always NULL. Receipt timestamp, callback sequence and one bounded notification in metadata replace owned callback echo events. A callback never becomes verified payment truth. |
| `create_payload` | Keep at most 2 KiB: description/local-reference evidence, monetary value, interval and trial period. Callback/redirect URLs and presentation title are omitted. |
| `create_response` | Keep at most 1 KiB: returned object IDs and exact HTTPS checkout host. No QR, private duplicate links or debug body. A conservative collection-evidence hint can also remain. |
| `status_response` | Keep at most 4 KiB: latest verified identity, money, reference, status, required paid flags and timestamp aliases. No payer banking details or debug body; replace rather than append on each GET. |
| `cancel_response` | Keep at most 2 KiB: accepted/result/source/object type/provider status and bounded trace/error codes. POST acceptance is not cancellation confirmation. |
| `mismatch_reason` | Single local reason authority: lowercase machine code, max 64 characters. Preserve existing valid codes; fallback for unstructured input is `manual_review_required`. |
| `status_reason` | Always NULL for new V2 rows; retain physical column for legacy readers/history. |
| `declining_reason` | Keep separate provider decline evidence; it does not replace the local review reason. |

`qr_code`, `callback_payload` and `status_reason` are **future removal candidates**,
not columns safe to drop in this change. Legacy checkout and historical evidence
consumers still exist. Dropping them merely because a future cutover empties Payments
would leave those source dependencies broken. The three compact response columns and
minimal request column remain necessary recovery/evidence structures, not drop candidates.

## Commercial and operational ownership

`purchase_snapshot` is immutable after insertion (16 KiB maximum). It owns purchased
name/code, cycle, allowance/quota/credit facts, display currency/exchange facts, fee
terms, coupon terms when relevant, and the selected payment method. Equal display
structures are stored once. Amounts and intended purchasable ID already in normalized
columns are not copied into JSON. `Payment::snapshot()` reconstructs the compatible
read shape; `feeQuote()` reconstructs subtotal/gross values from those columns. No
fulfillment caller needs a second commercial snapshot in metadata.

`meta` is limited to 16 KiB and owns operational checkout origin, version, retry
signature/count/time/pause flags, compact failure classification, review context,
cancellation intent/confirmation and resolution identity. It does not retain
`fee_quote`, `coupon`, `payment_method_code`, `payment_driver`, `provider_object_type`
or a second purchase snapshot. Structured failure fields replace duplicate flat
HTTP/error/trace fields; provider reference/type already have columns. Free-text
failure explanations are not duplicated in Payment metadata and reason columns.

Known reason codes include `provider_create_failed`, `provider_declined`,
`provider_not_found`, `provider_sync_failed`, `application_state_mismatch`, existing
financial-evidence codes and the conservative `manual_review_required` fallback.
Structured review context retains relevant intended/current plan IDs. Historical
event/audit reasons remain evidence, not a second mutable current-reason authority.

Bounded JSON permits at most 64 entries per container, eight nested levels and
2,048 bytes per string. Oversized retained values throw rather than silently truncate
financial evidence. QR/raw-body/secret field names and data URIs are excluded from
these JSON structures. This contract applies through Eloquent Payment saving and
PaymentEventRecorder; direct SQL is not an authorized new-checkout writer.

## Recovery, callbacks and provenance

Creation events use the same compact response/request projection as their Payment.
`FibProviderProvenance` still requires a matching creation event, provider/object/
local identities, request and response. New rows retain the returned HTTPS host
(`p-stage.fib.iq` or `p.fib.iq`); legacy full-response matching is unchanged. Current
configuration, database location and HTTP 404 never prove environment provenance.

GET transition events and the current Payment use the same compact status projection.
Existing money, reference, timestamp,
collection-cycle and cancellation checks remain. All paid/time aliases used by the
current DTOs/mappers are retained. If an unrecognized structure contained collection
evidence recognized by the existing Admin guard, retain only
`unmapped_collection_evidence: true`; it blocks invalidation but cannot fulfill a
payment or confirm cancellation. Historical observation events remain intact;
**unchanged provider status checks no longer append PaymentEvents**.

Owned callback metadata retains only bounded `id`, `paymentId`, `subscriptionId`,
`status`, `paymentStatus` tokens plus a monotonic conservative collection hint.
Existing callback
body-size limits, ownership/reconciliation and authenticated-GET authority remain.
An authenticated 404 records unresolved failure evidence and a bounded reason; it
does not trigger a cancellation POST or retirement exemption.

Admin/customer billing reads keep snapshot accessors and sanitized local evidence.
No raw provider response is needed to render purchase facts, amount, state, dates or
provider references. The existing five-table cutover algorithm and its fingerprint,
wallet/ledger/order/access/job/file preservation guards are unchanged.

## PaymentEvent frequency contract

PaymentEvent describes meaningful lifecycle/audit transitions, not every provider
observation. This frequency rule applies to the shared native FIB status/callback
pipeline, including unversioned Payments; it does not rewrite historical events.

- DRAFT → DRAFT, awaiting customer action → unchanged, ACTIVE → ACTIVE and
  PAID → PAID with the same collection/coverage evidence: **zero new events**.
  `last_status_checked_at` and bounded current operational fields still advance.
- DRAFT → ACTIVE and ACTIVE → PAID: one `provider_status_changed` per transition.
  An independently successful application also retains its `payment_fulfilled`
  audit event; repeated paid checks never repeat fulfillment.
- A new verified collection timestamp while status remains ACTIVE/PAID is genuine
  financial evidence: one `provider_collection_verified`, plus the existing keyed
  renewal/application audit as applicable. Other meaningful retained evidence
  changes use `provider_evidence_changed`. First verified terminal evidence for a
  legacy terminal row without a GET anchor also gets one durable evidence event.
- Transition identity combines the locked Payment's monotonic revision and its
  before/after business state. Repeated polls deduplicate, while a genuinely repeated
  transition after an intervening state is not lost. Debug fields and poll time are
  excluded. A transaction commits the Payment update, receipt and event together.
- Owned duplicate callbacks write **no `callback_received` or `callback_processed`
  echo events**. They replace one bounded notification and increment a sequence.
  Only authenticated GET results can create lifecycle transitions. A callback during
  an in-flight GET makes that response stale; a subsequent GET must verify state.
  Existing keyed orphan/rejection security evidence remains.
- Identical checkout, renewal and callback failures update one event per Payment,
  provider reference, source, endpoint and error signature. `failure_count`,
  `first_seen_at`, `last_seen_at` and latest sanitized detail are retained; trace-ID
  changes and passage into another time bucket do not add rows. Consecutive counters
  retain the existing review/pause policy. A distinct failure identity can create
  another event. Existing time-bucket events are not deleted or rewritten; the first
  post-upgrade failure may start one new stable aggregate. Ineligible checkout/renewal
  failures are still skipped under the existing reconciliation policy.

`meta.provider_observation` holds one bounded authenticated-GET receipt: checked time,
object/local identity, response digest, transition revision, immutable event anchor
and digest (if any), callback sequence and pre-GET event watermark. Unchanged pending
or active state may have no transition anchor. Readers validate current identity,
timestamps, payload digest, callback sequence and any anchored event. Failures/rejected
GETs invalidate the receipt. Old callback events beyond the captured watermark also
invalidate it. Cancellation/cutover readers cannot fall back to stale confirmation
context when a new receipt is invalid. Legacy rows without receipts retain their
strict event/response validation. Bulk review uses the same validator without per-row
event queries. No callbacks, 404s or receipt markers alone establish payment or retirement.

Creation/provenance, fulfillment, new collection/renewal, cancellation intent/result,
rejection, Admin review and cutover audit evidence remain durable. The existing noise
pruner explicitly protects all three new transition/collection/evidence event types.
A normal direct
checkout success has **four** events: local creation, provider creation, verified paid
transition and fulfillment. A subscription observed as DRAFT → ACTIVE → PAID has
**five** (two transitions). Additional real renewal/cancellation/review actions add
their own evidence; idle polling and duplicate callbacks add none.

## QR display without MySQL image persistence

The existing V2 owned checkout page uses `CheckoutQrCache`. Creation temporarily
caches validated raster data URIs (maximum 256 KiB), scoped to customer ID and Payment
UUID. The existing UI safety filter remains. Cache lifetime is the remaining provider
checkout expiry, with no shorter arbitrary cap. The creation response's deadline is
used, falling back to the existing checkout deadline resolver. Without a known
deadline no QR is cached (the checkout already requires review). Only the awaiting
owned checkout reads/displays it. No public QR endpoint is introduced.

Use the configured cache only when its driver is Redis, Memcached, file or array.
Database/failover/other stores fall back to the existing private file store; a
misconfigured fallback or cache outage safely omits the image. It never blocks
persistence of a remotely created financial object. App links/readable code remain
the durable continuation options. No automatic GET or second creation retrieves an
evicted QR. Multi-node deployments should already use shared Redis; file fallback
can miss on another node and relies on normal cache housekeeping for expired files.
Legacy QR columns remain readable without copying them to a new store.

QR preservation follow-up: full V2 checkout and persistence suites passed **92 tests /
1,377 assertions** with isolated SQLite and mocked providers. Coverage includes
one-time service/storage/add-on creation, recurring service/storage QR rendering in
EN/AR/KU, cache eviction with durable fallback presentation and reuse of the same
checkout (exactly one provider creation), no QR in Payment/event persistence, exact
36-hour expiry, missing deadlines and non-SQL cache selection. PHP lint/Pint passed
for all three changed PHP files. No live QR scan or provider request was performed.

## Read-only size estimate and acceptance

Using the loopback `metkurd_local_260930` snapshot, 123 Payments with creation data
were projected **in memory only**. The sum of serialized bytes across the targeted
11 JSON/QR/reason fields fell from 2,589,595 to 440,924 bytes: mean
21,054 to 3,585 bytes, approximately **83.0% less**. QR columns alone contributed
970,734 bytes before projection. This is a payload estimate, not InnoDB allocation,
index, event-table or backup-size measurement.
This static projection predates the event-frequency follow-up: later operational GET
receipts/callback counters add a bounded amount, not a copy per status check.

The local reproduction database remains unchanged. New-format regression covers
real checkout actions (one-time/add-on/recurring service/recurring storage), owned
EN/AR/KU presentation, cached QR expiry/non-SQL storage, immutable snapshot/fee
reconstruction, repeated GET/fulfillment, cancellation confirmation, callback
sanitization, 404/review reasons, unknown collection hints, and oversized metadata.
Existing suites cover Admin evidence, financial lifecycle, provenance, and five-table
cutover/preserved-state behavior with a compact Payment added to the cutover fixture.
Native MySQL/RDS acceptance, shared-cache browser acceptance and live provider
acceptance remain separate. No production operation is authorized by these tests.

Initial minimization verification (before the event-frequency follow-up): combined Payments, V2 account/checkout/purchase, recurring
billing, provider obligation/disposition, cutover and Admin evidence/workspace suites
passed **683 tests / 7,427 assertions**. A final targeted run after the creation-host
conflict guard and compact Admin fixture passed **14 tests / 107 assertions** (these
overlap the combined suite). Changed PHP passed lint and Pint (17 files); diff checks
passed. Tests used SQLite `:memory:`, array cache/session and mocked provider calls.
The process-only V1 feature override enabled legacy-route regression coverage without
changing application configuration. All ten protected local-table fingerprints matched
their pre-investigation values after verification. No application migration, live
provider call, production connection, cutover or deployment was performed.

Event-frequency follow-up verification: the combined Payments, V2 account/checkout/
purchase, recurring billing, provider obligation/disposition, cutover and Admin suites
finished with **695 passed and three obsolete event-per-poll assertions failing
(7,574 assertions)**. The source-width fixtures now use a genuine renewal collection;
the unchanged reconciliation fixture checks the advancing receipt/time with zero
transition events. The subsequent final run of the complete characterization,
reconciliation, frequency, persistence, noise-pruning and obligation-evidence files
passed **109 tests / 813 assertions**, including all three corrected cases. These
runs overlap and are not additive; no test failures remain unresolved.

The new frequency regressions cover 100 identical DRAFT checks with advancing check
time, DRAFT → ACTIVE → ACTIVE ×100 → PAID → PAID ×100 (five total events including
creation and fulfillment), one-time UNPAID/PAID loops and duplicate callbacks, new
collections under unchanged ACTIVE, 100 identical failures across time buckets,
callback/GET races, stale/tampered cancellation proof, legacy callback watermarks,
404 without cancellation POST, and retained collection warnings. Changed PHP passed
lint/Pint (32 files, with the five final edited files checked again); diff checks
passed. No frontend assets changed. All execution remained isolated SQLite with
array cache/session and mocked provider calls. No application database connection,
migration, real FIB call, cutover or deployment was performed in this follow-up.
