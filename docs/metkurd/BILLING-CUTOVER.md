# Billing cutover — production verification and conditional plan

## Separate local business rehearsal — 2026-09-15

The operator explicitly authorized preparation and dry run of a broader local
business cutover, independent of the historical conservative evidence policy below.
`billing:cutover-reset-payment-domain` is implemented for the designated local copy,
with whole-table preservation checks and a persisted reporting boundary. It requires
operator review before execution and cannot run on production. Read the current
[local runbook](BILLING-DOMAIN-CUTOVER.md). Earlier statements about an unimplemented
destructive business command describe the September 9 audit, not this later source.
Production provider disposition and RDS acceptance remain outstanding.


## Separate reset implementation — 2026-09-14

The subsequently authorized [explicit payment history reset](PAYMENT-HISTORY-RESET.md)
is now implemented as `billing:reset-payment-history`. It automatically retains the
latest active Payment bound to the review hash, preserves financial/normalized state and refuses the entire reset on any
unsafe dependency. It is separate from the broader production cutover proposed
below. The dated statements that a destructive command was unimplemented describe
the September 9 audit stage. Production evidence and native MySQL/RDS acceptance
remain outstanding. No application or production reset was executed during implementation.

## Status — 2026-09-09

**BLOCKED: production identity/access and actual production evidence are not available in this workspace.** The operator reports zero real paid subscribers and approximately 762 fully verified customers. Neither number is independently verified. The configured application is the local copy; its inventory must not populate the production report. No production connection, provider call, archive/export, database mutation, migration or cutover was executed in this task.

The conservative `billing:reset-legacy-payment-history` command remains unchanged, including its refusal of production. The business cutover is a separate operation. The dedicated destructive command is **not implemented**: the operator made preparation conditional on proving there are no legitimate current paid obligations. This document is its proposed contract, not executable authorization or a claim of eligibility.

## Read-only Artisan inventory — implemented 2026-09-09

The operator can now run the implemented inventory against the configured database:

```text
php artisan billing:cutover-inventory
php artisan billing:cutover-inventory --details
```

This command has no execute/reset option. It prints Laravel environment, driver, configured host/port/schema, server version/time/identity, application and database timezone, and Git HEAD if available before billing information. Git HEAD does not include uncommitted source. Loopback, RDS endpoint and other endpoints are labelled distinctly; neither an RDS hostname nor `APP_ENV=production` proves the intended production identity. Credentials are never printed. Confirm the displayed server and schema independently, including any loopback tunnel destination.

`CutoverInventoryReader` uses direct Query Builder SELECTs on one PDO connection, never Eloquent hydration, mutation services, row locks, reconciliation or provider requests. MySQL/MariaDB runs in an explicit repeatable-read **READ ONLY** snapshot, rolled back on exit. Split/read-write, multi-host and prefixed configurations are refused, as are missing schema, nontransactional tables and a lost snapshot. SQLite is supported only for isolated testing. Query failures produce sanitized failure text and BLOCKED, not invented zero counts.

Default output contains exact inventory/wallet totals, service/storage classifications, row counts and distinct customer counts, payment state counts, legacy-intent/unknown-purchase blockers and informational remaining-credit cohorts. `--details` adds only internal subscription/Payment/customer IDs, plan code, safe status tokens, dates, runtime selection/supersession and classification reasons. It never prints names, emails, raw payloads, free-form reasons or provider identifiers/tokens. Keep even internal-ID reports private.

Classification is a conservative audit projection, not a new entitlement resolver:

- Runtime selection follows `CustomerBillingStateService`'s latest eligible active row and existing default Free plan ordering. Supersession/newer-row facts are read without invoking the locking cycle policy. Free fallback counts are separate from stored Free rows.
- Explicit provider-free manual sources with renewal off and a known valid local boundary are complimentary. Existing manual `period_ends_at`, `cycle_ends_on` and `next_renewal_on` term hints can establish that local boundary; they never establish provider coverage. Invalid/missing terms or a runtime/supersession conflict remain Unknown.
- The audit validates the existing Phase 1/2 `meta.verified_subscription_collection` descriptor against the FIB object, customer, plan, paid/fulfilled state, stored collection time and paid-through timestamp, using the shared strict timestamp parser. Future collection timestamps are invalid. Unexpired verified terms block even if superseded. Elapsed verified terms are stale only with a persisted closed provider state; ACTIVE/missing provider state remains Unknown.
- This initial command deliberately does **not** promote legacy `status_response` payloads to verified receipts or invoke `SubscriptionCyclePolicy` (which also contains locking/mutation-path policy). Legacy-only evidence, one-time plan purchases and other provider contracts without a validated durable descriptor remain reviewable Unknown, not proof of expiry. The operator may supply further trusted evidence; do not weaken the verdict to make old rows pass.
- Missing/mismatched receipt, active-until or ownership is Unknown. Pending/review/paid-unfulfilled Payments, detached provider/paid-plan records, unresolved or paid legacy intents/schedules, and detached paid plan CreditOrders block. Manual/non-revenue historical orders are excluded from sales cohorts. Add-on purchase history with remaining balances is informational and does not itself block.

