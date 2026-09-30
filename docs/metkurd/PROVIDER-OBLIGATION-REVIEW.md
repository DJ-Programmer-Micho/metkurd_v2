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

Current `SubscriptionCyclePolicy` treats a matched authenticated collection/end pair
as verified paid-through evidence, independently from renewal status. An ACTIVE
status or a coverage-only extension cannot buy another paid cycle. That distinction
is unchanged: this patch exposes the old pair for review without allocating credits.

At minimum, keep subscription 1216 and its existing access intact and retain/review
the full provider-declared November boundary; **do not discard it in favor of
October**. The exact legally/financially paid entitlement is still a provider-review
question, not something this source patch can certify. Date correction is not
performed by cutover or by the new evidence-review command.
When a later GET reports a shorter date, the reviewed context retains the largest
previously observed boundary separately as `retained_active_until`; inventory
includes it so a newer shorter observation cannot erase the outstanding protection.

Current cutover ends provider-derived subscriptions and detaches their Payments;
post-cutover authority requires a current-epoch payment, a genuine existing bounded
local grant, or a real external agreement. It has no approved paid-provider coverage
transfer representation. Re-labeling paid access as a manual grant/cash agreement
would invent provenance and can affect allocations. Consequently this patch keeps
cutover blocked while coverage is current/ambiguous. The narrow audited workflow
below establishes cancellation evidence and preserves dates for review; it does
not bypass that limitation. An immediate cutover needs a separately reviewed
coverage disposition, or must wait until all verified coverage has naturally ended.

## Other blockers

- Payments 159/160: DRAFT, awaiting customer action, no paid/fulfilled/date evidence.
  Payment 160 records `non_cancelable`. Neither means remotely canceled. They remain
  unresolved pending authenticated provider disposition; do not use the paid review
  command or mark them canceled with SQL.
- PaymentIntent 15: paid/fulfilled, `provider=fake`, `payment_method=fake`,
  `is_recurring=1`, `recurring_strategy=manual_renewal`, no schedule reference.
  The broad legacy rule currently labels any recurring intent a provider-disposition
  obligation. This is **not evidence of a FIB schedule**. Its synthetic-looking
  provenance and linked entitlement/order still require operator review; no automatic
  fake-provider exemption or historical rewrite was added.
- The inventory's single unlinked paid plan order is CreditOrder 1, linked to
  PaymentIntent 15, with fake provider/method, and no native Payment link. Manual
  non-revenue orders are excluded by the existing inventory predicate. Confirm its
  origin and retained subscription/credit dependencies through finance review;
  never poll a fake reference, reclassify it as real revenue, or delete it to clear
  the count. These legacy issues are independent of Payment 161's GET-event defect.

All six broad `billing:cutover-inventory` blocker categories still reproduce locally.
That diagnostic inventory and the production cutover manifest have different
purposes; this patch does not silently turn either into a green verdict.

## Operator re-review after deployment

Deployment is not performed by this task. On both approved application nodes, after
deploying the reviewed source and verifying the intended production configuration:

**PRECONDITION:** reviewed production configuration, maintenance/writer controls,
correct release and operator authorization under the final deployment runbook.
**COMMAND — per node:**

```sh
php artisan config:clear
php artisan config:cache
```

**EXPECTED RESULT:** both nodes use the same reviewed configuration.
**STOP IF:** cache creation fails or endpoint/schema/feature assertions differ.

The read-only recognition fix needs **no reconciliation write** for Payment 161's
already matched event. Reinspect the broad inventory and optional targeted preview:

```sh
php artisan billing:cutover-inventory --details
php artisan payments:review-fib-cancellation 161 --customer=1182 \
  --provider-subscription="<REVIEWED_SUBSCRIPTION_ID>" --admin=1 --dry-run
```

The targeted preview requires fresh active finance and reconcile capabilities and
makes no provider call. Success means the preview ran, not that cutover is eligible.
Local copied Admin 1 lacks capabilities; its preview was correctly refused. No
capabilities were provisioned by this task.

If the operator requires fresh canonical confirmation (for changed/stale evidence),
the new narrow workflow uses `AdminOperationRunner` with immutable payment/customer/
provider identity, Admin, reason and UUID. It performs authenticated GET only,
validates CANCELLED and timestamps, records canonical confirmation and safe audit,
disables renewal on linked active rows, and preserves all existing paid/date/history
fields. It never cancels remotely, fulfills, refills, refunds, or transfers coverage.

