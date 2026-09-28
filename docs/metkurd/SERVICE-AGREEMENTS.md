# Dated cash / external service agreements

## Agreement-level controls — 2026-09-27

The Admin agreement modal now accepts custom monthly App/API credits, defaulting
to plan snapshots when blank, plus configured concurrency (new default five).
A separate audited adjustment modal affects future unallocated cycles and current
active-agreement concurrency without rewriting wallets or prior allocation history.
Normal plan limits stay unchanged. The nullable concurrency migration is prepared,
not applied to the application database. Read [the control follow-up](ADMIN-CONTROL-CLEANUP.md)
for precise defaults, schema gating, queue behavior and verification limits.


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


Implemented in source on 2026-09-13. This is Admin-authorized, externally collected
commercial access. MetKurd owns the access dates and monthly credit allowance.
Collection schedules, instalments and receipt reconciliation stay in the external
system. An optional agreed IQD total is contract information, **not evidence of
cash received**, and is not included as collected revenue. No FIB Payment,
PaymentEvent, PaymentIntent, CreditOrder or fabricated provider status is created.
Cash is not offered in customer checkout or registered as an online provider.

## Operator workflow

Open Customer Register, verify the numeric customer ID, and use **Cash / external
service agreement**. An active Admin with `admin.finance` selects the plan, start
date, inclusive expiry date, agency/agreement reference, optional agreed total and
mandatory reason. The existing SweetAlert bridge reviews the selected values.
`AdminOperationRunner` binds the immutable intent to the operator, customer and
exact submitted values. Replaying the same intent returns its original result;
changing its inputs requires the existing New correction control. Overlapping
scheduled, active or review-blocked agreements for one customer are rejected.

Example: input `2026-09-13` through `2027-09-03` permits access through September 3,
2027. Dates use the Laravel application timezone. Internally the end is exclusive,
`2027-09-04 00:00:00`; customer date presentation accounts for this boundary.
An expiry already in the past is rejected. A past start is permitted for a current
agreement, but does not backfill access, credits, collections or historical events.
Public plan prices are never changed. The agreed amount need not equal a catalog
interval price. The chosen plan's current App/API monthly allowances are recorded
as the agreement's allowance snapshot.

## Architecture and financial boundaries

`service_plan_agreements` retains the approval, dates, allowance snapshot and
reference independently of `customer_service_subscriptions`. This small scheduling
record avoids making a future agreement the latest/current subscription early.
At activation `ServiceAgreementLifecycle` creates the existing normalized service
subscription with source `admin_cash_agreement`, explicit starts/ends, no automatic
renewal, no payment linkage, and an agreement ID in metadata. Scheduled agreements
appear in their own bounded Admin table; activated subscriptions also appear in
Recent Subscription History. This source is commercial external access, not a
complimentary grant or verified online payment. Missing collection evidence remains
unrecorded; cash collection would require a separate explicit accounting workflow.

A current Free or complimentary subscription may be superseded at activation.
Unresolved service checkout, an active paid plan, retained unretired provider
collection or another future subscription blocks activation for operator review.
There is no automatic provider call, cancellation or review clearance. The existing
payment review controls resolve payment issues separately. Enter a reason and use
Retry activation afterward; it rechecks ownership, authorization and current state.
The scheduler does not automatically retry a review-blocked activation.

A reserved agreement blocks service-plan checkout through the shared final
CheckoutCreationGuard (including V1 callers) and the V2 display. It does not add a
cash choice or disable storage/add-on checkout. Active external agreements cannot
be cancelled through customer renewal controls; customer pages explain external
management. PaymentCheckoutState and online provider evidence rules are unchanged.
Plan deletion and plan-voice deletion guards include retained agreements. Foreign
keys restrict deletion of the customer, plan, approving Admin and linked subscription.
A populated agreement table cannot be rolled back by the migration.

## Monthly fair use and expiry

`billing:process-service-agreements` is registered every minute with the existing
scheduler overlap/shared-cache guards, independently of FIB reconciliation. It
uses the same service-checkout cache lock and customer row lock as protected billing
operations. Actual scheduling must already be running in the target environment;
registering this command does not start a local scheduler.

Before the start there is no subscription switch or wallet change. During the term,
each start-date anniversary resets App/API subscription buckets to their recorded
monthly allowances. January 31 uses February 28/29 then March 31. Unused monthly
credits do not roll over; add-on buckets remain untouched. A partial final interval
receives one monthly allowance, not a prorated allowance. After downtime only the
current interval is allocated: missed months are never stacked or paid retrospectively.
The initial allocation follows the same rule, rather than granting the whole term.

The existing `subscription_credit_allocations` unique subscription/cycle identity
claims each interval; App/API wallet changes and their separate CreditLedger rows
commit atomically with the claim. Existing wallet `current_cycle_key` retains its
seven-character calendar format. Agreement cycle identities live in the allocation
and ledger references. The generic monthly refill does not also allocate this source.

At expiry the agreement subscription buckets are cleared, add-ons retained, and the
shared ExpireSubscription service returns the customer to Free. The shared expiry
entry point also clears agreement credits when invoked by existing reconciliation.
Expiry is labelled as agreement term end, not failed provider renewal. An agreement
superseded by another authorized subscription never clears the new plan's wallets.
Credit allocation claims, original subscriptions and Admin audit history remain.
Creation/retry is audited with the operator; activation and expiry have linked
system audit entries. No collection or refund is inferred from credit changes.

## Installation and operator verification

One additive migration is required. Back up the local database first, then review
and apply only this migration manually:

```bash
php artisan migrate --pretend --path=database/migrations/2026_09_13_000001_create_service_plan_agreements_table.php
php artisan migrate --path=database/migrations/2026_09_13_000001_create_service_plan_agreements_table.php
```

No migration or seeder was executed against the application database during source
implementation. Without the table the Admin panel displays a migration notice and
the new scheduler command makes no changes. Existing feature gates are untouched.

An operator can inspect due agreements without mutations:

```bash
php artisan billing:process-service-agreements --customer=CUSTOMER_ID --dry-run
```

Dropping `--dry-run` is an explicit activation/allocation/expiry operation. Do not run
it against real customer agreements merely to inspect history. Creating a current
agreement through Admin itself performs its first eligible allocation; creating a
future agreement waits for the scheduler. Review-blocked rows require the Admin retry.

## Acceptance scope

Regression coverage uses isolated SQLite, mocked network and fixture customers.
It covers exact dates, delayed/future activation, anniversary boundaries, expiry,
monthly reset, unchanged add-ons, durable replay, current-state blockers, capability
revocation, ownership, no provider/payment writes and translated server rendering.
Native MySQL/RDS DDL and locking acceptance, the application migration, a running
scheduler and signed-in interactive browser acceptance remain operator checks.

Verified source checks on 2026-09-13: the broad Admin P0/P3, complimentary access,
V2 checkout/purchase, recurring Phase 2 and FIB flow run had 239 passes and one
pre-existing V1 Arabic assertion failure in `FibPaymentFlowTest.php:2470` (it expects
English redirect copy on the Arabic page). The final agreement/date-boundary and
V2 account/purchase rerun passed 55 tests, including 18 focused agreement cases.
These runs overlap; their counts are not additive. All 21 selected Admin/account/
payment frontend checks passed. Scoped Pint, PHP syntax and diff whitespace checks
passed. Browser checks here are HTTP/Livewire test rendering, not an interactive
signed-in browser acceptance session. No application database or feature gate was
modified, and no new cash contract was recorded for a real customer.