Exit 0: `PASS — no confirmed current paid obligations found`.
Exit 1: `BLOCKED — current/unknown paid obligations require review` (including incomplete inventory).
PASS concerns persisted obligation evidence only; it is not production identity confirmation, archive/dependency acceptance, provider-state verification or permission to delete.

The command was executed read-only against the configured **local MariaDB** copy and returned BLOCKED. This is local command/runtime evidence only; no production inventory, FIB call, migration, subscription/wallet mutation or feature-gate change occurred. The separate destructive command remains unimplemented and the conservative legacy command is unchanged.

Verification: **24 focused inventory tests passed (164 assertions)**, including storage, missing schema/evidence, detached purchases, manual calendar terms, redaction and a SELECT-only query trace with unchanged whole-table fingerprints and no HTTP calls. **47 existing cleanup/complimentary/recurring tests passed (327 assertions)** in the regression run; the new manual-date fixture exposed SQLite date serialization and was corrected/retested in the focused suite. Syntax and focused Pint pass for all three new PHP files. A broader dirty-file formatting check also flagged a pre-existing line-ending issue in `BillingPageTest.php`; that unrelated file was not changed. Native MySQL/RDS and actual production execution remain unverified.

## Production evidence collection

Use an operator-confirmed production read-only account, preferably with database-enforced SELECT-only privileges. Supply connection secrets through the operator's secure configuration, never chat, source, command arguments or documentation. Confirm the intended RDS instance and schema with the operator. Capture `SELECT VERSION()`, `DATABASE()`, server identity and time, and compare them with the deployed Laravel environment, resolved connection host/port/schema and code revision. A hostname or production-looking environment label alone is insufficient. The intended platform is Amazon RDS MySQL; a local copy or replica with unmeasured lag cannot prove current primary state.

The initial aggregate inventory is [BILLING-CUTOVER-INVENTORY.sql](BILLING-CUTOVER-INVENTORY.sql). It uses a repeatable-read, read-only transaction, reads no full provider payloads/customer content, and performs no DML. It has not been executed against production or native MySQL. It is a first collection pass, **not the effective-obligations classifier or a deletion review hash**. Missing schema, query errors or nontransactional tables make the corresponding evidence unavailable; never substitute zero or run migrations to make the report pass.

Once the operator has configured a secure MySQL login path and confirmed the schema, run from the repository root (replace `CONFIRMED_PRODUCTION_SCHEMA`):

```text
mysql --login-path=metkurd-production-audit --database=CONFIRMED_PRODUCTION_SCHEMA --batch --execute="source docs/metkurd/BILLING-CUTOVER-INVENTORY.sql"
```

Do not use `--force`. Retain the complete output privately and return only sanitized evidence. Check all queries completed through `ROLLBACK`; any incomplete output is not acceptance. Do not run this against the local copy and label it production.

The full subsequent report must include exact counts for customers and dual-verified customers, all five payment-domain tables, CreditOrders, coupon redemptions, both normalized subscription tables and allocations. Also capture preservation counts and canonical whole-row SHA256 fingerprints for customers/verification, both wallets, ledgers, jobs, files, usage, plans, prices, entitlements, monthly grants and existing Admin audit rows. Counts alone do not prove financial preservation. Include column order, stable primary-key row order, null/type encoding, application timezone, collection instant and code/schema version in the fingerprint specification.

## Effective obligations — required second pass

Record a private per-customer service and storage matrix: normalized row ID, plan ID/code, source/strategy, dates, cancellation/auto-renew, supersession, linked Payment identity, fulfillment, provider status, stored evidence validation result, verified paid-through boundary, selected runtime row and classification/reason. Do not put raw provider references or customer identifiers in the public report; retain a secure mapping for review. Inspect all open/future paid terms and pending paid purchases, including superseded rows; a current Free/manual plan cannot prove an older external obligation ended.

