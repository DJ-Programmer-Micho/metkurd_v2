# Production cutover provider evidence review — 2026-09-30

## Scope and local findings

Source correction, not deployment or cutover approval. The explicitly authorized
local copy `metkurd_local_260930` was inspected using loopback PDO read-only
transactions. It runs MariaDB 10.4.28, not production MySQL 8.4.8. No production
connection, provider call, migration, cutover, capability grant or SQL repair ran.
The production identity policy was not relaxed to run on this local copy.

Payment 161 is paid/applied and owns active service subscription 1216. Both payment
date columns (`active_until`, `last_payment_at`) and the Payment-level canonical
`meta.provider_cancellation` context are absent. The subscription has an older
request-only cancellation descriptor, `auto_renew=0`, and an October end date.
`ProviderSubscriptionCancellation` is a service writing JSON context and events;
there is no missing cancellation-table migration to apply.

Event 216568 is `provider_status_ignored`, source `fib_subscription_callback`.
Its event identity, merchant reference, subscription identity, raw payload and
processed timestamp match the Payment's latest persisted status response/check.
The source path obtains that payload from authenticated subscription GET; callback
payloads are separately recorded. “Ignored” means the paid Payment cannot transition
to canceled, **not** that GET evidence was rejected. Event 216563, at fulfillment,
also contains the same collection and November coverage timestamps.

The inventory previously required the newer canonical cancellation context before
it would validate stored status evidence. Legacy GET events without that context
were therefore missed. Ordinary cancellation recovery selects pending canonical
intents and cannot backfill this legacy row merely by running the scheduler.

## Shared evidence authority

`FibStatusEvidence::persistedSubscriptionObservation()` reads only the **latest**
`provider_status_checked` / `provider_status_ignored` event. It requires matching
Payment/provider/object/merchant identities, the current stored raw response,
matching nonfuture check/processed timestamps, and existing `FibStatusEvidence`
identity, optional money/reference and strict timestamp validation. Newer callbacks,
newer invalid observations, or a newer cancellation request prevent fallback to old
evidence. Callback-only status, local CANCELLED, request/POST acceptance, missing
objects and mismatched/malformed observations are not confirmations.

`ProviderSubscriptionCancellation::confirmation()` is the shared read-only renewal
authority for the inventory and operator preview. It accepts the existing validated
canonical lifecycle context or the matched GET-event path. These are application
provenance checks, not cryptographic attestations of database contents: do not edit
or manufacture metadata/events, and use a fresh authenticated GET if provenance is
in doubt. Dry-run never writes a backfill and never contacts FIB.

The local Payment 161 projection is now:

```json
{
  "classification": "valid_coverage_to_preserve",
  "reason": "confirmed_renewal_stop_with_remaining_coverage",
  "cancellation_evidence": {
    "confirmed": true,
    "evidence_event_id": 216568,
    "observed_active_until": "2026-11-29T16:42:15.998+00:00",
    "observed_last_payment_at": "2026-09-29T16:42:15.998+00:00"
  }
}
```

The offset follows configured application time zone; the instants are identical.
This is a **blocking coverage classification**, not authorization to delete Payment
161. The local subscription coverage blocker also remains. Local inspection of the
production obligation projection is not a production-target manifest: the actual
production dry-run still requires RDS identity, migration/Admin and backup checks.

## Date discrepancy and preservation

Unix milliseconds decode exactly to:

| Field | UTC | Baghdad |
| --- | --- | --- |
| lastPaymentAt | 2026-09-29 16:42:15.998 | 2026-09-29 19:42:15.998 |
| activeUntil | 2026-11-29 16:42:15.998 | 2026-11-29 19:42:15.998 |
| Cancellation event's local period_ends_at | 2026-10-29 16:42:26 | 2026-10-29 19:42:26 |

The older DTO in repository revision `142f888` used `Carbon::parse()` on the
millisecond integer converted to a string. Reproduction rejects that value, leaving
null date columns. Its PlanSwitcher fallback used `now()->addMonth()` for a monthly
plan. That explains the observed locally computed October boundary; the current
strict millisecond parser already fixes new observations and does not rewrite old
rows. The old cancellation writer stored request metadata on the subscription,
not the newer confirmed Payment context. The exact deployed historical SHA was
not independently obtained; this is source-history plus persisted-evidence diagnosis.