**PRECONDITION:** explicitly approved provider GET and local evidence writes; active
Admin with `admin.finance` and `admin.reconcile`; privately reviewed exact identities;
before fingerprints/backup; no concurrent lifecycle writer; no unresolved financial
review. An existing completed UUID only replays its result and does not obtain a new
GET. For a new observation use a newly reviewed UUID.
**COMMAND — once on one node, optional:**

```sh
php artisan payments:review-fib-cancellation 161 --customer=1182 \
  --provider-subscription="<REVIEWED_SUBSCRIPTION_ID>" --admin=1 \
  --operation="<NEW_REVIEW_UUID>" \
  --reason="<SPECIFIC_REVIEW_REASON_AT_LEAST_10_CHARACTERS>" --execute
```

**EXPECTED RESULT:** `renewal_stop_confirmed=true`,
`coverage_disposition=operator_review_required`, `cutover_authorized=false`.
Compare before/after: only reviewed Payment metadata/status observation, active
subscription renewal metadata, appended PaymentEvents/Admin audits and the durable
Admin operation may change. Wallets, ledgers, dates, orders, intents, allocations and
ended/superseded subscription history remain unchanged.
**STOP IF:** identity/capability/provider validation fails, GET reports ACTIVE/DRAFT,
dates are missing/malformed, or fingerprints show other changes. Failures may retain
a pending operation and sanitized failure audit. No provider cancellation POST is sent.

Do not use broad `subscriptions:reconcile`, the old no-refill plan-repair command,
or `payments:fib:cancel-subscription --force-current` as substitutes for this review.
The durable-intent cancellation scheduler alone does not discover this legacy case.

Resolve remaining provider/coverage/legacy blockers through reviewed evidence.
Stop all writers again, refresh backup/restore evidence, and obtain the **fresh**
production manifest as the last review step:

```sh
php artisan billing:cutover-reset-payment-domain \
  --target=production \
  --dry-run \
  --admin=1
```

Never reuse the previous hash. Only a fresh manifest with **zero blockers** makes
normal execution eligible, subject to all unchanged final-runbook safeguards.
The known local findings do not meet that condition.

## Verification boundary

Focused tests cover legacy GET recognition, callback/local/POST-only rejection,
identity/money/time conflicts, latest-evidence selection, millisecond UTC/Baghdad
conversion, retained future coverage, read-only review, blocked-hash execution,
operator capabilities, sanitized failure, replay, and financial/date preservation.
Existing cutover identity and recurring lifecycle suites remain in scope.
All ten requested local table counts and SHA256 multiset fingerprints matched
before/after inspection, classification and the refused command preview. Private
fingerprints remain outside the repository. Local inspection and command refusal
used read-only transactions; no corrective
execution occurred on the copied schema because its Admin lacks the required
capabilities. Operator mutation acceptance there/native MySQL remains outstanding.
No migration is required. Provider semantics and live provider acceptance are not
established by mocked GET tests or the local database copy.

Final combined isolated run: **166 tests, 2,458 assertions passed** across
`ProviderObligationEvidenceTest`, `CutoverTargetsTest`, `PaymentDomainCutoverTest`,
`CutoverInventoryTest`, `RecurringActionLifecycleTest` and `RecurringFinancialCycleTest`.
The new file contains 36 cases, including the actual bearer-authenticated HTTP client
with mocked transport and no cancellation POST. Initial fixture failures (guarded
capability assignment and unhydrated model snapshots) were corrected before this
complete rerun. PHP syntax and focused Pint passed for all five PHP files;
documentation links/code fences and whitespace checks passed. No frontend change.

## Files in this follow-up

- `app/Domain/Payments/Support/FibStatusEvidence.php`
- `app/Services/Billing/ProviderSubscriptionCancellation.php`
- `app/Services/Billing/Cutover/ProviderObligationInventory.php`
- `app/Console/Commands/ReviewFibCancellation.php` (new)
- `tests/Feature/Billing/ProviderObligationEvidenceTest.php` (new)
- `docs/metkurd/PROVIDER-OBLIGATION-REVIEW.md` (new)
- `docs/metkurd/V1-TO-V2-PRODUCTION-RUNBOOK.md`
- `docs/metkurd/PRODUCTION-DEPLOYMENT-FINAL.md` (prior task's new runbook retained;
  this follow-up adds its provider-review warning/link)
- `docs/metkurd/RECURRING-SUBSCRIPTION-LIFECYCLE.md`
- `docs/metkurd/CHANGELOG.md`