Current source contracts to compare with the deployed version:

- `CustomerBillingStateService::resolveActiveServiceSubscription` and `resolveActiveStorageSubscription` select the latest eligible active row with started/non-ended dates. They fall back through default-plan lookup for the display state. These methods only read; do not invoke normalization or reconciliation during an audit.
- `Customer::currentServicePlan` / `currentStoragePlan` also consume loaded relations and `active*Subscription()->latestOfMany()`. Compare fresh actual resolver outputs, not a SQL approximation or cached loaded plan. The relationship and billing-state resolver have different query shapes; any disagreement must be reported, not silently normalized.
- `SubscriptionCyclePolicy::isCurrent` separately rejects superseded rows and any newer normalized row. It uses `lockForUpdate`; do not invoke this method in the production read-only audit. Inspect the same newer-row/supersession facts using nonlocking snapshot reads.
- `SubscriptionCyclePolicy::verifiedCollection` checks matched persisted collection evidence, paid/fulfilled state and collection/boundary timestamps without contacting FIB. Verify customer/plan/provider-object bindings too. `boundary` reads persisted term fields; a date from this method alone is not proof it came from authenticated evidence. Missing receipt/coverage, contradictory metadata and invalid dates remain unknown.

Classify independently for service and storage, then deduplicate customers:

| Class | Required basis | Cutover consequence |
|---|---|---|
| A — legitimate paid obligation | Matched paid/fulfilled evidence and unexpired access, prepaid/future paid term or outstanding paid-but-unfulfilled purchase | Block; do not delete or downgrade |
| B — historical/stale paid-looking | Evidence establishes ended obligation, supersession disposition and no remaining prepaid/fulfillment/refund/review obligation | Candidate for explicit historical normalization, not automatic deletion |
| C — complimentary/manual | Confirmed manual/non-revenue origin and valid local term; source/flags, reason/operator and absence of conflicting sale/provider evidence | Preserve valid access and credit policy |
| D — Free | Actual runtime Free resolution with no other unresolved obligation | Preserve/ensure explicit Free state if needed |
| Unknown | Missing coverage, conflicting binding, unresolved purchase/refund, ambiguous manual source or provider state | Block; never count as B or zero paid |

`status='active'`, `hasPaidServicePlan`, dashboard counts and the presence/absence of provider references are not final obligation evidence. Cancellation does not erase an unexpired paid term. A missing local boundary cannot prove the remote subscription is no longer collecting. With FIB contact prohibited, unresolved cases require trusted existing operator evidence; this task cannot manufacture it.

## Credit dependency and CreditOrder disposition

Capture App/API totals and subscription/add-on buckets separately, and verify total equals the two buckets per wallet. Report distinct customers with confirmed paid purchases and nonzero balances, paid purchases with no currently resolved service subscription, with no storage subscription, and with neither; distinguish Free fallback from an explicit active row. Report add-on purchase customers separately, including ambiguous missing product IDs. The initial SQL cohort intentionally includes paid-looking/manual records until classification; it is not the confirmed-paid cohort. Join order, Payment, intent, ledger polymorphic links and JSON identifiers privately. Aggregate balances cannot establish exact unspent purchase provenance.

**Preserve every wallet balance, lifetime counter and ledger row.** No zeroing, rebucketing, expiry credit adjustment or replayed grant is part of this cutover.

| CreditOrder class | Proposed disposition |
|---|---|
| Old provider service/storage sale | Retain row/ID and sale evidence by default; archive its parent payment evidence. Archiving/removing the sale itself requires a separate explicit operator choice and complete soft-link review. |
| Add-on purchase | Retain row/ID, credit product link and ledger provenance, even when currently unspent attribution is uncertain. Archive parent evidence; do not erase remaining add-ons. |
| Historical manual/non-revenue order | Retain and classify as non-revenue in the cutover report, never count as a real sale solely because status is paid. Existing incorrect/missing reporting flags are not silently rewritten. If clean revenue reporting requires exclusion, approve that exact reporting disposition before implementation. |
| New complimentary grant | No CreditOrder exists by design; no deletion or conversion. |
| Unclassified/refunded/adjustment/compatibility record | Retain pending explicit evidence review; do not sweep into provider sales. |

