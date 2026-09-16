# Billing audit — FIB payments, recurring subscriptions, events and credit renewal

## Post-cutover correctness and mocked purchase — 2026-09-15

Read-only forensics found customer 1 selecting retained manual subscription 407, whose
recorded term ended July 2 despite active status and null ends_at. This was a missing
term-eligibility check, not wallet-derived access or stale cache. The shared authority
now resolves Free without rewriting the subscription or carried App/API credits.
Current history now filters both native Payments and detached CreditOrders through the
existing committed audit epoch. Admin exposes Legacy / Pre-V2 History separately.

Interactive local checks confirmed Free across V2 Billing/Plans, Admin Register/Operations
and API eligibility, and continuous old job/usage history. Existing failed V2 attempts
174/175 legitimately remain. Mocked normal creation/status/fulfillment for synthetic
customer 1083 produced Payment 176, Pro, App 250,000/API 300,000, no duplicate replay;
local paid orders 0→1/revenue 0→24,000 IQD. Every pre-existing protected row matched its
fingerprint, and customer 1 remains Free. No FIB/network or notifications were sent.
This synthetic receipt is not real revenue or production acceptance.

Production remains blocked pending native MySQL rehearsal and actual provider disposition,
release artifact, process managers, scheduler/workers and callbacks. See the complete
[runbook, command inventory and table classification](V1-TO-V2-PRODUCTION-RUNBOOK.md).

The subsequent deployment-identity refactor adds explicit disabled-by-default local
and production target policies around the same cutover algorithm. Production dry-run
reports migration, Admin, backup and provider blockers without mutation. Review hashes
bind the selected target and actual server identity; production has a distinct phrase
and backup/restore attestations. No cutover, FIB call or production connection occurred.


## Local payment-domain cutover preparation — 2026-09-15

The explicit local production-copy business cutover now has a separate command and
an atomic current-reporting boundary. Conservative cleanup and checkout policy are
unchanged. No historical revenue classification is repaired, no credits are reset,
and no provider/storage calls occur. See [BILLING-DOMAIN-CUTOVER.md](BILLING-DOMAIN-CUTOVER.md).
The full rehearsal is not accepted until reviewed execution and subsequent synthetic
lifecycle checks pass; source and isolated tests alone do not establish that result.


## Recurring action lifecycle — 2026-09-14

Implemented durable cancellation intent before HTTP, requested versus confirmed
provider state, retry of expired/superseded remote subscriptions, and separate paid
access. New plan fulfillment precedes old remote cancellation; failures remain
visible without undoing the new paid plan. No collection timestamp advancement means
no new coverage. Late collections enter review. V2 and Admin reads expose both states.
See [RECURRING-SUBSCRIPTION-LIFECYCLE.md](RECURRING-SUBSCRIPTION-LIFECYCLE.md) for the
provider contract, recovery and verification boundary. No historical data repair,
application mutation or Payment History Reset change is part of this implementation.


## Effective plan and Pro API follow-up — 2026-09-14

Current service-plan reads now share CustomerBillingStateService and the normalized
subscription eligibility scope. Partial relations, synthetic customer plan IDs and
stale model caches cannot override it. Agreement snapshots supply monthly allowance
presentation; scheduled/review agreements remain supplementary. Scoped shell cache
invalidation follows committed subscription/wallet changes and effective-plan changes.

The operator explicitly approved all six V2 API service scopes for the existing local
Pro plan. They were saved through the authenticated Admin scope-only action, preserving
legacy scopes and all prices/allowances. This is an audited local configuration change,
not a migration or automatic scope backfill. See [EFFECTIVE-PLAN-CONSISTENCY.md](EFFECTIVE-PLAN-CONSISTENCY.md)
for evidence, preserved-data fingerprints and verification limits.


## Dated cash / external agreements — 2026-09-13

Commercial access can now be recorded by an authorized Admin for explicit dates and
monthly allowance snapshots. Collection schedules stay external. The optional amount
is not a receipt; no Payment/CreditOrder/revenue evidence is invented. An additive
agreement schedule activates the existing subscription architecture and uses existing
allocation/ledger records, with no rollover or stacking of missed months. Online
checkout and payment review remain protected. See [SERVICE-AGREEMENTS.md](SERVICE-AGREEMENTS.md).
Application migration and native MySQL/scheduler/browser acceptance are separate.


## Historical checkout status follow-up — 2026-09-13

The local blocked Payment remains unresolved; cutover inventory did not reset it.
Its origin environment is unrecorded, while the active runtime is staging. A stored
permanent sync-failure pause now makes the V2 customer status button refresh only
the local review decision, avoiding repeated provider lookup of this old reference.
The existing Admin closure remains the explicit resolution; checkout, financial
and cleanup policies are unchanged. See [PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md).

## Operator closure of abandoned checkout — 2026-09-13

The existing Admin review invalidation now exposes a local-only abandoned-checkout
action with both financial/reconcile permissions, a reason-bound durable intent,
locked eligibility checks, retained provider/history/audit evidence and unused
coupon release. Paid/active/refund evidence and retained fulfillment links block it.
No change to PaymentCheckoutState or automatic review expiry; no application data
was modified. See [PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md) for the operator
flow and verification scope. Paid reconciliation remains a separate action.


## Checkout lifetime correction — 2026-09-10

The initial V2 pending-row guard ignored checkout expiry. PaymentCheckoutState now
distinguishes actionable, safely closed and review/unknown evidence; a known-expired
unpaid session no longer blocks new purchase. Review is never cleared because of
age. The currently linked historical local Payment is explicitly marked for review
and has an unparseable provider deadline; it remains blocked without a provider call.

Final guards cover all three Create* actions, including V1 callers. Known closure
uses customer/Payment locks, existing transitions, keyed events and coupon release;
late paid evidence is retained for review without fulfillment. V2 status/history
links stay in V2 and use safe projections. Timestamp conversion prevents premature
expiry in new creation/status responses. No economics or bulk historical repair.
See [PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md) for rules and acceptance limits.

## V2 purchase integration — 2026-09-10

V2 subscription, storage and add-on pages delegate to existing payment/cancellation
actions, shared FIB checkout and unchanged Phase 1/2 evidence/fulfillment/allocation
protections. PlanSwitcher resets subscription credits and retains add-ons; the new
preview shares that calculation. No prices, history or customer balances changed.

Two storage cases remain blocked in V2: replacing an unretired remote recurring
subscription (no automatic remote supersession), and non-monthly one-time purchase
(fulfillment omits billing_cycle and falls back to monthly). Same-plan interval
changes remain unsupported by current Create actions. These are existing domain
limits. See [PURCHASE-V2.md](PURCHASE-V2.md) for entry guards, browser evidence and
regressions, including the three unchanged baseline UI assertion failures.

## V2 account presentation — 2026-09-10

The customer V2 Billing page reuses the existing usage queries and normalized
subscription state service. It shows App/API wallets and their subscription/add-on
buckets separately. Usage and charts share server-side date/tool/status/search
filters; payment and ledger history use the selected dates and separate server
paginators. Native Payments and eligible unlinked CreditOrders are customer-scoped,
with safe display fields and the existing amount/currency semantics. Complimentary
orders are excluded, and internal plan access is not presented as a scheduled
payment. An elapsed recorded term is labelled for review without repairing it.

Rendering does not invoke reconciliation, refill, expiry, provider calls or
financial writes. Explicit cancellation delegates to the existing domain action
and retains its evidence/locking rules. No prices, balances, historical records,
schema or rollout gates were changed by this UI task. Shared plan selection and
checkout remained in place at this account-only stage; V2 selection is now provided
by the purchase integration above. See [ACCOUNT-V2.md](ACCOUNT-V2.md) for verification scope.

Date: 2026-09-09. Audit baseline HEAD: `ecf4dd9`, including the existing uncommitted Admin follow-up. **The operator approved this baseline, then separately authorized Phases 1 and 2. Phase 3 remains deferred.**

## Production payment cutover planning — 2026-09-09