The raw SQL date columns also differ from the offset-bearing event timestamps;
do not repair historical time zones or infer instants from a dump's display alone.
Use the explicit event offsets and provider milliseconds for this comparison.

The GET says P1M but its start/end span two months. The same November boundary was
present before cancellation, so cancellation did not introduce it. Public FIB
subscription [documentation examples](https://gist.github.com/first-iraqi-bank-bot/3e78260f90b143d0c5b853685ca0fb01)
expose these fields but do not establish why this particular
account has two months of coverage. The documented subscription collection was not
retrievable during this review. Obtain provider/merchant evidence before claiming
that interval means two collections or changing entitlements. No additional
collection, refund, allowance, or V2 revenue is inferred.

## Operator-approved retained coverage (implemented 2026-09-30)

`provider_coverage_dispositions` is a first-class pre-V2 paid-provider authority.
It is neither a manual grant, agreement nor new Payment. The additive migration is
`2026_09_30_000001_create_provider_coverage_dispositions.php`. It has NOT been applied
to the local reproduction database or production in this task. Native MySQL migration,
transaction/JSON/date and two-node acceptance remain operator prerequisites.

The record retains customer, original Payment/provider/subscription identity, typed
service/storage subscription and plan, explicit start/end, cancellation event,
renewal-stop confirmation, review reference, reason, Admin, durable operation UUID,
source fingerprint and a limited original payment/subscription/evidence snapshot.
Historical Payment/event IDs deliberately have no foreign keys to retired processing
rows. Customer/Admin/operation links restrict deletion; populated migration rollback
is refused. No generic edit form, manual-status shortcut or force switch is provided.

`billing:disposition-provider-coverage` defaults to read-only review. Both review and
execution require an active Admin with finance AND reconcile, every exact identity,
explicit dates, event ID, reason, UUID and merchant/provider-review attestation.
It reuses `AdminOperationRunner`: identical UUID/request replays the result; changed
payload/reason/Admin conflicts; a second operation cannot replace an existing approval.
Failed operations can retain a pending intent and sanitized failure audit, but no
financial fulfillment. No HTTP, cancellation POST, refund, Payment creation, credit
allocation or source Payment/subscription/date change occurs on approval.

This initial workflow accepts only an active, latest, owned FIB service/storage
subscription with a paid/applied Payment and a matched authenticated cancellation
GET event. The explicitly approved interval must equal that event's collection/end
pair, include a nonfuture start and future end, and not shorten a longer independent
known term. It does not automatically certify that pair. Callback-only/local-only
CANCELLED, ACTIVE/DRAFT, identity conflicts, missing/malformed dates, unpaid/review
states, missing operator confirmation and stale evidence are refused. If merchant
review establishes a different interval, STOP: this workflow cannot invent or override
provider evidence. A separately reviewed evidence correction is required.

Approval status is `approved`, with no replacement runtime authority yet. At cutover,
source fingerprints and the completed audited approval are revalidated. Only a valid
approval resolves the corresponding coverage/renewal blocker. The same subscription
ID, customer, plan and FIB provenance survive. Cutover detaches its original Payment,
sets renewal off, retains active status and projects the explicitly approved interval;
the snapshot preserves its old dates. Status becomes `retained`, and the committed
cutover boundary records its ID. Full-table expected-after fingerprints AND effective
plan identity are verified before commit. Any mismatch rolls back everything.
Unapproved provider subscriptions keep the existing conservative retirement policy;
production still refuses unresolved remote obligations and coverage.

`BillingSubscriptionAuthority` accepts only the audited record bound to the same
customer/subscription/plan/provider and listed in the committed cutover boundary.
Access is start-inclusive/end-exclusive. ISO dates preserve milliseconds; the existing
second-precision subscription columns use a floored start and ceiled end only as coarse
SQL filters. The authority checks the exact approved instant, so that rounding never
extends access. `SubscriptionCyclePolicy::boundary()` returns the exact approved end,
not the older October metadata. Effective subscription status/supersession still apply.
Expiry returns normal eligible-plan/Free fallback without resurrecting provider terms.

CustomerBillingStateService, customer plan relations, plan concurrency, App/API and
MCP eligibility therefore share the existing resolver. API scopes, entitlements,
wallets, feature gates and plan activation still apply separately. Retained coverage
has no lifecycle/allocation authority: no future provider cycle, monthly refill, new
Payment, current-epoch sale, add-on reset or cancellation call is generated. Existing
wallet, ledger, order and allocation rows are fingerprint-preserved. An immutable
allocation FK into a Payment selected for retirement STILL blocks cutover; disposition
does not detach that evidence or weaken existing preservation rules.

Existing Admin billing evidence displays read-only EN/AR/KU labels for Legacy Provider
Coverage, renewal stopped, coverage end, original Payment/event IDs and pre-V2 financial
provenance, explicitly not current V2 revenue. It distinguishes approval from retained
status and does not display raw payloads, provider references, reason or review notes.
Existing evidence capability gates remain enforced.

## Payments 159/160: still unresolved

Both are unpaid DRAFT/awaiting customer action; 160 also records `non_cancelable`.
Neither is cancellation. Public FIB examples describe `expiresIn`/`validUntil` as the
activation/QR window, but do not prove that an expired window plus DRAFT makes the
remote subscription permanently incapable of activation/collection. Existing
`AbandonedCheckoutEligibility` also refuses to infer remote retirement from a local
checkout deadline. No broader DRAFT exemption was added.

Required provider action: obtain a merchant/FIB disposition for each exact remote
object confirming it cannot activate or collect again; where supported, have FIB
close/cancel it and obtain matched authenticated terminal GET evidence. If FIB only
returns DRAFT/non_cancelable, keep blocked and obtain documented irreversible expiry
semantics before proposing a separate narrowly evidenced rule. Do not send a blind
cancel POST, use force-current, or set CANCELLED with SQL.

## Intent 15 / Order 1: retained financial history

Read-only inspection confirms the source contract of `FakePaymentProvider`: fake
provider AND method, paid/fulfilled, manual renewal, known FAKE payment/transaction
identity shapes, `response_payload.provider=fake`, `mode=instant_fake`, and no schedule,
remote customer/purchase or saved-method identity. `LegacyFakeIntentEvidence` requires
that narrow conjunction. A recurring flag alone is no longer treated as proof of a
remote obligation in this case. Conflicting remote identities or missing corroboration
remain unresolved. This is not reclassification as FIB revenue or an entitlement grant.

Cutover preserves matching intents and linked transactions/webhook evidence unchanged,
and leaves Order 1's intent FK intact. Any retained processing child referencing a
parent selected for retirement blocks the transaction. Self-referencing transaction
delete ordering respects the retained subset. The broad inventory excludes only a
customer/plan/provider/method-matched Order from its unlinked remote-plan count and
reports retained financial legacy IDs separately. Other historical financial reads
and immutable dependencies remain unchanged; reporting watermarks exclude these old
orders from current V2 revenue. No fake identity is ever polled.

## Exact operator sequence after separately approved deployment

No commands below were executed against production. Obtain native MySQL acceptance,
backup/restore evidence, migration approval and correct release/configuration on both
nodes under [the final runbook](PRODUCTION-DEPLOYMENT-FINAL.md). Apply the new additive
migration only under that approved deployment procedure; do not seed or repair tables.
The production migration preflight will block until its inventory matches the release.

1. Review the full November interval with FIB/merchant; retain the review externally.
   Approval is NOT inferred from Event 216568. Stop if the discrepancy is unresolved.
2. Stop lifecycle/callback/payment writers, take before fingerprints of the ten listed
   tables, and confirm Admin 1 has fresh finance/reconcile authority (the final cutover
   requires all Admin capabilities). Do not provision privileges automatically.
3. Optionally obtain fresh GET evidence via the separately authorized
   `payments:review-fib-cancellation` workflow if existing provenance is stale. This
   changes the evidence identity/fingerprint; review it BEFORE disposition. No GET is
   necessary merely to recognize already accepted Event 216568.
4. Fill these non-secret review identifiers privately. Keep the UUID/reason/arguments
   exactly identical between preview, execute and retry. The following dates are only
   appropriate IF the entire November interval has been explicitly confirmed.

```sh
DISPOSITION_OPERATION="<ONE_NEW_DURABLE_UUID>"
FIB_SUBSCRIPTION="<EXACT_REVIEWED_PAYMENT_161_SUBSCRIPTION_ID>"
REVIEW_REFERENCE="<MERCHANT_PROVIDER_REVIEW_REFERENCE>"
REASON="<SPECIFIC_REASON_CONFIRMING_THE_REVIEWED_FULL_INTERVAL>"

php artisan billing:disposition-provider-coverage 161 --customer=1182 \
  --provider-subscription="$FIB_SUBSCRIPTION" --subscription-kind=service --subscription=1216 \
  --coverage-start="2026-09-29T19:42:15.998+03:00" \
  --coverage-end="2026-11-29T19:42:15.998+03:00" --evidence-event=216568 \
  --coverage-confirmed --review-reference="$REVIEW_REFERENCE" --admin=1 \
  --operation="$DISPOSITION_OPERATION" --reason="$REASON" --dry-run

php artisan billing:disposition-provider-coverage 161 --customer=1182 \
  --provider-subscription="$FIB_SUBSCRIPTION" --subscription-kind=service --subscription=1216 \
  --coverage-start="2026-09-29T19:42:15.998+03:00" \
  --coverage-end="2026-11-29T19:42:15.998+03:00" --evidence-event=216568 \
  --coverage-confirmed --review-reference="$REVIEW_REFERENCE" --admin=1 \
  --operation="$DISPOSITION_OPERATION" --reason="$REASON" --execute
```

Expected: one approved disposition, one completed Admin operation and approval audit;
original payment/event/subscription/order/wallet/ledger/allocation rows unchanged.
Audit fingerprints necessarily change by the new approval event only. Verify the
new record, operation and audit together. Dry-run must leave all fingerprints unchanged.
Stop on any mismatch, stale evidence, different identities, missing capabilities or
unresolved dates. No automatic amendment/overwrite exists; an invalidated approval
requires separate reviewed recovery, never SQL edits to make it pass.

5. Resolve 159/160 and every other provider/dependency blocker independently. Keep
   Intent 15/Order 1 and their immutable history. Refresh backup/restore attestations
   and stop all writers before the final production manifest. End with:

```sh
php artisan billing:cutover-reset-payment-domain \
  --target=production \
  --dry-run \
  --admin=1
```

Only **ZERO blockers**, a fresh matching hash and all unchanged final-runbook controls
make normal execute eligible. Approval of Payment 161 alone never authorizes cutover.
Do not reuse an earlier hash. After a separately authorized cutover, verify retained
coverage, expiry, no new refills/current revenue, and the expected fingerprint patches.
Do not roll back to old code that cannot recognize the retained authority.

## Verification and local acceptance boundary

The local copied schema remains read-only and has no new disposition table/approval.
Thus Payment 161 and its subscription still correctly require disposition; 159/160
remain unresolved. Intent 15/Order 1 now classify as retained financial history.
The broad inventory still reports unresolved payments, service current/unknown
obligations, storage current/unknown obligations and unlinked provider evidence;
its separate intent/order categories are resolved by classification, not deletion.
This diagnostic projection is not a native-production cutover manifest. Local MariaDB,
missing migration and copied Admin capabilities cannot satisfy production acceptance.

Isolated fixtures exercise approval/replay/capabilities, unsafe evidence, exact expiry,
service/storage authority, MCP/concurrency, no recurring allocation, preserved fake
history, read-only review, blocked manifests and transactional preservation rollback.
No provider HTTP, real migration, cutover, feature-gate or privilege change occurred.
Final test totals and local fingerprints are recorded after verification below.


The read-only production-obligation projection contains 1 retired-confirmed item,
2 coverage-to-preserve items, 31 operator-review items and 121 unresolved-remote
items, plus the separately retained fake financial item. These are evidence items,
not distinct customer counts; resolving 161/159/160 alone is not proof of zero blockers.
The full production identity/readiness/manifest must be rerun on the authorized native
engine by the operator; no identity bypass was used on local MariaDB.

Local before/after full-row SHA256 multiset fingerprints matched for all ten tables:

| Table | Before rows | After rows | Fingerprint |
| --- | ---: | ---: | --- |
| credit_wallets | 2330 | 2330 | identical |
| credit_ledgers | 8516 | 8516 | identical |
| customer_service_subscriptions | 1199 | 1199 | identical |
| customer_storage_subscriptions | 1167 | 1167 | identical |
| payments | 161 | 161 | identical |
| payment_events | 39074 | 39074 | identical |
| payment_intents | 1 | 1 | identical |
| credit_orders | 35 | 35 | identical |
| subscription_credit_allocations | 0 | 0 | identical |
| admin_audit_events | 1 | 1 | identical |

Raw fingerprints and reproduction helpers stay in the private local temporary
directory, not source control. Isolated approval tests allow only operation/audit and
disposition creation; isolated cutover tests compare full expected fingerprints,
including existing nonempty ledger/order/allocation history and injected rollback.

## Final source verification — 2026-09-30

- **235 PHP tests / 3,001 assertions passed**: ProviderCoverageDispositionTest
  (44 new cases), ProviderObligationEvidenceTest, PaymentDomainCutoverTest,
  CutoverTargetsTest, CutoverInventoryTest, RecurringActionLifecycleTest,
  RecurringFinancialCycleTest, PlanConcurrencyTest and AdminBillingWorkspaceTest.
- **38 frontend tests passed** across Admin billing workspace, localization and UI.
- Syntax checks and focused Pint passed for all 16 changed non-Blade PHP files.
  UTF-8, Markdown fences and `git diff --check` passed.
- All test databases were isolated SQLite `:memory:` with array cache/session,
  synchronous queue and blocked/mock HTTP. Test fixtures execute migrations and
  cutover only inside that isolated test database. No application-database migration,
  disposition or cutover ran.
- EN/AR/KU evidence was rendered in Blade tests; no live browser or native RDS
  acceptance is claimed. No JS/CSS asset change required a Vite build.
- Native MySQL DDL/FKs/JSON/locking/date behavior, two-node acceptance, real operator
  approval and provider DRAFT retirement remain unaccepted. Production activation
  and cutover are not authorized by these test results.

## Files changed in the disposition follow-up

- `docs/metkurd/ARCHITECTURE.md`
- `app/Console/Commands/DispositionProviderCoverage.php`
- `app/Models/ProviderCoverageDisposition.php`
- `app/Services/Billing/BillingSubscriptionAuthority.php`
- `app/Services/Billing/CustomerBillingStateService.php`
- `app/Services/Billing/Cutover/ProviderObligationInventory.php`
- `app/Services/Billing/CutoverInventoryReader.php`
- `app/Services/Billing/LegacyFakeIntentEvidence.php`
- `app/Services/Billing/PaymentDomainCutover.php`
- `app/Services/Billing/ProviderCoverageDispositions.php`
- `app/Services/Billing/SubscriptionCyclePolicy.php`
- `app/Support/Admin/AdminBillingWorkspace.php`
- `database/migrations/2026_09_30_000001_create_provider_coverage_dispositions.php`
- `docs/metkurd/BILLING-DOMAIN-CUTOVER.md`
- `docs/metkurd/CHANGELOG.md`
- `docs/metkurd/PRODUCTION-DEPLOYMENT-FINAL.md`
- `docs/metkurd/PROVIDER-OBLIGATION-REVIEW.md`
- `docs/metkurd/RECURRING-SUBSCRIPTION-LIFECYCLE.md`
- `docs/metkurd/V1-TO-V2-PRODUCTION-RUNBOOK.md`
- `resources/lang/ar/admin_billing.php`
- `resources/lang/en/admin_billing.php`
- `resources/lang/ku/admin_billing.php`
- `resources/views/components/admin-billing-evidence.blade.php`
- `tests/Feature/Billing/ProviderCoverageDispositionTest.php`