Keeping CreditOrders means a clean **payment-processing** baseline does not mean zero historical sales/revenue. Decide explicitly if the operator also requires a revenue-reporting cutoff; this task does not silently change accounting reports or prices.

## Exact proposed reset boundary

Only the reviewed pre-cutoff IDs of `payment_events`, `payment_transactions`, `payment_webhook_events`, `payment_intents` and `payments` are deletion candidates. Include unlinked old events only after classification and archive. No automatic whole-table truncate or age-only selection. Every unresolved/review/paid-but-unfulfilled record needs a documented disposition before eligibility; no force override may erase a genuine obligation.

Discover actual production FKs and logical/polymorphic/JSON links. Current migrations show Payment children in events, CreditOrders, service/storage subscriptions, coupon redemptions, allocations and ad conversions; intent children in transactions, webhook events and CreditOrders; transaction self-parent links also require ordering. Unexpected dependencies block the manifest.

Proposed retained-child handling, only after the exact rows are approved:

- Preserve CreditOrders, coupons/redemption usage and conversion delivery/deduplication history. Archive original parent links; explicitly detach only nullable Payment/intent FKs needed for the reviewed parent deletion. Do not reset coupon limits, resend conversions or broadly clear provider/customer payment-method tokens. The archive maps retained table/row/column IDs to original parent identities.
- Preserve normalized subscription IDs/history. Archive original payment links and FIB metadata before removing operational references from the specifically retired rows. Retain source and historic dates/evidence in the archive, with a non-operational cutover classification/mapping. No fabricated provider cancellation/collection status.
- Leave ledger polymorphic IDs and ledger JSON unchanged. Preserve referenced CreditOrder IDs and archive payment evidence so those immutable links remain interpretable. Do not reuse deleted IDs or reset auto-increment counters.
- `subscription_credit_allocations` is **never deleted or fabricated**. A referenced allocation blocks parent deletion; an expected zero is valid only if the actual pre-cutoff table is empty. Missing table is not zero. New rows or claims after review invalidate it.

Child-first transaction order: approved nullable link detachment and subscription normalization; old payment events/webhooks; transactions in descendant-before-ancestor order; Payments and then intents after all actual dependencies are cleared. Check self-reference cycles explicitly. No `FOREIGN_KEY_CHECKS=0`, no cascade assumptions. Recheck all preserved identities/counts/fingerprints and record a sanitized Admin audit entry within the transaction; failure rolls everything back. Existing audit entries are unchanged, but total audit count increases by the new cutover entry.

## Subscription normalization

Review an exact before/after list for both subscription domains. Preserve valid complimentary/manual rows, their expiry and allowance policy. End only proven stale provider rows, disable their local renewal, retire operational references and record a cutover reason/manifest reference without pretending FIB was canceled. Retain their historical row and provenance. Prevent any older stale row from becoming effective when the current row changes.

For a customer with no valid manual plan, use the existing canonical Free plan and create/ensure one explicit normalized Free row if required. Do not create new catalog plans. Do not invoke `PlanSwitcher`, grant services, refill or fulfillment because they can allocate credits/create sales. `ExpireSubscription` alone is not the cutover operation: it requires a current row and known boundary, does not retire the complete provider metadata, and may create Free without the full retained-manual/dependency review. No general subscription deletion or historical allocation backfill.

For storage, calculate over-quota consequences before a Free downgrade. Preserve files and usage; no destructive storage operation. A reduced quota can legitimately block future uploads, so show impacted customer counts explicitly in the reviewed plan. Verify both runtime resolvers after the proposed changes on an isolated rehearsal before production execution.

## External and in-flight boundary

Stop billing entry points, workers, scheduler and callback processing for the final snapshot/archive/review. Maintenance mode alone does not establish that all callback/API/worker paths are quiescent. Inventory queued, delayed, reserved and failed billing jobs and old checkout links; quarantine only the reviewed old billing work with a recovery record, never purge unrelated product jobs. Inspect legacy webhook/reconciliation routes as well as native Payment callbacks.

Current `FibSubscriptionCallbackController` records receipts and orphan events even when no local Payment matches; `ProcessFibPaymentStatus` also records unmatched work. Thus events can reappear after reopening even if all five tables are zero at cutover. Unmatched callbacks do not by themselves create a paid subscription, but deleting local records does not cancel remote objects or prove that remote charges stopped.