Subsequent operator-authorized follow-up: **`billing:cutover-inventory` is implemented**, with optional `--details`, identity-first output, direct SELECT-only inventory, MySQL/MariaDB read-only snapshot and a conservative unknown-blocks verdict. It does not authorize deletion, contact a provider or invoke reconciliation. Local execution against the configured MariaDB copy returned BLOCKED; production remains unverified. See the [command contract and evidence limits](BILLING-CUTOVER.md#read-only-artisan-inventory--implemented-2026-09-09). The destructive command remains unimplemented and `billing:reset-legacy-payment-history` is unchanged.

The operator separately requested read-only production verification before a potential payment-domain reset, preserving customer accounts, credits and product history. Production identity/access and actual counts are not available here; zero paid subscribers remains operator-reported, not verified. **Eligibility is BLOCKED.** The [conditional cutover plan](BILLING-CUTOVER.md) and [initial read-only SQL inventory](BILLING-CUTOVER-INVENTORY.sql) describe evidence collection, effective-obligation classification, retained CreditOrder/ledger provenance, explicit subscription normalization, archive and future execution guards. The SQL is not an eligibility verdict and has not run against production.

No destructive cutover command was implemented or executed, and the existing conservative local cleanup command was not changed. No production/provider contact, data mutation or gate change occurred. Old unmatched FIB callbacks can still create orphan events; deleting local history cannot establish cancellation of remote objects or promise permanent zero event counts. The plan keeps this boundary separate from the verified Phase 1/2 source contracts.

## Focused legacy cleanup / complimentary grant follow-up — 2026-09-09

Phase 3 remains deferred. The [local inventory and guarded cleanup runbook](LEGACY-PAYMENT-CLEANUP.md) records 151 Payments, 38,872 events, 34 CreditOrders, active non-Free/FIB subscription rows, unresolved payments and retained purchase-customer balances. The operator's zero-production-subscriber statement was not independently verified against production. The local dry run has zero eligible deletion IDs and blockers; no destructive cleanup, provider call, seeder, historical repair or balance mutation ran.

`billing:reset-legacy-payment-history` is restricted to the exact local target (or isolated in-memory tests), defaults to dry-run, inventories real FKs, retains financial/soft-link evidence conservatively, and requires an unblocked unchanged review, active financial/reconcile operator, explicit confirmation and maintenance/stopped workers before transactional execution. It retains purchases, CreditOrders, subscriptions and allocations; it cannot implement a full reset while those dependencies exist. No override was added.

The existing Admin grant path already avoided Payment/CreditOrder creation. This follow-up corrects complimentary access being counted as paid subscribers, preserves prior subscription financial classification on supersession, and makes new grants' non-revenue classification, reason, exact local expiry and separate credit previews explicit. Existing allowance policy, P0 intent identity and provider-free manual renewal remain. See the runbook for scope and verification.

## Phase 2 local post-migration acceptance — 2026-09-09

The operator applied the allocation migration manually. Read-only verification found **75 migrations Ran, zero pending**, and an empty InnoDB/utf8mb4 allocation table with both required unique indexes and all three foreign keys. Direct `SELECT VERSION()` through the local connection returned **10.4.28-MariaDB**; Laravel's connection/driver is `mysql`. This is acceptance of the existing local application schema, **not native MySQL or Amazon RDS acceptance**.

- Established baseline counts match: 1,045 customers, 1,045 App wallets, 1,045 API wallets, 6,499 ledgers, 1,078 service subscriptions, 151 Payments, 34 CreditOrders, 2,187 MlJobs and 2,464 CustomerFiles. Both saved pre-V2-migration wallet and ledger fingerprints match exactly.
- **10 local cases passed, 102 assertions**, using clearly synthetic customers and rollback-only outer transactions on the installed application schema. No seeders, DDL, committed fixture records or real provider requests ran against that database. Existing catalog prices were not changed; the zero-API case used a temporary synthetic plan copy that was rolled back.
- Native local execution covered paid monthly calendar denial; mocked verified GET collection with complete applied claim fields; separate App/API allowances, lifetime accounting, ledger deltas and retained add-ons; replay after spending through callback-labelled sync and scheduler sync; injected API-ledger failure rolling back both wallets/claim/lifecycle followed by successful retry; annual prepaid slices and expiry; superseded-plan exclusion; cancellation retention and one explicit Free row at the boundary; provider failure before/after known expiry; and zero API allowance. Actual callback HTTP routing is separately covered by the isolated regression suite.
- Both duplicate cycle constraints rejected conflicting inserts with server error 1062, including the same Payment/cycle bound through a different subscription. Nullable Payment keys worked per subscription. A second connection contending only on an uncommitted synthetic customer hit the intentionally bounded one-second lock timeout (1205); rollback/retry and subsequent renewal replay produced one allocation. This is a bounded two-connection lock check, **not parallel financial-worker or plan-switch/deadlock acceptance on native MySQL**.
- After fixture rollback, all captured full-row fingerprints and counts matched the immediately preceding acceptance snapshot: customers, wallets, ledgers, service subscriptions, CreditOrders, Payments, MlJobs, CustomerFiles, allocations, monthly grants, payment events, service plans and pricing rules. Allocation rows returned to zero. Feature gates and persistent configuration were unchanged. Historical Payment/subscription full-row fingerprints were not saved before the operator's migration; the earlier baseline proves their counts, while the additive migration contains no historical DML and the new full-row comparison proves this acceptance did not rewrite them.
- Current-source regression run: **347 passed, 3 known UI assertion failures, 2,169 assertions** across Payments, Billing, timestamp unit tests and four Admin safety/billing suites. All 21 focused Phase 2 cases passed within that run. The unchanged UI failures are English redirect copy on Arabic checkout, legacy API component balance, and API resource visibility. Syntax and focused Pint passed for 23 PHP files.

**PASS — Billing Phase 2 is locally accepted.** No Phase 2 correction was needed. Native MySQL/RDS parallel acceptance and real-provider acceptance remain separate; existing Phase 3/B15 risks below remain open. No Phase 3 work was started.

## Phase 2 implementation — 2026-09-09

This is the current source status; the dated Phase 1 section and original audit below remain historical evidence. No application database, customer financial data, provider contract, prices or feature gates were changed during implementation.

| Finding | Current source status |
|---|---|
| B01/B02 — paid calendar refill / independent double allowance | Resolved in source. Paid monthly provider subscriptions require a matched, verified collection timestamp and paid-through boundary. The calendar command cannot renew them. Free remains calendar-based; explicit local/manual grants retain their term policy. Annual prepaid terms use at most one allowance per monthly slice within their verified term, without another provider charge. |
| B07 — superseded subscriptions | Resolved in automatic lifecycle/allocation paths. Under the customer lock, a locking read rejects any subscription with a newer normalized row or a supersession marker. An old Payment may retain observations, but cannot reactivate that subscription, change the current subscription's dates/cancellation or overwrite its wallets. |
| B08 — durable identity and monotonic history | Resolved in source. A dedicated allocation table retains every applied cycle, with unique subscription/cycle and Payment/cycle identities. Normal status sync keeps last-payment time and paid coverage monotonic even through terminal observations. Terminal provider state is distinct from immutable paid financial history. |
| B09 — cancellation precedence | Resolved in source. Explicit customer/operator/provider cancellation survives later ACTIVE observations. Paid access is retained to the known boundary, renewal remains off, and Payment stays paid. The operator's force-current cancellation follows the same boundary rule; genuine supersession still ends the older local row. |
| B10 — missed allocation retry | Resolved in source. Observed provider time, verified collection and applied allocation are separate. A disabled plan does not consume the financial cycle. A wallet/ledger/claim failure rolls back the complete financial transaction; the same verified cycle can subsequently apply once. |
| B12 — missing metadata / expiry / Free | Resolved for prospective automatic behavior. `ExpireSubscription` is shared by provider lifecycle, scheduled local expiry and current-subscription operator cancellation. At a known elapsed boundary it ends the current paid row and creates one explicit Free row, retaining history and existing wallet/add-on balances; Free is eligible for its normal calendar refill. No fabricated provider period, bulk historical downgrade or historical receipt backfill. Unknown historical boundaries remain flagged for review and cannot generate new paid refills. |
| B13 — renewal / plan-switch concurrency | Source protections implemented: customer → Payment (when applicable) → current subscription → App wallet → API wallet, with locked balance reads, transaction retries and durable unique claims. Native MySQL parallel execution remains an acceptance prerequisite; SQLite regression coverage is not proof of MySQL row-lock behavior. |

### Allocation schema and atomicity

One additive migration, `2026_09_09_000001_create_subscription_credit_allocations_table.php`, creates `subscription_credit_allocations`. A row records customer, normalized service subscription, optional Payment, cycle key, allocation type, cycle start, paid-through evidence and applied status/time. Foreign keys restrict deletion of referenced history. Unique `(subscription_id, cycle_key)` protects local calendar/manual identity; unique `(payment_id, cycle_key)` also prevents replay through a second subscription row attached to the same provider Payment. Nullable Payment keys support explicit non-provider grants. These are financial allocation identities, not `payment_events` identities or generic ledger reference uniqueness.

Provider cycle keys retain the existing `fib:<subscription-id>:<UTC payment time>` contract. The initial fulfilled provider allocation claims that same cycle; later annual slices append `:month:N` (1–11). Annual one-time purchases use Payment/month-slice keys. Free/manual calendar allocations use a calendar-month key. No synthetic rows are inserted for historical cycles. Existing applied/initial timestamps remain a conservative historical lower bound; uncertain historical missed allocations require review rather than a fabricated receipt.

Claim creation, locked eligibility checks, both subscription-bucket resets, retained add-ons, lifetime counters, ledger deltas and applied marker commit together. A failed attempt leaves no committed partial claim or financial changes. Zero-delta renewal still records its durable claim. The old calendar duplicate-constraint catch after wallet writes was removed: a grant failure now rolls back the wallet changes too. Existing initial plan-switch allowance policy is retained.

The customer lock serializes renewal against plan switching and Admin P0 financial intent operations. Authoritative subscription and claim lookups use locking reads, including after waiting for the customer lock, rather than trusting an earlier MySQL repeatable-read snapshot. Wallet spending still uses the existing wallet locks; plan switching reloads those locked balances before calculating its reset. No provider worker or scheduler cadence was changed.

### Evidence, cancellation and retained access

`SyncFibCheckoutStatus` stores a small matched GET collection descriptor in `meta.verified_subscription_collection`, separate from the latest status response. Later incomplete observations cannot erase that already verified annual receipt. A coverage-only ACTIVE observation with no collection timestamp cannot extend an already fulfilled paid term. New recurring allocations require the matching Payment, plan/customer binding, collection time and paid-through evidence. Replayed/older cycles cannot restore spent credits or increase lifetime counters again.

`SubscriptionCyclePolicy` supplies the shared boundary used by cancellation, lifecycle, expiry and billing-state display. Provider dates are never synthesized from `starts_at` or an anniversary when evidence is missing. Existing non-provider purchased/manual terms retain their persisted boundaries. Cancellation metadata is preserved. An elapsed known boundary triggers `ExpireSubscription`; a future boundary retains paid service access. Expiry creates no sale, charge, refund or wallet repair, and never rewrites Payment as unpaid/canceled. Add-on balances, subscriptions, orders and ledger history remain retained.

### Migration and verification boundary

At the implementation checkpoint, the additive migration was **source only**, exercised in isolated SQLite fixtures. The operator subsequently applied it locally; see the post-migration acceptance above. Native MySQL/RDS migration acceptance remains separate. No seeder or historical backfill is required. Rollback refuses to drop a populated allocation table because that would discard financial replay protection.

Regression tests cover authenticated callback/scheduler/restart replay after spending, initial/renewal identity, paid calendar denial, annual allocation and overlap, failed ledger/calendar writes with retry, stale/superseded subscriptions, cancellation/Free boundaries, unknown evidence, separate App/API buckets and zero API allowance. All provider calls are mocked, notifications/storage faked, and fixture databases isolated. **No actual concurrent MySQL workers, RDS, provider cancellation/collection, or production acceptance were executed.**

Implementation-checkpoint verification (superseded by the local acceptance above where stated):

- Expanded Phase 2 suite (`RecurringFinancialCycleTest`): **21 passed, 162 assertions**. This includes two independent PHP processes released at a shared barrier against a temporary copy of the in-memory fixtures: duplicate collection claims produce one applied allocation, and plan-switch/old-renewal contention leaves the new plan's correct App/API allowances. SQLite writer-contention retries are test-harness accommodation, not a claim of native MySQL locking behavior. The temporary fixture copy is removed afterward.
- Phase 2 cases plus provider repair, Admin P0 safety and Admin billing controls: **93 passed, 519 assertions** (before adding the two new process-race cases). Existing Admin P0 concurrent-intent coverage remains passing.
- Complete Payments/Billing, timestamp/source unit tests and four Admin safety/billing suites: **345 passed, 3 failed, 2,145 assertions**. The two added process-race cases were then checked in the expanded Phase 2 suite above; these overlapping counts must not be added together.
- The same three previously documented UI assertions remain: English redirect copy on an Arabic checkout page (`FibPaymentFlowTest`), legacy API component balance, and API resource visibility (`SeparateCreditWalletArchitectureTest`). No full-suite-green claim; no new recurring-financial regression failed.
- Syntax and focused Pint passed for **23 affected PHP files**. Scoped diff whitespace checks passed. No frontend assets changed.
- Existing positive cancellation fixtures now supply persisted paid-through evidence; calendar wallet tests explicitly identify a manual grant. The ordinary successful-checkout fixture date is frozen within its documented paid term. These changes remove reliance on guessed boundaries or unpaid calendar renewal.
- At this implementation checkpoint, native MySQL/RDS migrations, parallel row-lock acceptance, real provider requests and application database changes were **unexecuted**. The later operator-applied local migration and rollback-only acceptance are recorded above.

### Remaining billing risks / next phase

B11 event volume, B16 external effects inside database transactions, B17 remaining payload/exception retention, B18 pause handling and B19 organization remain open. Phase 3 event pruning, coalescing, cadence changes, retention rewrites and legacy removal were not started.

B15 remains **open/report-only**. The approved audit found no established FIB provider-create idempotency header/field. Fresh local UUIDs and generic HTTP POST retries do not establish exactly-once provider object creation. After an ambiguous create, operators must inspect the existing local/provider object and reconcile it before initiating a new purchase; a fresh checkout is not a safe blind retry. A durable creation-intent/recovery flow and provider-supported deduplication require a separate reviewed design. No header was invented and no provider-create behavior was changed here.

## Phase 1 implementation — 2026-09-09

This section supersedes the affected pre-fix descriptions below. The original audit remains evidence of the baseline; it is not a claim that resolved defects still exist. At the Phase 1 checkpoint, Phases 2/3 had not been implemented; see the newer Phase 2 status above.

| Finding | Current source status |
|---|---|
| B03 — callback-paid activation | Resolved: current and stored callback flags/timestamps cannot prove payment. Only the authenticated GET DTO reaches the mapper. Forged/replayed callback tests assert no order, subscription activation or App/API grant; a verified GET still fulfills once. |
| B04 — legacy unsigned/cross-provider webhook | Resolved at the compatibility boundary: disabled provider, missing secret or invalid authentication returns 403 before intent processing. Lookups are provider-scoped and locked; conflicting stored references and returned money are rejected. Configured authenticated replay remains idempotent. No historical records/routes removed. |
| B05 — subscription dates | Resolved in source: explicit integer/string Unix milliseconds, strict ISO/SQL date strings, and null. Invalid dates, relative strings, seconds disguised as milliseconds and unsupported ranges are rejected. The supported billing era is 2000-01-01 UTC through the existing MySQL TIMESTAMP limit (2038-01-19 03:14:07 UTC). GET observations containing a supplied invalid date cannot apply financial state. |
| B06 — oversized event source | Resolved without migration: `scheduled_sub_checkout`, `scheduled_sub_renewal`, `scheduled_sub_checkout_expiry`, `scheduled_payment_expiry`. Recorder rejects future overlength sources explicitly; source inventory and simulated strict-width persistence tests cover the current paths. Stored event labels remain untouched. |
| B14 — provider response identity/money | Resolved for automatic status application: response IDs/aliases must match the locked Payment's stored provider object. Returned monetary fields and merchant reference must match the local checkout; omitted optional fields are not fabricated from DTO defaults. Rejections commit only safe reason-coded review evidence and bypass fulfillment/lifecycle. Admin verification reuses these checks while retaining its stricter mandatory-money and customer/reference-correction proof. Cancellation prechecks and the manual reconciliation entry also reject mismatches. |
| B08 — regressing observations | Limited Phase 1 protection: nonterminal observations with older last-payment time or shorter verified coverage are rejected before lifecycle. Missing dates do not erase existing evidence; terminal transitions may shorten coverage but cannot regress last-payment time in normal sync. The latest-key-only allocation design and direct lifecycle replay characterization remain open for Phase 2. |
| B17 — sensitive callback storage | Partially reduced: new FIB callback receipts contain bounded identifiers/status only; legacy receipts no longer store request headers, banking extras or the unrestricted body. Existing provider status/exception retention and historical records remain a Phase 3 review. |

`FibSubscriptionTimestamp` normalizes the instant in UTC. The status DTO represents that same instant in the configured application zone before Eloquent writes timezone-less columns, preventing the former three-hour read-back shift in Baghdad. The existing database fields retain second precision; the parser preserves fractional milliseconds in memory. No historical timestamp conversion runs.

FIB callback trust is explicitly **untrusted notification → matched local object → authenticated GET → validated evidence → existing fulfillment**. The reviewed public FIB docs do not establish a cryptographic webhook signature or delivery of the optional local secret header. That header remains an operator delivery filter, never payment proof. Callback routes have a 300/minute per-IP throttle; FIB identifiers/aliases, status types and a 16 KiB body bound are checked before queued work. Actual production reachability, header support, worker delivery and throughput remain operator acceptance checks. [FIB subscription contract](https://documenter.getpostman.com/view/30814842/2sB3BHn9i6), [online payment contract](https://documenter.getpostman.com/view/30814842/2sB2j68V73#intro).

The Areeba compatibility path requires the existing configured shared-secret delivery agreement. This is not a newly invented provider signature or proof that Areeba can currently send that header. Without established/configured authentication, financial processing fails closed. Positive tests use synthetic configured delivery; no live Areeba acceptance is claimed.

Rejected FIB identity/money/date evidence sets `review_required_at` and a safe reason code; unfulfilled Payments enter the existing review state. Repeated rejection is keyed without storing the rejected provider body. Stale observations create safe diagnostics without permanently pausing reconciliation. Same-Payment fulfillment retry without another GET now requires validated stored GET evidence, not merely a local paid flag or stored callback. If that evidence is absent, fresh GET is required; an otherwise unverified ACTIVE response leaves an unapplied paid row unfulfilled for review. Existing model evidence/display helpers also exclude callback-paid flags. No historical reclassification/backfill runs.

**Still open:** B01/B02 paid calendar allocation and duplicate calendar/provider allowance; B07 superseded subscription protection; the remaining B08 durable cycle identity; B09 cancellation-intent precedence; B10 retryable missed allocation; B12 missing-period/expiry/Free behavior; B13 customer/wallet concurrency; B15 provider-create idempotency. B11/B16/B17/B18/B19 operational noise, external effects, retention, pause handling and organization also remain. Do not treat Phase 1 as recurring-billing release acceptance.

Verification uses only isolated SQLite, fake notifications/storage and mocked provider responses. The relevant audit characterizations were converted to safe regression expectations; explicitly named deferred characterizations remain intentionally unsafe-baseline proofs. Existing lifecycle fixtures no longer invent one fixed amount for every product; strict money cases explicitly supply matching/mismatched values. Time assertions now compare actual UTC instants rather than a shifted wall clock. Admin repair fixtures obtain verified GET evidence before offering a provider-paid action; callback claims alone no longer qualify.

Final Phase 1 verification:

- Focused timestamp/source, audit regression, legacy webhook, Admin P0 safety and Admin billing controls: **133 passed, 813 assertions**.
- Complete Payments/Billing suites plus the timestamp/source unit tests and four Admin safety/billing suites: **326 passed, 3 failed, 2,015 assertions**. The suites overlap; do not add their counts.
- The three failures are unchanged baseline UI assertions: English redirect copy expected on an Arabic page (`FibPaymentFlowTest`), legacy API component balance, and API resource visibility (`SeparateCreditWalletArchitectureTest`). No new financial/security regression failed. No full-suite-green claim.
- PHP syntax and Pint passed for **30 affected PHP files**; scoped diff whitespace checks passed. No frontend assets changed.
- **Native MySQL/RDS, real FIB/Areeba, public callback delivery and distributed race acceptance remain unexecuted.** The source-width persistence test emulates a strict width constraint in SQLite; it is not MySQL acceptance.

The new shared helpers are `app/Domain/Payments/Support/{FibSubscriptionTimestamp,FibStatusEvidence,FibCallbackNotification}.php`. Application boundaries are in `SyncFibCheckoutStatus`, subscription DTO/mapper, Payment evidence helpers, callback controllers/validators, `PaymentWebhookService`, the configured legacy adapter and existing Admin/provider prechecks. Scheduler command changes are source-label corrections; `routes/console.php`, monthly refill, wallet/plan-switch and lifecycle allocation implementations are unchanged.

No application/local financial database operations, provider requests, migrations, historical cleanup, prices, feature gates, scheduler cadence or credit-refill architecture changed in Phase 1.

Baseline audit evidence: source, migrations, existing tests, 12 isolated characterization tests, and the two operator-designated FIB documentation pages. No application database queries/writes, provider API requests, migration commands, real grants, cancellations, reconciliation, scheduler changes or feature-gate changes were performed in that audit. Fixture writes and fixture seeders ran only in SQLite `:memory:` with fake notifications/storage and mocked provider behavior. Public documentation browsing is not provider API acceptance.

## Executive summary

The three reported symptoms have concrete source explanations, but this audit does **not** establish which particular production payment failed or whether FIB actually collected it.

1. **Credits can renew without collection.** `RefillMonthlyCredits` applies to every eligible local active plan, including paid/provider-scheduled plans. It checks an anniversary date and customer/month grant, not a new provider payment. Independently, `SyncProviderSubscriptionLifecycle` can refill the same wallets from a changed provider payment timestamp. These paths do not share grant identity.
2. **Provider dates are parsed incorrectly.** FIB documents `activeUntil` in milliseconds. `FibSubscriptionStatusData::nullableCarbon()` uses `Carbon::parse((string) $value)`. The documented `1755588900190` becomes a date in **8900**, not August 2025, in the installed runtime. Other numeric inputs can be discarded or misread. This can break persistence or produce a wrong entitlement boundary. Existing FIB fixtures primarily use ISO strings and conceal the mismatch.
3. **Scheduled subscription events exceed their schema.** `scheduled_subscription_renewal_reconciliation` is **45 characters** and `scheduled_subscription_checkout_reconciliation` is **46**; `payment_events.source` is `VARCHAR(40)`. Successful sync and failure recording both use these values. With the declared strict MySQL connection, this is a source/schema incompatibility; native execution was not performed. A rejected event insert rolls back the accompanying status update and may prevent lifecycle/expiry processing.
4. **Cancellation is implemented, but fragile.** CANCELLED is recognized; the original Payment remains paid while subscription access ends separately. Wrong dates, failed sync, missing-period handling, and stale ACTIVE responses can prevent the intended downgrade. A stale ACTIVE response demonstrably clears a locally scheduled cancellation. Retaining paid access before a valid paid-through boundary is intended behavior, not a defect.
5. **Additional financial/security defects need priority review.** Optional-secret FIB callbacks can supply a paid claim used for activation despite no charge evidence in the authenticated GET response. The separate Areeba webhook can fulfill an unsigned historical intent even with that provider disabled, and does not scope lookup to its provider. Superseded subscriptions can be reactivated and refill the wrong allowance; out-of-order provider observations can replay an older grant.

Recommendation: **do not treat recurring billing as production-ready on the strength of checkout success or the existing green lifecycle tests.** Preserve the working one-time fulfillment structure; correct evidence validation, grant ownership and lifecycle persistence in small reviewed phases.

## Billing architecture

### Current one-time and initial recurring checkout

```text
Authenticated shared App account/checkout Livewire pages
  -> CreatePlanSubscriptionPayment / CreateStorageSubscriptionPayment / CreateAddonPayment
  -> server catalog + payment-method authorization + coupon/fee/currency snapshot
  -> payments row + local_payment_created event
  -> one-time: FibOneTimePaymentService -> FibOneTimePaymentClient -> POST payments
     recurring: FibSubscriptionService -> FibSubscriptionClient -> POST subscriptions
  -> save provider object ID, response, QR/app links, expiry and interval

FIB callback -> dedicated controller -> PaymentEvent -> ProcessFibPaymentStatus queue job
Browser status/manual refresh or scheduler -----------------------------+
                                                                      v
ConfirmFibPayment -> SyncFibCheckoutStatus -> authenticated provider GET
  -> lock Payment -> persist provider fields/local status + status event
  -> PaymentConfirmed -> RunPaymentFulfillment
  -> purchase-specific Fulfill* action, locked Payment/fulfilled_at guard
  -> PlanSwitcher or AddonPurchaseService
  -> CreditOrder + normalized service/storage subscription + wallet/ledger changes
  -> fulfilled_at / internal_status=applied + coupon consumption + notifications
```

One-time purchase mode can buy a service/storage term; it is not limited to add-ons. Add-ons remain App-wallet purchases. Both V1/V2 account surfaces reuse this billing domain. Checkout mode chooses provider object; purchase type chooses fulfillment.

### Current recurring and independent calendar flow

```text
FIB subscription automatically schedules collection at its configured interval
  -> callback or subscriptions:reconcile
  -> GET subscription -> same local Payment (no new local payment per collection)
  -> SyncProviderSubscriptionLifecycle
  -> subscription dates/status/auto_renew + provider_last_payment_at metadata
  -> if last_payment_at is newer than last observed subscription timestamp:
       CreditService::applyProviderRenewalCycle
       -> reset App/API subscription buckets, retain add-ons
       -> last_applied_renewal_cycle_key + delta ledgers + keyed renewal event

Separately, daily credits:refill-monthly
  -> latest locally active subscription per customer
  -> anniversary reached + no customer/year-month grant
  -> reset both subscription buckets + CreditMonthlyGrant + two ledgers
  -> no provider/payment verification
```

Provider synchronization and lifecycle application are separate transactions. Status persistence can succeed while lifecycle application fails. Lifecycle writes subscription metadata before deciding whether credit application actually succeeded. There is no per-collection `PaymentTransaction` or new `CreditOrder` in this renewal path; those tables must not be mistaken for a complete FIB collection history.

Relevant entry points: `resources/views/app/pages/{subscription-plan,storage-plan,addon-credits,payments}/⚡*.blade.php`; `FibPaymentController`; callback controllers; `routes/web.php`; `routes/console.php`. Customer ownership is enforced by authenticated routes/component checks and `PaymentPolicy`. Admin correction entry points use `AdminOperationRunner`, `AdminFinancialCorrections`, `AdminPaymentReconciliation` and `AdminProviderEvidence`.

## payment_events growth

**Confirmed, P2:** `SyncFibCheckoutStatus.php:116,158,226,264` records `provider_status_checked` or `provider_status_ignored` without `event_key`, including unchanged states. `PaymentEventRecorder.php:35–43` uses `firstOrCreate` only for a supplied key; otherwise every call creates a row. A nullable unique key does not deduplicate null-key observations.

The log-level audit helper suppresses unchanged log messages, but the preceding database event insert is unconditional. Thus quieter application logs do not imply quieter `payment_events`.

### Insertion-path inventory

All current direct PaymentEvent creation is centralized in `PaymentEventRecorder`. Callers below also identify indirect insert paths. Paths are relative to the repository.

| Caller(s) | Events / identity |
|---|---|
| `app/Domain/Payments/Actions/CreatePlanSubscriptionPayment.php`, `CreateStorageSubscriptionPayment.php`, `CreateAddonPayment.php` | Local creation unkeyed; provider-created keyed by local Payment ID; provider-create failure unkeyed. |
| `app/Domain/Payments/Actions/SyncFibCheckoutStatus.php` | Status checked/ignored unkeyed; called by browser, callback job, scheduler and Admin/operator reconciliation. |
| `app/Http/Controllers/Payments/FibCallbackController.php`, `FibSubscriptionCallbackController.php` | Received/orphaned keyed by object ID and payload hash; rejected uses payload hash alone, without a provider-object namespace. Raw payload stored. |
| `app/Jobs/Payments/ProcessFibPaymentStatus.php` | Processed/failed/orphaned keyed by suffix, reference and payload hash. Object type omitted from the key. |
| `app/Domain/Payments/Actions/FulfillPlanSubscription.php`, `FulfillStorageSubscription.php`, `FulfillAddonCredits.php` | Fulfilled unkeyed but guarded by the locked Payment fulfillment marker. Plan supersession and provider-cancel result keyed by old/new Payment identities. |
| `app/Domain/Payments/Actions/CancelFibCheckout.php` | Provider cancellation requested/result events without durable event key. |
| `app/Services/Billing/SyncProviderSubscriptionLifecycle.php` | Service/storage renewed, cancellation-at-period-end, ended: keys include Payment, event type and relevant timestamps/status. Notifications require newly created event. |
| `app/Services/Billing/ScheduleServicePlanCancellation.php`, `ScheduleStoragePlanCancellation.php` | Cancellation request keyed by Payment/result/period; failure keyed by Payment. A thrown validation exception rolls back the failure event in that transaction. |
| `app/Services/Payments/PaymentSyncFailureService.php` | Status/renewal failure keyed by Payment/source/error signature/time bucket; existing bucket updated. Review event keyed by Payment/signature. |
| `app/Services/Payments/PaymentApplicationService.php` | Review event keyed by Payment. |
| `app/Console/Commands/ReconcileFibPayments.php`, `ReconcileFibSubscriptions.php` | Local checkout expiry keyed by Payment. |
| `app/Console/Commands/ReconcileSubscriptions.php` | Local entitlement expiry keyed by subscription/end; missing renewal metadata warning once per subscription/day. |
| `app/Console/Commands/ReconcileFibSubscription.php`, `CancelFibSubscription.php` | Operator reconciliation/cancel/failure events with operation-specific formatted keys; manual financial tools, not ordinary reads. |
| `app/Console/Commands/InspectFibPayments.php` | Optional recovery creation records `manual_provider_recovery_created`, keyed by Payment. Despite its name this command has mutation modes. |
| `app/Services/Admin/AdminPaymentReconciliation.php` | Operator reconciliation keyed by durable Admin operation ID. |
| `app/Domain/Payments/Actions/InvalidateAdminReviewPayment.php` | Invalidation keyed by Admin operation ID. |
| `app/Support/Admin/ManagesCustomerRegisterPage.php` | Non-revenue classification keyed by Admin operation ID; other correction methods delegate to guarded services. |
| `app/Services/Payments/ManualRevenueReclassificationService.php` | Reclassification keyed by Payment/reclassification/reversal mode. |

`ReconcileFibSubscriptionRenewals`, the checkout reconciliation commands and `ConfirmFibPayment` also cause these writes through the sync action; they need not call the recorder directly.

Payload-hash deduplication is sensitive to field order and irrelevant payload changes. More importantly, finding an existing callback event does **not** prevent controllers from dispatching another processing job. Identical delivery can therefore produce another provider GET and unkeyed status event even though received/processed event counts stay constant. Callback event identity is not the financial replay guard.

With successful inserts, one continuously eligible subscription produces approximately **144 status events/day, 4,320/30 days** at a ten-minute schedule. One unresolved one-time payment can produce **288/day** at five minutes, bounded by its lifetime/eligibility. The browser checks every five seconds for up to five minutes: roughly **60 observations per pending page session**, plus initial poll/manual refresh; concurrent tabs can add more. These are formulas, not measured production counts. Strict-schema failures described above can stop inserts instead.

Failure signatures exclude changing trace IDs and use six-hour transient / 24-hour permanent buckets (`FibFailureInterpreter`, `PaymentSyncFailureService`). That is already useful noise control. `PrunePaymentEventNoise` is manual, defaults to retaining 50 status-failure samples per group, and is not scheduled. Its protected list does not cover every cancellation/end event; it is not a general financial retention policy. **Do not run it as this audit's remedy.**

Indexes: unique nullable event key; individual Payment/provider/type/source/reference/status/time indexes; composite `(provider,event_type,created_at)`. These support basic filtering, but do not control growth. Measure actual query plans before adding `(payment_id,created_at,id)` or reducing indexes. Preserve durable business and replay evidence; use keyed state transitions/collections for financial audit, last-check counters on the Payment and separately bounded diagnostic logs for unchanged polling. No deletion or retention change was made.

## Recurring payment

FIB owns recurring collection after consent. Its subscription API creates a fixed amount and interval; MetKurd's client exposes create/get/cancel, not a monthly charge operation. MetKurd should not create another provider subscription/payment every month as a workaround. The recurring amount is sent once from the server-side checkout snapshot. Source intervals default to `P1M`/`P1Y`; hourly mode is a guarded testing option and is not established as a documented production interval. [FIB subscription documentation](https://documenter.getpostman.com/view/30814842/2sB3BHn9i6).

Current activation evidence: `FibSubscriptionMapper` accepts explicit paid status, or ACTIVE/SUBSCRIBED plus a payment timestamp/paid flag. `SyncFibCheckoutStatus::resolveSubscriptionStatus` additionally accepts current **or previously stored callback** paid flags. ACTIVE alone with no such evidence does not activate a new checkout; an existing test covers that case. However, the callback exception makes this protection incomplete.

Current renewal evidence: a non-null `payments.last_payment_at` newer than `subscription.meta.provider_last_payment_at`. It is not a new Payment or transaction. Cycle key is `fib:<subscription-id>:<UTC timestamp to seconds>` (`Payment::providerRecurringCycleKey`). `active_until` supplies the end boundary but is not itself proof of a new charge. The lifecycle grant condition does not independently require ACTIVE or confirm the returned amount/identity/current customer plan.

### Every credit allocation path

| Path | Trigger / verification | Durable identity / effect |
|---|---|---|
| `CustomerObserver -> CustomerOnboardingService` | New-customer default provisioning; legitimately no payment for Free. | Customer/month unique grant; initial App/API allocations and ledgers in a transaction. Not a renewal worker. |
| `PlanSwitcher::switchServicePlan` | Called by purchase fulfillment or privileged/manual paths; no independent provider verification inside this service. | Caller Payment/intent/Admin guard. Replaces subscription buckets, keeps add-ons; `CreditMonthlyGrant::updateOrCreate(customer,month)` overwrites that month's grant metadata on plan switches. |
| `RefillMonthlyCredits` | Latest local `status=active`, starts reached, no elapsed `ends_at`, active plan, clamped anniversary day reached, absent customer/month grant. | Unique `(customer_id,year_month)`; no payment, renewal_strategy, auto_renew, active_until or last_payment check. Applies to Free, paid monthly, annual and manual terms. Both wallets reset in one transaction. |
| `SyncProviderSubscriptionLifecycle -> CreditService::applyProviderRenewalCycle` | Newer observed provider timestamp; existing fulfilled provider-subscription Payment. | Locked subscription and **only the latest** applied cycle key in JSON. No monthly-grant row; no durable set of every previously applied cycle. Both wallets reset together; add-ons retained. |
| `AdminFinancialCorrections::plan/credits` | Fresh explicit finance capability and reason; intentional manual grant/synchronization. | Durable Admin operation UUID/payload hash, customer lock, shared transaction, separate App/API references. A newly authorized manual operation is a distinct business action, not a provider cycle. |
| `CreditService::grantMonthlyCredits` | Caller supplies amount; no provider check. | Wallet lock and ledger, no built-in cycle uniqueness. No current production caller found by repository search; do not wire it into renewal as-is. |

`RefillMonthlyCredits::dueAtForMonth` uses subscription start day (fallback customer creation), clamps short months and uses midnight. It does not use the provider's exact collection time. A May 1 10:00 paid term can refill June 1 00:15 before the expected June 1 10:00 collection; the audit reproduces this. After that refill, spending 100 credits followed by a June provider renewal restores those 100 again. This is duplicate available credit, not merely duplicate logging.

Annual prepaid access may legitimately allocate monthly credits without another annual payment, but each allocation must be covered by the paid annual term. The fix must distinguish that from monthly collection and explicit non-revenue/manual terms. Do not solve the problem by forbidding all calendar allocations indiscriminately.

Limited-duration recurring coupons are already rejected by `CouponService::recurringDurationCompatibilityMessage`; FIB's fixed recurring amount cannot automatically return to full price after N cycles. Do not report that guarded case as an unmitigated new-price bug. Historical coupon/provider settings remain unverified.

## Cancellation

Current intended propagation exists:

```text
CANCELLED from authenticated GET
 -> persist provider_subscription_status / active_until / last_payment_at
 -> Payment paid-to-canceled transition is intentionally ignored (past payment stays paid)
 -> lifecycle still runs after that ignored financial transition
 -> auto_renew=false; ends_at=period end
 -> active until future end, otherwise ended
 -> Customer active-subscription query no longer selects it; Free fallback
```

`FibSubscriptionService::isClosedProviderStatus`, mapper, lifecycle `shouldEndNow`, `CustomerBillingStateService::resolveActiveServiceSubscription`, and existing provider-cancellation tests establish this. It is incorrect to fix cancellation by allowing historical paid Payments to become unpaid/canceled indiscriminately.

Confirmed failure mechanisms:

- Millisecond parsing can make the boundary invalid or thousands of years away. On the declared MySQL TIMESTAMP columns, that value is out of range; persistence is a separate native acceptance check. [MySQL timestamp range](https://docs.oracle.com/cd/E17952_01/mysql-8.4-en/date-and-time-type-syntax.html).
- Subscription scheduler event source overflow can roll back status updates, and its error-recording path uses the same oversized source. It can abort the orchestrator before local expiry work. Repository `config/database.php` declares strict mode. [MySQL strict SQL modes](https://docs.oracle.com/cd/E17952_01/mysql-8.4-en/sql-mode.html).
- Lifecycle recomputes auto-renew solely from current provider status. A stale ACTIVE GET clears `canceled_at`, `ends_at` and cancel source, even after a local cancellation request. There is no durable cancellation-intent precedence.
- It selects the latest subscription **for that Payment**, without excluding ended/superseded rows, and overwrites status. An old provider sync can resurrect a superseded plan and reset wallets to its allowance while the effective plan remains the newer plan. This is reproduced.
- Callback is a wake-up for GET; CANCELLED in the payload is not directly applied. If GET is stale/unavailable, local cancellation awaits recovery. That needs coordinated retries, not unconditional trust in the callback.
- A fulfilled Payment with no last-payment metadata may interpret its first later observation as renewal, even if it only rediscovers an old collection. Historical rows need review, not automatic backfill.

Before a valid paid-through boundary, retain paid access and existing purchased add-ons. At the boundary, end the paid entitlement and resolve Free. Keep historical Payment paid, cancellation status and entitlement status separate. Existing code largely expresses this policy. The FIB documentation exposes CANCELLED, `activeUntil` and an accepted cancellation response, but does not fully specify cancellation refunds, final charge races or failed-collection timing. Obtain provider clarification before asserting those details or changing already-paid credit expiry policy. [FIB cancellation contract](https://documenter.getpostman.com/view/30814842/2sB3BHn9i6).

## Failed renewal

`subscriptions:reconcile` runs checkout sync, renewal sync, then local overdue handling. Known elapsed periods can end a service/storage entitlement and create a default Free subscription. The configured local expiry grace defaults to zero minutes. There is no implemented paid-plan past-due/grace business state; `requires_review` belongs to Payment application/operations, not a paid credit entitlement.

`ReconcileSubscriptions::hasMissingRenewalMetadata` deliberately preserves an applied FIB ACTIVE/PAID/SUBSCRIBED entitlement when `active_until` is absent, marks metadata and records a daily warning. Combined with the unconditional calendar refill and null `ends_at`, this can keep paid allocation continuing without a bounded paid period. If a trustworthy end exists, local expiry can downgrade even while provider status remains ACTIVE. The effective-plan resolver itself filters `status`, `starts_at`, `ends_at`, not `active_until`; automatic active subscriptions therefore depend on successful reconciliation to populate/end their term.

Closed/UNPAID-like provider states with future periods retain access until the boundary. At/past the boundary lifecycle can directly set `ended`. The local expiry command selects `status=active`, so a row already ended by lifecycle can be skipped for explicit Free-row creation: the effective-plan fallback is Free, but the calendar worker may then have no active Free subscription to refill. Tests cover effective fallback and local-expiry Free creation separately; their composition needs coverage.

Transient provider failure is not proof that collection failed. Conversely, a stale ACTIVE state is not evidence a new term was paid. Keep these unknowns distinct. Renewal failures write a pause flag after repeated permanent errors, but `canScheduledRenewalPoll` and the renewal query do not consume that flag; persistent errors can keep being polled. Do not confuse checkout-review stopping behavior with renewal stopping behavior.

A proven missed-grant path also exists: lifecycle saves the new **observed** timestamp before `applyProviderRenewalCycle`; if the plan is inactive, credit application returns false. Re-enabling the plan and retrying the same observation never retries the missing grant, because it is no longer newer. Observation and successful allocation need separate durable identities.

## Callback vs polling

| Concern | Current implementation / limit |
|---|---|
| One-time callback | POST `/payments/webhooks/fib`; `payments.fib.callback`; `FibCallbackController`. |
| Subscription callback | POST `/payments/webhooks/fib/subscription`; `payments.fib.subscription.callback`; `FibSubscriptionCallbackController`. It is present and wired. |
| URL construction | `FibCallbackUrlService::absoluteRoute`: configured `FIB_CALLBACK_BASE_URL`, falling back to `APP_URL`, plus the non-localized route path. Subscription callbacks require HTTPS; production one-time callbacks require HTTPS; localhost/.local rejected. This checks strings, not public reachability. |
| Authentication | Optional shared-secret header, constant-time comparison if configured; otherwise identifier alone validates. No documented FIB signature/replay timestamp verification is implemented. Creation payload specifies a URL, not how FIB would obtain/send this custom header. Verify provider support before treating the header as deployed authentication. |
| Request processing | Raw payload receipt persisted; matched Payment queues `ProcessFibPaymentStatus`; accepted 202. Testing dispatches synchronously. Production depends on a functioning worker. Invalid callback returns 406; orphan accepted and recorded. |
| Verification | Worker GETs the provider. One-time status derives from that GET. Subscription activation additionally trusts current/stored callback paid flags: P0 defect. |
| Polling | Browser five-second pending polling, four-second last-check hint, manual refresh, five-/ten-minute server reconciliation. These are parallel recovery paths, not a callback-only implementation. Last-check time is not a poll lease across concurrent requests. |
| Local testing | Synthetic HTTP callback requests to Laravel plus mocked GET responses suffice. No public tunnel or actual payment is needed. |

The official one-time docs describe callback ID/status, 202/406/500 responses and retries when callbacks receive no response. They also provide the authenticated status endpoint. The inspected subscription docs promise status callbacks but do not establish a signed delivery contract or guarantee a callback for every successful collection without a lifecycle-status change. Retain periodic recovery. Actual production callback URL, DNS/TLS, delivery history, secrets and worker operation are **not verified**. [FIB online payment documentation](https://documenter.getpostman.com/view/30814842/2sB2j68V73#intro).

## Scheduler

| Scheduled/job path | Frequency / targets | Provider calls | Writes / ownership |
|---|---|---|---|
| `credits:refill-monthly` / `RefillMonthlyCredits` | Daily 00:15; all eligible latest active service subscriptions | None | Both wallets, monthly grant, ledgers. Competes with provider renewal for paid allocations. |
| `payments:reconcile-fib-payments` / `ReconcileFibPayments` | Every five minutes, stale unresolved one-time checkout | GET status, token as needed | Payment/status events, possible fulfillment/order/credits/storage; locally expires eligible stale checkout. Applied/review/terminal rows skipped. |
| `subscriptions:reconcile` / `ReconcileSubscriptions` | Every ten minutes | Calls the next two commands unless explicitly skipped | Orchestrates sync then local service/storage expiry and default-subscription creation. Does not itself collect money. |
| `payments:reconcile-fib-subscriptions` / `ReconcileFibSubscriptions` | Child of the ten-minute orchestrator; unresolved recurring checkout | GET subscription | Initial payment state/events/fulfillment; local checkout expiry; not the owner of applied recurring renewal. |
| `payments:reconcile-fib-subscription-renewals` / `ReconcileFibSubscriptionRenewals` | Child of same orchestrator; paid/applied provider subscriptions with active entitlement or future active_until | GET subscription | Provider state/status events, lifecycle, possible App/API reset and renewal event. |
| `ProcessFibPaymentStatus` | Per matched callback / queue retry | GET status/subscription | Same sync and fulfillment paths, callback processed/failure evidence. No uniqueness/overlap job contract. |
| `SyncPendingPaymentIntentJob` | Legacy intent checkout fallback; default 15-second delay, up to five attempts | Adapter sync if available | Legacy intent/transaction/fulfillment path; not current FIB renewal. |
| Manual commands: `payments:fib:reconcile-subscription`, `payments:fib:cancel-subscription`, inspection/recovery, revenue reclassification, wallet repair, event pruning | Operator only, not scheduled here | Some modes do GET/create/cancel | Potential financial/history mutations. None run in this audit. |

`FIB_RECONCILIATION_ENABLED` controls the FIB scheduled block, default true. The daily refill lies **outside** that gate; disabling FIB reconciliation does not stop calendar grants. Scheduler guards use named overlap locks and `onOneServer` only with supported shared cache drivers. They do not coordinate browser/callback/Admin paths or make multiple machines safe with private file/array locks. No every-minute FIB schedule exists in this file: the every-minute job is GPU reconciliation, a separate domain.

The top-level subscription orchestrator already avoids registering checkout and renewal commands independently. The main business ownership overlap is **calendar refill versus provider renewal**, plus lifecycle/direct-expiry/default-subscription logic split across services and a console command. Keep one owner per accepted collection/credit cycle and one expiry service reused by callback and scheduler.

## Financial idempotency

| Action | Existing protection | Gap |
|---|---|---|
| Provider creation | Local unique UUID/local reference/idempotency key; provider IDs nullable unique. | New random key every checkout call; not a stable request replay claim. Shared authorized HTTP client retries POST as well as GET, with no provider idempotency field in the create DTO. A timeout after provider acceptance can create ambiguity/duplicate provider objects. |
| Initial fulfillment | Locked Payment plus `fulfilled_at` in same SQL transaction as local order/subscription/credits. | Good same-Payment protection. Does not serialize two different Payments for the same customer. |
| Calendar refill | Subscription/wallet locks, unique customer/month grant, both wallets and ledgers in transaction. | No shared provider-cycle identity. Duplicate-key exception branch returns false after wallet updates; if a competing different subscription hits that branch under MySQL, prior writes could commit without its ledger. This race is source risk, not native-reproduced. |
| Provider refill | Subscription lock + latest applied key; both wallet locks and writes transactionally. | Only latest key retained, not all applied cycles; observations can regress; old plan not excluded; missing-grant retry can be lost. Audit reproduces older-cycle replay and superseded-plan overwrite. |
| Admin corrections | Stable operation UUID/hash, fresh capabilities, locked operation/customer, guarded provider evidence. | Protects identical Admin intent. Automatic PlanSwitcher does not take the same customer/wallet locks, so do not infer global serialization. Preserve intentional distinct manual actions. |
| Ledgers | Reference codes indexed; charge/refund paths use wallet locks and supplied references. | No global unique reference constraint; references can legitimately span App/API and split buckets. Do not add a naive unique reference constraint. |
| Generic webhook | Unique webhook event key + intent fulfillment lock. | Optional authentication and unscoped intent lookup; unsafe even if duplicate delivery only fulfills once. |

`PlanSwitcher` uses a transaction but reads the current subscription and existing wallets without `lockForUpdate`. An in-flight credit spend/add-on update can be overwritten by its stale wallet snapshot, and distinct simultaneous plan purchases can race to create active subscriptions. This is an evidenced concurrency gap, not a claim of observed production double charging. Existing Admin operation race tests exercise a different, stronger boundary.

`CreditMonthlyGrant` is an allocation/month marker, not an immutable invoice or a provider receipt. Initial plan switches update its existing row, while provider renewals do not create one at all. Renewal delta ledgers are omitted when delta is zero despite cycle/lifetime-earned changes. A durable collection/allocation record must exist independently of net balance delta.

## Security findings

Severity follows the requested definitions. A reproduced unsafe behavior is not evidence that an attacker used it in production.

| ID | Priority | Finding and evidence |
|---|---|---|
| B01 | P0 | Paid calendar refill without fresh collection/covered-term validation: `RefillMonthlyCredits:36–125,182–317`; reproduced. |
| B02 | P0 | Independent calendar/provider grants replenish spent credit twice: `CreditService:341–503` versus monthly grant; reproduced. |
| B03 | P0 | Unsigned FIB subscription callback with `paymentStatus=PAID` activates ACTIVE/no-payment-evidence checkout when optional secret absent. `FibSubscriptionWebhookValidator`, `SyncFibCheckoutStatus:453–468`, mapper; full HTTP/fulfillment reproduction. Needs knowledge of a local subscription reference, obtainable for one's own checkout; not a claim of cross-customer account access. Stored callback claims also remain reusable. |
| B04 | P0 | Areeba callback allows null authentication, ignores provider enabled state and resolves intents without provider constraint. `AbstractConfiguredPaymentProvider::validateWebhookSignature`, `PaymentWebhookService:21–110,153–176`. Reproduced unsigned callback to a disabled adapter fulfilling a historical FIB-labelled intent without any provider request. Reach depends on matching historical intents; current FIB Payment rows use a separate table. |
| B05 | P0 | Documented numeric dates misparsed, corrupting financial lifecycle evidence / breaking timestamp persistence: `FibSubscriptionStatusData:91–103`; year-8900 parser reproduction. MySQL outcome remains unexecuted. |
| B06 | P1 | 45-/46-character scheduled source versus VARCHAR(40) prevents strict-schema event/status persistence. Migration plus both reconciliation call sites; source assertion, not MySQL acceptance. |
| B07 | P0 | Superseded subscription reactivation can reset wallets to an obsolete plan: `SyncProviderSubscriptionLifecycle:47–60,132–179`; reproduced current plan/allowance mismatch. |
| B08 | P0 | Non-monotonic observations and single latest cycle marker permit old-cycle reallocation: lifecycle `renewalDetected` plus unconditional metadata assignment; reproduced June, July, stale May, June sequence. |
| B09 | P1 | Stale ACTIVE clears local cancellation intent and end boundary: lifecycle `shouldAutoRenew/applyLifecycleState`; reproduced. |
| B10 | P1 | Observation marked seen although grant returns false (inactive plan); same evidence never retries later: lifecycle versus `CreditService:400–409`; reproduced. |
| B11 | P2 | Unchanged polling inserts unlimited financial-table observations; duplicate callback processing still calls provider; reproduced status-event growth. |
| B12 | P1 | Missing-period exemption can preserve paid access indefinitely; Free-row creation and expiry are split. `ReconcileSubscriptions:414–550,658–706`; source/partial existing coverage, combined downgrade/refill scenario still needed. |
| B13 | P0 | PlanSwitcher wallet/current-subscription reads are not locked; different-payment fulfillment can conflict with spending/grants/other switches. Source race exposure; not reproduced with native concurrent sessions. |
| B14 | P1 | Normal sync does not compare response object ID, amount or currency to the Payment; AdminProviderEvidence does. Reproduced mismatched subscription response becoming paid. This is not evidence that the bank returns wrong responses, but it fails to detect integration/reference corruption. |
| B15 | P1 | Create POST retry and fresh local checkout identity do not establish durable provider creation idempotency; `FibAuthorizedClient::authorized`, create actions and DTOs. Ambiguous acceptance must not become an automatic fresh purchase. |
| B16 | P2 | Provider cancellation and notifications occur inside fulfillment/cancellation SQL transactions. Remote acceptance cannot roll back; a local retry can repeat remote effects. Cancellation-failure event can roll back with the validation error it records. |
| B17 | P2 | Recorder stores raw callbacks/status payloads; generic webhook stores all headers. PII/bank fields and inbound secrets can remain in the database; raw exception messages also enter callback failure events/logs. Admin display redaction is useful but is not storage redaction. No real secret values were inspected/exported. |
| B18 | P2 | Permanent renewal pause metadata is not enforced by renewal eligibility; initial ACTIVE without period/evidence can also leave checkout automatic recovery skipped. Review/unknown state needs explicit recovery ownership. |
| B19 | P3 | Two payment architectures, alias classes, console-embedded lifecycle/grant rules and repeated service/storage mappings obscure ownership. Consolidate only after behavioral fixes. |

Existing protections to retain: server catalog/fee/coupon calculations; customer Payment ownership; constant-time optional secret comparison; same-Payment locked fulfillment; no paid-to-unpaid rewriting; App/API separation; P0 Admin capability/reason/intent/evidence boundaries; current limited-duration recurring coupon rejection. No authentication or pricing bypass is recommended.

## Code organization

| Class | Exact files/modules and role |
|---|---|
| A — current required billing core | `app/Domain/Payments/Actions/{CreatePlanSubscriptionPayment,CreateStorageSubscriptionPayment,CreateAddonPayment,SyncFibCheckoutStatus,ConfirmFibPayment,CancelFibCheckout,CancelFibPayment,FulfillPlanSubscription,FulfillStorageSubscription,FulfillAddonCredits}.php`; `app/Domain/Payments/Models/{Payment,PaymentEvent}.php`; domain `Data`, `Enums`, `Support`, `Contracts`; explicit one-time/subscription clients/services/mappers/validators and cancellation service under `app/Domain/Payments/Fib/`. |
| A — local subscription/credits | `app/Services/Billing/{PlanSwitcher,CreditService,SyncProviderSubscriptionLifecycle,ScheduleServicePlanCancellation,ScheduleStoragePlanCancellation,CustomerBillingStateService,CustomerOnboardingService,ManualServicePlanGrantService}.php`; `app/Models/{Customer,CustomerServiceSubscription,CustomerStorageSubscription,CreditOrder,CreditMonthlyGrant,CreditWallet,CreditLedger,ServicePlan,StoragePlan,CreditProduct}.php`; `app/Observers/CustomerObserver.php`. |
| A — current interfaces/operations | `app/Http/Controllers/Payments/{FibCallbackController,FibSubscriptionCallbackController,FibPaymentController}.php`; `app/Jobs/Payments/ProcessFibPaymentStatus.php`; `app/Events/Payments/PaymentConfirmed.php`; `app/Listeners/Payments/RunPaymentFulfillment.php`; `app/Console/Commands/{RefillMonthlyCredits,ReconcileFibPayments,ReconcileFibSubscriptions,ReconcileFibSubscriptionRenewals,ReconcileSubscriptions}.php`; shared checkout Blade files above. |
| B — shared required infrastructure | `app/Services/Payments/{CheckoutAuthorizationService,PaymentMethodCatalog,PaymentFeeCalculator,PaymentApplicationService,PaymentSyncFailureService,AddonPurchaseService}.php`; `app/Services/Coupons/`; `BillingCurrencyService`, `CustomerUsageSummaryService`; `FibTokenService`, `FibConfiguration`, `FibAuthorizedClient`, `FibFailureInterpreter`, `FibDiagnostics`, `FibCallbackUrlService`; `app/Services/Admin/{AdminOperationRunner,AdminFinancialCorrections,AdminPaymentReconciliation,AdminProviderEvidence,AdminAudit}.php`; `app/Support/Admin/{AdminAccess,AdminData,InteractsWithPaymentAdmin,ManagesCustomerRegisterPage}.php`; notifier/support classes; PaymentPolicy; payment configuration, routes and migrations. |
| C — legacy compatibility, still reachable in part | `app/Models/{PaymentIntent,PaymentTransaction,PaymentWebhookEvent,CustomerPaymentMethod}.php`; `app/Services/Payments/{PaymentIntentService,PaymentIntentReconciliationService,PaymentIntentStatusSyncService,PaymentIntentLifecycleService,PaymentFulfillmentService,PaymentWebhookService,PaymentProviderManager}.php`; `app/Services/Payments/Providers/{FibPaymentProvider,AreebaPaymentProvider,FakePaymentProvider,AbstractConfiguredPaymentProvider}.php`; `app/Jobs/Payments/SyncPendingPaymentIntentJob.php`; `app/Http/Controllers/Payments/AreebaWebhookController.php`; Areeba gateway/interfaces. The public Areeba webhook is active route architecture even though current explicit checkout actions reject non-FIB providers. Retain historical relationships; secure the route before considering retirement. |
| D — likely obsolete/duplicate candidates, not deletion approval | `app/Domain/Payments/Fib/{FibClient,FibPaymentService,FibWebhookValidator}.php` alias classes; unused `CreditService::grantMonthlyCredits`; unused customer-facing entry paths into the legacy PaymentIntent checkout subsystem. Repository search found no current App checkout callers for PaymentIntentService; its FIB adapter explicitly throws retirement errors. Operator `ReconcileFibSubscription` versus automatic lifecycle has duplicate reconciliation logic, but remains a useful privileged repair interface. Do not delete it as dead code. |

The explicit Payment and old PaymentIntent enums/models are different types, not interchangeable aliases. Controllers perform receipt/event/dispatch orchestration; business transitions are mostly in actions/services. Livewire calls those actions and exposes polling/retry controls. Large reconciliation commands still contain subscription expiry and credit mutation rules instead of merely invoking a shared domain operation. The two provider-specific clients are legitimate distinct contracts; consolidating them must preserve separate credentials/profiles and date formats.

## Recommended target flow

1. **One-time:** server-priced checkout with stable intent identity -> provider creation -> callback wakes verified GET -> validate reference/money/status -> atomically claim fulfillment and apply purchase once. Preserve current purchase-specific handlers.
2. **Recurring activation:** provider subscription consent -> verified initial collection (or separately approved trial policy) -> record paid coverage and initial allocation -> establish normalized local subscription. ACTIVE alone never authorizes a new paid allocation.
3. **Successful renewal:** verified new collection identity/timestamp with validated paid period -> durable collection/allocation claim -> one transaction updates subscription and both wallets/ledgers -> meaningful keyed event. Duplicate/old responses cannot move evidence backward or refill another plan.
4. **Failed/unknown renewal:** record provider evidence/failure separately; no new monthly paid allocation without covered term. Keep already-paid access through its verified end. Unknown provider availability is operational review, not automatic financial success or refund.
5. **Cancellation:** persist cancellation intent, request stop-renewal, verify asynchronously; retain paid term. Stale ACTIVE cannot erase the intent. Preserve paid financial history and add-ons.
6. **Expiry/Free:** one shared operation ends the term and ensures the default Free subscription needed by Free allocations; all callers use it. Monthly Free refill remains calendar based. Annual/manual allocations must be explicitly covered by their term and policy.

No complicated new state machine is required as the first fix. Clear separation of collection evidence, paid-through period, allocation identity and cancellation intent is sufficient. Provider billing remains in FIB; local allocation remains in MetKurd.

## Fix plan

**Phase 1 — evidence and callback boundary.** Correct timestamp normalization with bounded, explicit units; reject response identity/money mismatch; remove callback paid claims as authoritative evidence; fail closed for unsupported/unsigned generic webhook transitions and enforce provider-scoped lookups. Correct scheduled event source/schema mismatch. Add strict-engine acceptance before deployment. No historical backfill.

**Phase 2 — financial cycle and cancellation correctness.** Make paid calendar allocation require verified term coverage, share a durable allocation claim with provider renewal, reject regressing/superseded observations, separate observed/applied markers and serialize customer/subscription/wallet operations in consistent order. Preserve cancellation intent; centralize expiry/default Free creation. Retain explicitly authorized manual/annual behavior and already-owned add-ons.

**Phase 3 — operational noise and recovery.** Emit durable business events only for meaningful transitions/collections; coalesce duplicate callback work and unchanged polling; enforce permanent-failure pause/recovery ownership; move external notifications/cancel requests to explicit after-commit/recoverable work. Review legacy retirement and retention only with separate approval and backups.

Do not rewrite historical balances, prices, identities or invoices in any of these code-fix phases. Historical investigation and any correction need a separate evidence-backed operator plan.

## Tests required

### Executed in this audit

- `tests/Feature/Payments/BillingAuditCharacterizationTest.php`: **12 passed, 62 assertions**. These tests deliberately assert unsafe **current** behavior to prove findings; they are not desired acceptance specifications. Reverse/replace the affected expectations with rejection/no-extra-grant expectations when each approved fix lands.
- Broader run: `tests/Feature/Payments`, `tests/Feature/Billing`, `AdminP0SafetyTest`, `AdminBillingControlsTest`, `AdminCustomerPaymentsReviewTest`, `AdminPaymentPlansCreditsTest`: **271 passed, 3 failed, 1,574 assertions**. That run loaded the first eight audit cases; the final standalone run covers all twelve. Do not add these counts as disjoint suites.
- Existing failures: `FibPaymentFlowTest:2468` expects English redirect text on an Arabic page; `SeparateCreditWalletArchitectureTest:180` expects legacy API component properties; `:340` expects API resource visibility. All three reproduce in a separate filtered run that excludes the new audit file (3 failed, 12 assertions). These are UI assertions, not new financial reproductions. They are reported, not silently repaired. No all-green suite claim.
- PHP syntax/Pint checked for the audit test. No frontend build is needed for this report/test-only change. No native MySQL/RDS, distributed locking, real FIB collection or public callback acceptance was executed.

### Existing coverage and gaps

| Area | Existing evidence | Required acceptance addition |
|---|---|---|
| One-time/recurring activation | `FibPaymentFlowTest`, `CouponCheckoutTest`, `AddonPurchaseAuthorizationTest` cover server checkout, provider success, ownership, duplicate callback and fulfillment recovery. | Wrong ID/amount/currency, missing/forged/stored callback evidence; never fulfill from an unsigned claim. |
| Provider date formats | Existing subscription fixtures mainly ISO strings; new audit proves numeric misparse. | Actual documented millisecond integers/numeric strings, ISO where supported, null/malformed/out-of-range values, UTC conversion and exact boundary; strict MySQL persistence. |
| Renewal grants | `FibPaymentFlowTest` covers newer cycle, repeated same cycle, event/notification dedup; `MonthlyCreditRefillCommandTest` covers Free anniversary and short months. | Paid calendar with no collection; both callback/calendar orderings with spend between; annual covered months versus expired annual term; explicit manual terms; missed scheduler day and month-end. |
| Financial replay | P0 Admin same-intent concurrent-process test and Payment locked fulfillment. | Real concurrent callback/poll/Admin/spend/plan-switch sessions; distinct Payments same customer; rollback after each wallet update; unique-claim conflict must roll back all writes. |
| Monotonicity | New audit covers old-cycle replay, superseded resurrection and lost retry when plan inactive. | New/old/new observations cannot regress paid-through or double allocate; recorded-but-unapplied cycle remains retryable; old subscription cannot alter active wallet. |
| Cancellation/downgrade | `FibPaymentFlowTest`, `SubscriptionArchitectureTest`, `MyStorageRecurringFlowTest` cover provider cancel and exact hourly fallback; `SubscriptionsReconcileCommandTest` covers local overdue/default plan. | Millisecond cancelled response; accepted-but-still-ACTIVE cancellation; callback lost; sync throws before lifecycle; ended-by-lifecycle then explicit Free row/refill; no premature removal of paid access/add-ons/files. |
| Failed renewal/provider unavailable | `PaymentSyncFailureServiceTest`, `FibPaymentFlowTest`, `SubscriptionsReconcileCommandTest` cover several failure/review/missing-metadata cases. | No paid refill on stale evidence; bounded missing-period policy; permanent renewal pause actually prevents GET; resumed evidence safely recovers exactly once. |
| Event growth | `PaymentEventNoisePruneCommandTest` tests pruning protections; existing renewal tests assert business-event dedup, not all status-event volume. | Repeated unchanged ACTIVE/UNPAID/callback creates no unbounded business events, changed collection with same ACTIVE emits once, all source/key lengths fit native schema. |
| Generic legacy webhook | `PaymentWebhookFlowTest` tests positive unsigned processing and replay; new audit proves disabled cross-provider path. | Disabled/unconfigured/missing-secret/invalid-secret rejected before financial changes; provider-scoped intent match; validated amount; replay/body-order/key-length handling. |
| Admin/App/API | P0 and Admin billing tests, `SeparateCreditWalletArchitectureTest` cover capability/reason/intent boundaries and wallet separation. | Preserve all gates; same financial cycle cannot be claimed again through reconciliation; both wallets consistent; API display failures tracked separately. |

Local test recipe: isolate database/cache/session/storage; fake mail/notifications and prohibit stray HTTP; create synthetic checkout/subscription; POST a synthetic callback to the real route; mock authenticated provider GET response; assert Payment, subscription, both wallets, ledgers, grant identity and events before/after repeated and reordered requests. Queue dispatch and worker retry require a separate non-financial harness because testing controllers dispatch synchronously. No tunnel or real FIB request is necessary.

## Database changes

**No migration created or run.** Parser, callback validation, event emission, cancellation precedence and most lifecycle fixes can start in source. Source labels can be shortened to fit the existing 40-character column; alternatively, a reviewed additive widening migration is justified if retaining the current labels is required. Do not silently truncate diagnostic identities.

A durable per-provider-collection/per-allocation unique claim is justified for Phase 2 because customer/month uniqueness and a single JSON latest-key do not represent all accepted provider cycles. Prefer a small additive allocation/collection structure or carefully extended existing grant model after agreeing annual/monthly/manual semantics. Keep original monthly grant uniqueness/history intact; do not retrofit fabricated historical receipts or add a generic unique ledger reference.

| Table | Current role / relationships / constraints |
|---|---|
| `payments` | Current FIB checkout plus recurring provider object snapshot. Unique UUID/local reference/idempotency key; nullable unique FIB payment/subscription IDs. Customer FK; polymorphic purchasable has no target FK. Provider status, financial status and internal application status are separate. One row does not enumerate recurring collections. |
| `payment_events` | Mixed financial audit and poll log. Nullable Payment FK with null-on-delete; raw provider identifiers retained; nullable unique key. VARCHAR(40) source mismatch above. |
| `payment_intents` | Older generic checkout contract, retained for historical/adapters. Unique UUID/idempotency/merchant reference; provider references indexed, not unique. Not current FIB Payment authority. |
| `payment_transactions` | Child of legacy intent, self-parent nullable FK; indexed provider references without a collection uniqueness rule. Current explicit FIB renewal does not populate it. |
| `payment_webhook_events` | Legacy webhook receipt/processing, unique event key, nullable intent FK, raw headers/payloads. Separate from current FIB payment_events. |
| `credit_orders` | Purchase outcome/revenue metadata; nullable Payment and PaymentIntent links, plan/product relationships. No one-order-per-payment uniqueness. Normal fulfillment relies on locked parent marker. |
| `customer_service_subscriptions` | Normalized entitlement history, customer/current/previous plan and optional Payment/method FKs; dates/auto_renew/strategy/meta. Current plan FK restricts deletion; previous plan null-on-delete. No unique active-subscription-per-customer constraint; application must serialize term changes. |
| `credit_monthly_grants` | Unique customer/year-month allocation marker, required plan FK, nullable subscription FK; initial switches update same month row. No provider-cycle key/unique collection identity; API allocation stored in metadata. |
| `credit_wallets` | Current materialized balance; unique customer/wallet_type after split migration. App/API subscription/add-on buckets separate. Not proof of collected money. |
| `credit_ledgers` | Balance movement evidence with customer/channel/bucket/reference; reference index not unique. Preserve split-bucket semantics and historical rows. |
| `service_plans` | Catalog/allowance configuration, App/API allowance and term/pricing/access fields; not customer subscription storage. There is no physical customers.service_plan_id dependency to introduce. |

Repeated fields (`active_until`, metadata `period_ends_at`/`provider_active_until`, cycle dates/next renewal, observed/applied cycle keys) have competing precedence. `paid_at` retains original payment time while `last_payment_at` is mutable latest evidence. Provider renewal does not change the original order's payment timestamp or create recurring invoices. Missing metadata flags can remain after later evidence changes. Document a single authoritative paid-through and applied-cycle record before reducing redundant projections. Historical customer-delete cascades and null-on-delete links exist in migrations; they are not permission to delete financial history. Preserve P0 final deletion guards.

## Files likely to change

Priority files: `app/Domain/Payments/Data/FibSubscriptionStatusData.php`; `app/Domain/Payments/Fib/{FibSubscriptionMapper,FibSubscriptionWebhookValidator,FibAuthorizedClient,FibSubscriptionClient,FibOneTimePaymentClient}.php`; `app/Domain/Payments/Actions/{SyncFibCheckoutStatus,CreatePlanSubscriptionPayment,CreateStorageSubscriptionPayment,CreateAddonPayment}.php`; `app/Services/Payments/{PaymentWebhookService,PaymentSyncFailureService}.php`; `app/Services/Payments/Providers/AbstractConfiguredPaymentProvider.php`; `app/Http/Controllers/Payments/{FibSubscriptionCallbackController,FibCallbackController,AreebaWebhookController}.php`.

Cycle/lifecycle files: `app/Services/Billing/{CreditService,PlanSwitcher,SyncProviderSubscriptionLifecycle,CustomerBillingStateService,ScheduleServicePlanCancellation,ScheduleStoragePlanCancellation}.php`; `app/Console/Commands/{RefillMonthlyCredits,ReconcileSubscriptions,ReconcileFibSubscriptions,ReconcileFibSubscriptionRenewals}.php`; `app/Domain/Payments/Support/{PaymentEventRecorder,PaymentReconciliationPolicy}.php`; `app/Jobs/Payments/ProcessFibPaymentStatus.php`; `app/Models/CreditMonthlyGrant.php`; any reviewed additive allocation migration/model. Admin evidence/correction callers and purchase-specific fulfillment actions need regression review, not blanket replacement.

Follow-up tests belong in `tests/Feature/Payments`, `tests/Feature/Billing` and relevant Admin P0 tests. Update architecture/import/operational docs only after approved behavior actually changes. This audit added only **this report** and **BillingAuditCharacterizationTest.php**; pre-existing uncommitted Admin files were left intact.
