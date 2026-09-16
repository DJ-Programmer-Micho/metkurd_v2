# Effective service plan and V2 API consistency

## Post-cutover correction — 2026-09-15

BillingSubscriptionAuthority is shared by effective service/storage scopes and cycle
checks after the committed billing epoch. Customer helpers reread the resolver, including
storage; loaded stale relations do not overrule it. Expired or unparseable retained manual
terms are history only. AppShell cache identity includes the epoch. No old grant refill
or historical provider row can infer paid access from a preserved balance. See
[V1-TO-V2-PRODUCTION-RUNBOOK.md](V1-TO-V2-PRODUCTION-RUNBOOK.md) for actual local findings.


## Confirmed local findings — 2026-09-14

Read-only transactions against the confirmed local application copy established:

| Customer | Effective subscription | App buckets (subscription / add-on) | API buckets | Agreement |
|---|---|---|---|---|
| 1 | 407, Pro, active, `admin_manual`, no Payment | 250,000 / 20,021 | 300,000 / 0 | None |
| 35 | 450, Free, active, `system`, no Payment | 10,000 / 0 | 0 / 0 | None |

The agreement table exists and contains no records. There is no due agreement to
activate or retry for either customer. No scenario of a failed cash activation was
established for customer 35. The subsequent operator report explicitly identified
customer 1 as the Pro account. The earlier screenshot's authenticated identity was
not independently established; do not label it a proven Pro/Free split for ID 35.

Customer 35's retained normalized rows, in application local time:

| ID | Plan / status / source | Start | End | Payment |
|---|---|---|---|---|
| 52 | Free / ended / unspecified | 2026-05-09 00:09:10 | 2026-05-09 00:50:00 | None |
| 54 | Student / ended / FIB | 2026-05-09 00:50:00 | 2026-06-09 00:50:00 | 96 |
| 450 | Free / active / system | 2026-06-09 00:50:24 | Open-ended | None |

Row 54 retains supersession metadata. There is no agreement linkage or allocation
for ID 35. Both wallets retain cycle key `2026-08`, dates August 8–September 7.
This historical cycle metadata was not repaired. Billing state, Customer helpers
and API plan resolution all return Free. No Pro allowance should be granted.

Customer 1 has multiple retained historical rows still marked active. Billing's
latest eligible normalized row is 407 (Pro), starting June 2, 2026 at 16:51:38,
with no end date. The current wallets have cycle key `2026-09`, September 2–October 1.
These historical records were not ended or reclassified by this work.

## Exact API rejection and authorized correction

For customer 1, the fresh API plan resolver already returned Pro, `api_enabled=true`,
300 requests/minute and 10 concurrent API jobs. However, `api_allowed_tools` contained
only exact V1 scopes. `ApiCatalog::scopesForConfiguration` intentionally accepts V2
scopes or supported family wildcards, so it returned no V2 scopes. The key action
incorrectly described this configuration gap as the plan having no API access.

The operator explicitly approved enabling every current V2 service for Pro. Through
the signed-in Admin page, **Save API scopes only** added these explicit grants:

`v2:speech`, `v2:voice-clone`, `v2:transcriptions`, `v2:captions`, `v2:ocr`, `v2:stem`.

The existing V1 scopes remain. AdminEntitlementScopes synchronizes explicit/derived
ownership under the plan lock. The component requires fresh `admin.pricing`, an
explicit reason and the existing SweetAlert confirmation. Admin audit event 2 records
Admin 1 and operation `4d50a4dc-4d84-4c33-911f-91f8416e00c2`. This action writes only
scope configuration/ownership and normal timestamps; it does not resave prices,
billing intervals, credits, payment mode or customer overrides.

Afterward the local runtime returns the six service scopes plus job-read/file-download
scopes. Customer 1's API entitlement checks pass for all nine current actions:
Apollo 1.5/2, Vector 1.5/2, Leo, Caption, OCR and STEM 2/4. No real key was created,
no provider request was made, and existing key scopes were not rewritten. Existing
keys still require their own permitted scopes. Other plans were not changed.

The portal now distinguishes an unavailable plan from an API-enabled plan with no
V2 services configured. EN/AR/KU messages retain the same final server-side checks.
V2 scopes are never silently inferred from legacy exact scopes or a plan name.

