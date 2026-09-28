# Admin usability and control cleanup — 2026-09-27

This is a source/UI follow-up, not a redesign or deployment. No application
migrations, seeders, financial corrections or capability mutations were executed.
An explicitly local, loopback DB read found Admin 1 active with all six supported
capabilities. The existing fresh Gate authority and provisioning command remain.

## Browsing and action controls

- Sidebar viewport/flex bounds and native overflow allow independent scrolling;
  the navigation region is keyboard focusable. Existing desktop/mobile controls
  remain. The shared bridge makes Bootstrap dialogs scrollable after navigation
  and Livewire morphs. Final physical scrolling needs browser acceptance.
- Header shows Admin Panel, a null-safe profile/name, and MetKurd logo fallback.
  The incorrect customer-profile link was removed; no Admin profile editor exists.
- The existing reason component now supplies only a hidden action marker and
  validation summary. SweetAlert collects a fresh 10–500-character reason only
  when confirming an audited action; the same backend property, capability,
  audit rules and operation identities remain. Credit sync and agreement retry
  have explicit reason-property annotations. Financial forms retain their own
  reasons. Changing a durable decision still requires **Start a new correction**.
- Validation summaries appear inside existing modal bodies and at page level;
  inline errors remain. Failed requests show translated safe messages, including
  a distinct permission denial, without provider/SQL/exception contents.
- The already collapsed V2 catalog is labelled **Technical reference**. Tools
  link to the existing entitlement editor, including action-filtered links.
- Customer rankings offer Top 20/40/60/80/100. Query limits are bounded server-side;
  arbitrary/negative client values fall back to 20.
- `/customers/detail/{id}` retains its selected-customer operational sections.
  Its linked focused billing view no longer queries/renders the Register Table;
  unfiltered Register retains the table. App/API wallets, effective plan, storage,
  jobs/history and access remain separate. MCP gate/eligibility/scopes are local,
  read-only projections; permission, credits and runtime enablement remain separate.
- Complimentary grant, real FIB reconciliation, storage correction and add-on
  correction are separate modal launchers with short explanations. Existing
  grant start-at-confirmation, monthly/yearly expiry, allowance preview and safe
  top-up policy are unchanged. Real FIB evidence/finance/reconcile checks remain.

## Limit authority

`PlanEntitlement.limits`, per action and App/API channel, remains the existing
plan-limit store. The entitlement editor exposes `max_chars_per_submit` as a
number field for the four Apollo/Vector variants derived from the V2 catalog.
Other JSON settings are retained. Service-plan processing/API concurrency remains
on the existing plan editor; it is not duplicated in entitlement JSON.

The audit found `InputBoundary` calling a nonexistent `Customer::entitlementLimitFor`,
which meant every V2 speech request fell back to 400 characters. V2 now calls
`Customer::inputLimitFor`, using
the existing current effective plan and customer-entitlement override resolution,
including channel/all fallback and dated customer overrides. Legacy Mobile’s
optional helper/fallback path remains unchanged. API submission passes
the API channel explicitly; App remains App. Without a configured value the 400
fallback remains. No plan/price/entitlement rows were rewritten.

Only enforced settings are presented as editable limits. Batch segment/character
envelopes and Harakat characters come from `metkurd_v2`; OCR page ceiling comes
from `OcrDocumentProbe`. These remain shared reference values, not per-plan knobs.
There is no existing enforced per-plan audio duration limit, so the UI says so
instead of creating a second system or offering a control that does nothing.

## External agreements

Creation retains the base permission plan, start, inclusive end, reference,
optional agreed amount and required reason. It adds optional explicit monthly
App/API allowances and processing slots. Blank allowances snapshot the selected
plan as before; explicit zero is valid. Public ServicePlan values are not changed.
Creation/retry/adjustment remain finance-authorized and use AdminOperationRunner,
the existing customer checkout lock, DB transaction and row locks.

`service_agreements.php` holds default concurrency 5, maximum 5 and maximum monthly
credits 1,000,000,000 per wallet. The concurrency ceiling conservatively matches the
existing highest seeded App capacity, not an asserted production load-test result.
An operator must review capacity before raising it. No unlimited value is accepted.

The additive migration
`2026_09_27_140000_add_service_agreement_concurrency.php` adds nullable
`service_plan_agreements.concurrent_jobs_limit`. **Prepared, not applied to the
application database.** Existing rows remain null and retain plan concurrency.
New agreement controls show a migration notice until this column exists; historical
agreement reads and the existing scheduler/lifecycle remain available. Rollback
refuses to discard populated overrides.

PlanConcurrencyService resolves the bound, current, active agreement through
CustomerBillingStateService; scheduled, expired, superseded, unbound or other-customer
records cannot confer an override. Normal customers retain plan limits. API/MCP
configuration consumes the same override without enabling a disabled/zero-capacity
API plan or changing scopes. Existing App/API queue counting remains unchanged;
the override does not create a new combined queue or concurrency subsystem.

The adjustment modal changes only that agreement's future allowance snapshot and
concurrency, with before/after audit and an immutable adjustment intent. Concurrency
applies while the agreement is effective. Credits change at the next **unallocated**
cycle; current wallets, past allocation claims and original approval are untouched.

ServiceAgreementLifecycle still resets separate App/API subscription buckets on
anniversary cycles. Claims are durable/idempotent; downtime allocates only the
current interval, not accumulated missed months. Add-ons remain separate. Existing
expiry clears the agreement subscription buckets and returns through effective-plan
authority. No Payment, PaymentIntent, collection, provider status or revenue is
fabricated; agreed amounts remain external contract information.

## Verification and remaining acceptance

Tests use isolated SQLite memory databases, array cache/session, and mocked external
services. They cover shell/locale rendering, fresh reasons/capabilities, real billing
regressions, bounded rankings, effective entitlement enforcement and agreement
allowances/concurrency/replay/expiry/ownership. Frontend tests cover reason confirmation,
cancel/no-mutation behavior, navigation, safe failures and scroll CSS contracts.

Browser automation could not initialize (missing kernel assets). Interactive
desktop/mobile/RTL scrolling and modal acceptance remain unverified. Native MySQL
DDL/locking and application migration remain operator steps. No deployment or
feature-flag change was performed. Verification finished with 292 distinct PHP cases passing across the scoped runs:
120 Admin/core/entitlement cases, 160 agreement/Admin-route/API cases (one outdated
notice assertion was corrected and rerun), 11 plan-concurrency cases and one added
pre-migration-history case. The final affected run passed 57/57, followed by 8/8
API speech/voice checks after isolating the V2 helper name from legacy Mobile.
Nineteen Admin frontend/localization checks passed. Vite build, scoped Pint and
whitespace checks passed.