Before promising a permanently clean event domain, settle a narrowly scoped retired-reference policy (secure tombstone/ingress quarantine with no provider calls and no replay into new purchases). That policy is not implemented here. If the operator accepts ordinary orphan operational events after reopening instead, document that zero events is a cutover-time assertion only. Neither option may silently discard notifications of a legitimate unknown payment. Preserve archived identifiers and never rebind/reuse them for new checkouts.

## Archive and execution guards

Under the same final quiescent boundary, take a normal recoverable production backup and a separate restricted archive of the five full payment-domain tables, original dependent subscription/order/coupon/conversion links and relevant financial provenance. Raw payloads may contain sensitive financial data: store encrypted outside the repository/public storage, with restricted operator access and a retention owner. Do not redact the authoritative private backup destructively; sanitize only reports.

Manifest: UTC timestamp, exact confirmed environment/endpoint/port/schema/server identity and version, deployed revision/migration state, exclusive cutoff/timezone, table counts, exact deletion/normalization/retained-link IDs, canonical row hashes and export-file SHA256s, preservation fingerprints, classification reasons, operator identity and archive location reference. Bind the final reviewed hash to this complete manifest, not counts alone. Verify file hashes, completeness and restore readability on an isolated restore before destructive execution. No production archive was created in this task.

Future command must default to dry-run and require an exact configured production identity plus a reviewed manifest. Execution additionally requires maintenance; stopped workers **and scheduler**; verified callback/write quiescence; backup/archive acknowledgement; fresh active-admin `admin.finance` and `admin.reconcile`; explicit reason/confirmation; matching full review hash; exact previewed counts; and transactional storage. Lock/re-read affected and protected state under the established customer-first ordering, check FK graph and full-content drift, and refuse any new writes/claims or changed evidence. Do not rely on a consistent snapshot alone to prevent concurrent writes. Any unsupported cross-table/FK/quiescence guarantee blocks execution.

Proposed future dry-run invocation — **not installed; do not run yet**:

```text
php artisan billing:cutover-reset-payment-domain --dry-run --manifest="PRIVATE_REVIEWED_MANIFEST.json"
```

The manifest would contain the exact identity, cutoff and explicit order/link/normalization policy, with no credential material. No destructive invocation is provided before production eligibility and reviewed implementation.

## Conditional expected state and future lifecycle

- Five payment-domain tables: zero **only if** the final manifest includes every row and no new processing occurs. Otherwise show exact retained/new counts rather than claim zero.
- Retired live operational subscription references: zero in the approved scope; archived references retained. Remote FIB objects remain untouched and their actual status must be established from trusted evidence.
- Allocations: unchanged; zero only if verified empty initially. Never delete later initial or renewal claims.
- Customers/verification, wallets/lifetime/buckets, ledgers, jobs, files, usage, plans/pricing/entitlements and valid manual grants: unchanged full-row fingerprints. Subscriptions and nullable dependent links: exactly the approved diff, with history preserved. No stale paid-provider plan remains effective.
- New checkout creates a pending local Payment first, then authenticated matched provider evidence permits fulfillment, normalized subscription and initial allocation. Verified collection uses a durable cycle claim for one App/API renewal. Cancellation retains paid access until the verified boundary then Free. Complimentary grants create only audited local access/credits with retained add-ons and no sale. Phase 1/2 source/tests cover these contracts; they are not evidence of production deployment/provider acceptance.
- Provider-create ambiguity (B15) and real MySQL concurrent acceptance remain separately documented limitations; deletion does not resolve them. Do not initiate a blind new provider checkout to repair an ambiguous old purchase.

## Verification record

The initial planning task changed only the SQL artifact and documentation. The subsequent operator-authorized follow-up adds the read-only Artisan inventory described above, with no destructive command or customer behavior change. Production counts, obligation classes, wallet provenance, exact row disposition, archive hashes and post-cutover values remain **unverified** pending secure production evidence. Existing local inventory is intentionally not repeated as production evidence.

Current-source regression: **47 tests passed, 327 assertions** across `LegacyPaymentHistoryResetTest`, `ComplimentaryPlanGrantTest` and `RecurringFinancialCycleTest`, using isolated SQLite fixtures, array cache/session and mocked provider traffic. These prove the existing conservative guards, complimentary/non-revenue behavior and recurring replay/expiry contracts remain passing; they do not prove a future cutover implementation or native MySQL/production acceptance. No PHP/frontend code changed, so no asset build or PHP formatting changes were needed.