## One effective plan

CustomerBillingStateService remains the authority. Its normalized subscription query
and the current Eloquent relations share `CustomerServiceSubscription::effectiveAt`:
active status, no recorded supersession, start not in the future, and valid end date.
Cash agreement end dates remain exclusive. The latest eligible ID wins.

Previously, the through relation could return an older active row, the latest-of-many
relation applied eligibility outside its aggregate, and Customer helpers trusted
partial or stale relations and a synthetic scalar plan ID. Current relations now
select the same eligible ID; helpers obtain the full plan through the billing resolver.
No customer plan column or new plan architecture was introduced. The Admin catalog's
unsaved ID-zero pricing preview explicitly supplies its selected plan; that simulation
does not participate in real customer account resolution. Historical relations
and retained rows remain available separately.

The billing state includes source, access classification, dates, cancellation state,
automatic renewal and App/API allowances. Payment presence is not access authority.
Admin Operations and the focused register use this state; the current-plan badge,
Billing, Profile, pricing/entitlement helpers and API checks share it. Agreement
reference/status/dates are supplementary Admin context; external collection is labelled.

## Agreements, credits and expiry

An agreement is never a second current-plan record. Only legitimate activation through
ServiceAgreementLifecycle creates the normalized `admin_cash_agreement` subscription.
The existing blockers and operator retry remain unchanged. Future/review agreements
display separately in V2 plans and billing and never claim the Current Plan badge.

Current linked agreements supply their recorded App/API allowance snapshots to usage
and Admin allowance displays, including after later catalog edits. Allocation still
uses the existing unique SubscriptionCreditAllocation cycle claim, monthly reset,
separate wallets, retained add-ons and no rollover. No generic refill was added.

At the exclusive end, readers fall back to Free even before the next scheduler run.
The existing expiry operation clears the agreement subscription buckets once, retains
add-ons and records Free through the shared lifecycle. Reads never run that operation.
No Payment, revenue or provider evidence is fabricated.

## Cache boundaries

Customer current-plan helpers no longer retain a stale plan object. Cached tool/action
decisions include the effective plan ID. App shell cache identity includes the current
plan/subscription and a customer-scoped revision; committed subscription/wallet model
saves invalidate that customer's shell entries. Plan saves retain existing catalog
version invalidation. The shell also changes identity at a date boundary without
requiring a scheduler write. No global cache flush is used.

## Preservation and acceptance limits

SHA-256 fingerprints before/after the approved scope change matched for all 2,090
wallets, 6,499 ledger rows, 151 Payments, 38,872 PaymentEvents, 34 CreditOrders,
1,078 service subscriptions and the empty agreement table. Non-scope Pro attributes
and non-scope metadata matched. Both V2 gates were already true when inspected and
remained unchanged. No migration, seeder, historical repair or customer activation ran.

The local Admin scope save was exercised interactively and its persisted values were
verified after refresh. Customer access checks were executed read-only against the
local application database. This does not establish native MySQL/RDS acceptance or
end-to-end paid API/provider execution. Automated lifecycle tests use isolated SQLite
and mocked HTTP.

Final verification: the broad run executed 420 cases (415 passed initially). Its
Admin preview regression was corrected, and three meter fixtures were made explicit
about the V1 redirect gate, V2 scope eligibility and the actual configured API allowance.
The final rerun of all four affected suites passed **79 tests / 676 assertions**,
including **24 agreement/consistency cases**. Across these overlapping runs, 419 of
the 420 distinct cases passed. The remaining failure is the previously recorded
V1 Arabic redirect-copy assertion at `FibPaymentFlowTest.php:2470`, which expects
English text on the Arabic page. No V1 production behavior was changed to satisfy it.

Coverage includes Admin P0 dependency/race/authorization, P1 catalog, P2 reads,
complimentary grants, V2 account/purchase/checkout, API V2, subscription boundaries,
separate wallets, Phase 2 allocation/replay and FIB flow characterization. All **15**
selected Admin/localization/account frontend tests passed; scoped PHP syntax, Pint
and whitespace checks passed. A real customer API key or provider job was not created.
