# V2 payment-domain cutover

## Reviewed provider coverage exception — 2026-09-30

The current release adds `provider_coverage_dispositions`; review its
[operator procedure](PROVIDER-OBLIGATION-REVIEW.md) before cutover. Confirmed cancellation
plus a completed immutable Admin approval can retain the same service/storage
subscription under the explicitly reviewed bounded provider term. The full original
term/provenance snapshot survives, dates are projected only at cutover, and exact
fingerprints/effective-plan identity must verify before commit. This is not a manual
or cash grant, current revenue, or credit-cycle authority. Unapproved/ambiguous paid
coverage and remote obligations still block production.

The processing-delete set now excludes narrowly corroborated paid fake/manual intents
and their transaction/webhook history. Their retained CreditOrder links and ledger/
allocation dependencies remain intact. All other dependency guards still apply.
The original blanket five-table retirement/date-preservation description below is
superseded ONLY for these explicit reviewed preservation cases. Local-rehearsal and
production retain their existing separate identity/preflight policies.


## Deployment targets — 2026-09-15

The shared review/mutation algorithm now requires explicit `--target=local-rehearsal`
or `--target=production`, matching disabled-by-default deployment assertions in
`config/billing_cutover.php`. Separate identity policies check local hostname versus
RDS/native MySQL facts. No CLI connection override or identity bypass is available.
Production additionally requires complete migration/Admin readiness, backup/restore
evidence and attestations, resolved provider obligations and its distinct confirmation
phrase. The target and readiness are hashed with the existing full review.
See the [exact target configuration and commands](V1-TO-V2-PRODUCTION-RUNBOOK.md#6-billing-cutover--explicit-deployment-target).
Source support is not production acceptance. No cutover was executed in this refactor.

## Post-execution correction — 2026-09-15

The operator executed the local cutover. The historical projection retained four manual
accounts, including customer 1; that recorded audit is not rewritten. Customer 1's retained
manual term was already expired. Shared post-epoch eligibility now respects recorded
terms, and future dry-run projections use the same policy while retaining unclassified
paid-access blockers. Existing audits/history remain immutable; do not rerun this reset.
Current V2 history and provider processing now share the audit boundary. Legacy orders
remain accessible through the Admin Legacy / Pre-V2 selector. See the updated
[production runbook](V1-TO-V2-PRODUCTION-RUNBOOK.md); the deployment-target policy above supersedes the former local-only identity guard.


## Scope and authorization — 2026-09-15

`billing:cutover-reset-payment-domain` is a separate, intentional business cutover.
It does not change `billing:reset-payment-history`, legacy cleanup, checkout review,
provider reconciliation, public prices or the recurring allocation policy.
Source implementation and isolated tests are not approval to execute the cutover.
The operator must review the actual dry-run manifest before execution.

Local execution uses Laravel's local environment and configured loopback mysql
connection, exactly matching BILLING_CUTOVER_EXPECTED_HOST/DATABASE/PORT. DATABASE(),
SQL port, FK/writable state and local SQL hostname are checked. MariaDB is allowed
for disposable-copy rehearsal only. Production uses APP_ENV=production, an asserted
RDS endpoint, native MySQL/UUID/no replica channels, enabled foreign keys and writable
server flags; it never compares the server hostname to the app machine. Both refuse
split connections, prefixes, sockets and non-InnoDB reviewed tables. No production
code path accepts SQLite; isolated tests replace only database identity facts.

## Reviewed changes

- Delete **all** Payments, PaymentEvents, legacy PaymentIntents, PaymentTransactions
  and PaymentWebhookEvents. Retain no latest Payment. Delete children before parents
  and self-referencing transactions in leaf order, with foreign keys enabled.
- Preserve CreditOrders byte-for-byte except reviewed nullable `payment_id` and
  `payment_intent_id` detachments. Apply the same explicit nullable-link policy to
  normalized subscriptions, coupon redemptions and ad conversion events. Retain
  every original table/row/column/target mapping in the manifest and committed audit.
  No coupon status or usage counters change.
- Retain old provider-derived service/storage subscription rows, original dates,
  sources and provider metadata. Only set local `status=ended`, `auto_renew=0`, and
  detach reviewed payment foreign keys. This does **not** claim remote cancellation.
  The audit explains why these rows ended without inventing a historical end date.
- Preserve explicit local grants and Cash/external agreements, their allocations,
  dates, allowance snapshots and existing audit evidence. Provider-shaped fields on
  legacy `admin_manual` rows alone do not prove online provenance: a retained manual
  order must match customer, plan, reference, manual provider/method and metadata,
  have no online payment/intent link, and share no FIB object reference. Conflicting
  evidence is a blocker. This recognition does not repair old manual revenue flags,
  renewal fields or dates, and is not evidence of an external cash receipt.
- Customers without a remaining eligible subscription use the existing Free
  fallback. No PlanSwitcher/expiry/refill action, new Free allocation, replacement
  order or historical subscription allocation runs during cutover. Retained local
  access that conflicts with newer lifecycle authority is blocked for review.
- Preserve all wallet fields, App/API balances and buckets, lifetime counters,
  ledgers, customers/verification, jobs/files/storage records, products, prices,
  entitlements, users and unrelated tables. Allocation rows are never removed or
  rewritten; an allocation linked to a deleted Payment blocks this operation.
- Polymorphic ledger/provenance references remain untouched; their old target IDs
  are archived in the manifest alongside Payment UUID mappings. They intentionally
  refer to retired history rather than a fabricated replacement Payment.

The command uses database reads and direct transactional writes. It makes no FIB,
HTTP, storage-disk or S3 requests and invokes no billing fulfillment, cancellation,
wallet or model lifecycle mutation. Missing remote objects and unknown provider
states are not cutover blockers **only for local-rehearsal**. Production blocks every
unresolved remote obligation and still-valid/ambiguous coverage before deletion.

## Current revenue boundary

One append-only Admin audit (`billing.cutover_reset_payment_domain`) atomically
records `reporting_boundary.starts_at`, the retired CreditOrder/Payment ID high
watermarks and the sanitized reviewed manifest. The private dry-run file retains
the complete fingerprints, including tables whose names trigger Admin redaction.
`BillingReportingBoundary` reads
that persisted boundary without an environment edit or migration. Repeating a
cutover on the same database is refused.

Current Admin dashboard, plan/add-on sales, customer aggregates and rankings apply
the boundary to their revenue queries. Dashboard cache identity includes it, so a
warm pre-cutover cache cannot display old totals. New records must be created on or
after the boundary and exceed the retired ID watermark; future-dated old orders
cannot leak into new revenue. Normal generated IDs must remain monotonic: do not
reset auto-increment counters or import explicit historical IDs after cutover.
Existing revenue-exclusion rules still apply.

Historical order classification is retained. Current customer/Admin financial reads
use the epoch; the explicit Admin legacy view exposes archived orders separately. Current
sales/revenue/payment totals are zero immediately after cutover; future real new
payments and their fulfilled orders are counted through normal billing behavior.
Do not remove the cutover audit or treat it as disposable logging: it is persisted
reporting configuration and the archive for detached references.

## Dry run and execution

From the repository root:

```powershell
php artisan billing:cutover-reset-payment-domain --target=local-rehearsal --dry-run
```

The mode defaults to read-only, but omission of the target is refused. MySQL/MariaDB inventory uses a
repeatable-read, read-only transaction. Keep its full output private: it includes
internal customer IDs, exact deletion IDs, mappings, subscription classifications,
effective-plan projections, wallet totals, revenue baseline and fingerprints.
It excludes passwords, provider payloads, checkout URLs and QR codes.

Fingerprint specification: each raw database row is keyed by column name, encoded
with JSON null/type preservation and SHA256-hashed; sorted row hashes form a final
SHA256 multiset digest per table. The expected result projects only reviewed column
patches and deletions. This catches same-count field changes and preserves actual
date/metadata values. MySQL/MariaDB transactional table engines and inbound foreign
keys are checked. Triggers or unsupported foreign keys require separate review.

Before execution, export a normal backup of the local database, stop application
workers/scheduler/callback intake and every other writer, and enable maintenance.
Run a **fresh dry run after stopping writers**; sessions/cache/queue changes can
invalidate the prior whole-database hash. Review that final manifest. The command
does not stop processes or enter maintenance automatically.

Only after operator review, substitute the actual hash and authorized Admin ID:

```powershell
php artisan billing:cutover-reset-payment-domain `
  --target=local-rehearsal `
  --execute `
  --review-hash="REVIEWED_HASH" `
  --confirm="RESET-V2-BILLING-DOMAIN" `
  --admin=ADMIN_ID `
  --reason="Local production-snapshot rehearsal for MetKurd V2 billing cutover" `
  --workers-stopped
```

Fresh active `admin.finance` and `admin.reconcile` checks, a substantive reason,
maintenance and stopped-writer attestation are mandatory. Locked re-inventory must
match the hash. All changes and the one new audit share a transaction. Exact final
fingerprints, untouched prior audit history, actual shared effective-plan resolver
results and zero current revenue/payment state must pass before commit. Failures
roll back; no foreign-key disabling, truncation, provider calls or partial reset.

## Acceptance boundary

### Observed local dry run — 2026-09-15

The designated local database returned an eligible dry run with no blockers:
151 Payments, 38,872 PaymentEvents, one legacy intent, two transactions and no
webhook events are selected for deletion. It proposes 59 nullable reference
detachments and retirement of 24 service plus one storage provider-derived rows.
Projected service access: 1,041 Free customers and four retained manual/internal
plans; projected storage access: all 1,045 Free. Existing Cash agreements and
allocation rows are both zero in this copy. All 34 CreditOrders remain.

There are 1,045 customers, 762 dual-verified customers, 6,499 ledgers, 2,187 MlJobs
and 2,464 CustomerFiles. The App/API wallet totals are 11,644,239 / 2,600,000 credits,
with 46,211 App add-on credits. The full private operator manifest stores exact
IDs, all before/expected-after fingerprints and its review hash; do not commit it.
No cutover, provider request, storage request, application seeder or feature-gate
change was executed. SQL reported local MariaDB 10.4.28, not native MySQL acceptance.

Verification: 25 new cutover cases and 474 existing billing/Admin cases passed
across isolated SQLite runs; PHP syntax/Pint passed for 11 files. One known existing
V1 Arabic payment-page test still expects English redirect text
(`FibPaymentFlowTest.php:2476`). Initial fixture mistakes in two new tests were
corrected and those cases passed. No unrelated V1 page change was made.

### Remaining acceptance

The local command dry run is separate from executing the reviewed cutover. Isolated
SQLite regression coverage is also separate from the post-cutover production-copy
rehearsal. After approved execution, verify all preservation results and exercise
new synthetic customers with mocked provider evidence through initial purchase,
App/API allocation, verified renewal/replay, failed renewal/expiry, both cancellation
directions, upgrades/downgrades, add-ons, storage, one-time/recurring purchases,
history and revenue. No live FIB credentials or production requests are permitted.
Until that sequence passes, the result remains **BLOCKED — do not prepare production
cutover yet**.
