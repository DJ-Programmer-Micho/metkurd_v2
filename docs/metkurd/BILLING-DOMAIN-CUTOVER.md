# V2 payment-domain cutover

## Full local billing reset — 2026-10-01 (current business contract)

The approved final semantics use **`--mode=full-local-reset`**. This section supersedes
older access-preservation/provider-disposition instructions below for this explicit
mode. `preserve-access` remains the compatibility default; an old command/hash cannot
silently acquire the more destructive all-customers-Free semantics. Mode, deployment
target, operator, readiness, schema and row fingerprints are bound into the review.
“Local” describes retirement of MetKurd's domain: the existing local-rehearsal versus
production identity policies still apply unchanged. This is source support, not
permission to execute on any application database.

- All existing service/storage subscriptions become `ended`, with renewal disabled.
  Provider references and operational provider/cancellation metadata are removed from
  those subscription rows; commercial history, plan identities and dates remain.
  Existing cash agreements, including future scheduled terms, become `ended`.
  No remote cancellation status or timestamp is fabricated. Every customer resolves
  to Free; old grants, cash agreements and provider coverage cannot refill wallets.
- Every row in **payments, payment_events, payment_intents, payment_transactions,
  payment_webhook_events** is deleted, regardless of status. No processing row is
  migrated or archived elsewhere. There is **no legacy FIB exit manifest** or retained
  provider-ID cleanup queue. Merchant-side cleanup is outside MetKurd after this reset.
- ProviderObligationInventory and ProviderCoverageDispositions are not consulted in
  this mode. Old missing/conflicting provider evidence, unresolved financial review
  and retained coverage do not block it and are not relabelled CANCELLED/REJECTED.
- Customers, App/API wallets (including both credit buckets), balances, credits,
  ledgers, jobs, files, storage usage and history retain exact full-row fingerprints.
  CreditOrders retain financial/revenue facts. Known nullable Payment/Intent FKs in
  orders, subscriptions, coupons, conversions and subscription allocations are detached;
  only these local row/column/ID mappings are audited. Allocation facts/cycle identity
  remain intact; ledgers are never rewritten. Unexpected/nonnullable dependencies
  still block. Existing immutable revenue/Admin review history is preserved as history,
  never as a provider-processing fallback or future cleanup authority.
- The single cutover audit contains `reporting_boundary` and `reset_verification`
  (counts, hashes, wallet totals and local detach mappings). It contains no copied
  Payment/Intent/transaction payloads, provider object IDs or exit manifest. All
  existing Admin audit rows remain byte-for-byte unchanged.
- Backup and restore evidence plus explicit confirmations, full migration/Admin
  readiness, maintenance, stopped writers, correct deployment identity, transactional
  schema, exact review hash and before/after preservation checks remain mandatory on
  **both** targets. No FK disabling, TRUNCATE, counter reset or generic repair SQL.
  A database with an existing committed cutover remains ineligible for a second reset.

### New epoch fence

The committed audit records subscription/agreement ID watermarks as well as the
existing Payment/order timestamp and ID boundary. Old local subscriptions remain
ineligible even if a stale process marks them active or they are future-dated. Old
agreements cannot activate/refill. No old provider coverage disposition confers access.
Payment IDs remain monotonic; “first V2 Payment” means first current-epoch Payment,
not ID 1. Do not import/reuse old IDs or provider objects after reset.

Native FIB callbacks may wake up only a known current-epoch Payment. Unknown, old or
unmatched malformed callbacks return 202 without retaining the provider ID, creating
orphan events or dispatching status work. Old queued callback jobs likewise exit.
The retired generic FIB PaymentIntent webhook returns 410 before recording anything.
Other legacy webhook deliveries must resolve a known new-epoch intent before recording
anything; old/unknown deliveries return 410. Old provider batch-review packets cannot
be generated after this reset.
New epoch Payments always use compact persistence. Actionable QR presentation remains
in CheckoutQrCache/private non-SQL storage with checkout-lifetime TTL; durable
readable_code/provider_links remain fallback. A cache miss never creates a new FIB
object. Unchanged polling and duplicate callbacks add no lifecycle events; repeated
failure evidence stays aggregated. See [the persistence contract](PAYMENT-PERSISTENCE-V2.md).

### Verification for the full reset

`FullLocalBillingResetTest` covers populated processing tables, App/API credit buckets,
ledger/customer/job/file/storage-usage fingerprints, retained order/allocation facts,
all-Free manual/provider/active-cash/future-cash access, stale lifecycle refusal,
unknown native and compatibility callbacks, no provider manifest/disposition, new
compact paid fulfillment, 100 repeated checks/duplicate callbacks, stale Payment
identity imports, mode-bound CLI confirmation and structural/readiness rollback.

Final isolated SQLite run: **130 tests / 1,807 assertions passed** across that file,
PaymentEventFrequencyTest, PaymentPersistenceTest, PaymentWebhookFlowTest and
V2PaymentCheckoutTest. PHP lint/Pint and diff checks passed. A broader billing/payments,
checkout and Admin run had 802 passes and two failures: the new timestamp-import
fixture was corrected (mass assignment had ignored its timestamp) and passed on rerun;
the existing V2LaunchPricingMigrationTest API-scope fixture still fails at line 208.
That separate failure reproduces with all 12 changed application classes loaded from
unchanged HEAD: a loaded Pro relation is not persisted eligible access. No authorization
policy or unrelated fixture was changed to conceal it. Native MySQL/RDS transaction,
JSON storage, locking and actual deployment acceptance remain unverified. No application
database cutover/migration, production access, FIB call or deployment was performed.

### Review and execution contract (operator-only; not executed here)

Configure the existing disabled-by-default `BILLING_CUTOVER_*` identity, Admin, backup
and restore assertions for the specifically approved target; never guess a DB identity.
A read-only preliminary review uses:

```sh
php artisan billing:cutover-reset-payment-domain --target=local-rehearsal --mode=full-local-reset --dry-run --admin="$ADMIN_ID"
```

Use `--target=production` only under separately approved production operations. Require
zero **structural/readiness** blockers, all effective plans Free, preserved balances,
and all five deletion counts reviewed. Under maintenance and stopped writers, obtain
a fresh review immediately before any independently authorized execution. The existing
`--execute --review-hash=... --admin=... --reason=... --workers-stopped --backup-confirmed
--restore-confirmed` options are all required. Exact confirmation is
`RESET-V2-BILLING-DOMAIN-ALL-CUSTOMERS-FREE` for local-rehearsal or
`RESET-V2-PRODUCTION-BILLING-DOMAIN-ALL-CUSTOMERS-FREE` for production. The command does
not take backups, validate an external restore, stop workers or enter maintenance.
No source change or passing SQLite test authorizes execution; native MySQL/RDS
transaction/locking and deployment acceptance remain outstanding.

## Batch provider review — 2026-09-30

[Batch review](PROVIDER-OBLIGATION-BATCH-REVIEW.md) now provides grouped private
manifests, bounded GET-first remote retirement (maximum 25 objects), exceptional merchant attestations and
atomic per-customer coverage approvals under one durable Admin operation. The new
`provider_obligation_reviews` table retains logical Payment/event IDs after the
existing approved processing retirement. Only exact unpaid/unbound terminal proof
can clear that object's obligation; newer/stale/failed evidence remains blocked.
The paid-coverage authority and complete zero-blocker cutover preflight are unchanged.
Source migrations and isolated tests are not application execution approval.

## Reviewed provider coverage exception — 2026-09-30

The current release adds `provider_coverage_dispositions`; review its
[operator procedure](PROVIDER-OBLIGATION-REVIEW.md) before cutover. Confirmed cancellation
plus a completed immutable Admin approval can retain the same service/storage
subscription under the explicitly reviewed bounded provider term. The full original
term/provenance snapshot survives, dates are projected only at cutover, and exact
fingerprints/effective-plan identity must verify before commit. This is not a manual
or cash grant, current revenue, or credit-cycle authority. Unapproved/ambiguous paid
coverage and remote obligations still block production.

The final business clarification requires all five processing tables to be empty.
Corroborated fake/manual intent, transaction and order provenance is archived in the
cutover audit; those processing rows are deleted and reviewed nullable order links
are detached with their original mapping retained. This supersedes the earlier
fake-processing-row exception. Immutable ledger/allocation dependencies still block.
Paid dates change only under the explicit coverage disposition above. Local-rehearsal
and production retain their separate identity/preflight policies. Remote cancellation
is a separate, explicitly executed, per-object durable workflow; cutover itself makes
no provider calls. Unconfirmed renewal/coverage still blocks it.


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
