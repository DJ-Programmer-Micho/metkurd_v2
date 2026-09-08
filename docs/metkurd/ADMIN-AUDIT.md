# MetKurd Admin engineering audit

## R2 native MySQL acceptance prerequisite — 2026-09-07

The intended platform is local MySQL and Amazon RDS for MySQL. A fresh
`SELECT VERSION()` through Laravel's active `mysql` connection returned
`10.4.28-MariaDB`; the local server therefore does not satisfy that prerequisite.
R2 native-engine acceptance is stopped. Historical MariaDB reads are not native
MySQL authentication, rendering, mutation or RDS acceptance. No Admin application
code or database state was changed for this check. See
[engine identity evidence and next step](PRODUCTION-DB-IMPORT.md).

## R1 approved launch pricing correction — 2026-09-07

Implemented only source/tests/documentation for the approved new-variant launch
parity policy. No evidence proves the original three registration migrations
never ran in another maintained environment, so their source remains unchanged.
The new `2026_09_07_000001_normalize_v2_launch_pricing` runs after registrations.

| Global channel | Apollo 2 / character | Vector 2 / character | Leo / minute |
|---|---:|---:|---:|
| all | 20 | 24 | 1,100 |
| app | 20 | 24 | 1,100 |
| mobile | 20 | 24 | 1,100 |
| api | 15 | 18 | 825 |

Minimums remain 1 / 1 / 1,100, unit size 1, ceil/step 1. Explicit constants replace
the old arbitrary QASR copy as the final policy. A stable smallest global row ID
is reused for identity only; competing global rules for those actions are retained
inactive, so old placeholder rates cannot remain active fallbacks. Existing
plan/customer overrides are preserved with normal runtime precedence. The whole
correction rolls back if any expected Tool/action binding is absent. Repeating
it preserves counts and timestamps; rollback does not restore unapproved economics.
Its data-dependent body is skipped in pretend mode because preview cannot load
the preceding registrations; normal execution still rejects missing registrations.

The correction writes only pricing_rules. Registration plan grants, P1 scope
ownership and API allowlists remain unchanged. API access retains the existing
plan/key/scope/entitlement and runtime wallet/rate/concurrency intersection. Legacy
Apollo/Vector/QASR/Caption rates remain untouched. No UI/resolver change was needed;
the existing Admin pricing-review diagnostic still calls for checking actual
post-migration configuration, not inferring deployment from this approval.

Verification: the combined migration/P1/grouping/P0/Admin-billing run passed
**110 tests (643 assertions)**. After additional access/checklist/pretend coverage,
the final focused migration suite passed **12 tests (206 assertions)**. Those
runs overlap; they are not 122 distinct tests. Three QASR insertion orders converge;
Admin preview and real Customer quotes agree for App/API; Mobile/all/unknown-channel
fallbacks use approved values. Nonempty wallet/ledger fingerprints and customer,
subscription, purchase, job/file and plan snapshots remain unchanged in fixtures;
legacy prices and grants are preserved. A superseded global placeholder is inactive,
while a scoped override survives. Paid-plan/key/entitlement/action access is tested
through ApiCatalog without enabling either feature gate. The full HTTP ApiV2Test
suite was not rerun because its setup enables the API gate; no such change was
added to this task's tests. Existing P4B native API test evidence remains historical.

In total **112 distinct tests passed**: 12 final migration tests, 24 P1 correctness,
7 grouped pricing, 48 P0 safety and 21 Admin billing controls. Tests used isolated
SQLite `:memory:` plus the existing P0 race test's disposable SQLite fixture copy,
array cache/session and fake external services; no application database was read
or changed. Both gates
remained disabled in the test process; the actual `.env` and cached files were
unchanged. Syntax, focused Pint and `git diff --check` passed. Native migration
execution, post-migration native MySQL acceptance and rollout remain unverified.

Relative to R1's observed 66 recorded migrations, the added source implies eight
pending / 74 recorded after success if the target has not changed. It was not
requeried here. Against that four-plan baseline expect 66 pricing rows after the
12 explicit new-action rules; active policy checks supersede universal count
assumptions. See [import runbook](PRODUCTION-DB-IMPORT.md) and
[read-only checks](RELEASE-R1-CHECKS.md). Approved source pricing removes the prior
economics/nondeterminism blockers. Operator backup, maintenance, disabling gates
and the complete manual migration run remain required; no execution is marked done.

## Admin P4B — four proven source removals — 2026-09-07

P4B is complete in source. Removed only the unused AdminController, unused
CustomerApiTtsService and the shadowed Admin nav-feature-link / nav-multi-feature-link
copies. Each deletion was verified before the next. The active Admin auth/logout
controller, Livewire routes, API V1 submission service, API V2 and App navigation
components remain unchanged. No routes, V1/mobile contracts, shared backend,
catalog identities, translation/assets, account/checkout flows or database records
were removed. The application database was not accessed during P4B.

Verification used isolated SQLite `:memory:` with fake/mocked external services:
D1 **155 passed (1,929 assertions)**; D2 **55 passed / 5 failed (386 assertions)**,
including all **30 API V2 cases passing**; four additional provisioned Apollo
1.0/1.5 and XTTS/XOMNI alias cases **passed (64 assertions)**. The five failures
were reproduced with the exact deleted service temporarily restored, proving
they predate removal: three legacy API catalog-fixture failures and the two P3
API-credit display failures. Original failing tests/seeders were not repaired.

D3 finished with **58 distinct passing tests** after correcting a new test that
incorrectly expected the old App shell to have Admin's HTML `dir` attribute.
D4 repeated all four relevant suites: **58 passed (1,242 assertions)**. Installed
Livewire Finder still resolves both bare navigation tags to the surviving App
copies. Tests render nested Services/Customers/Payments/Landing links, retained
Profile/Billing pages and hidden feature links across EN/AR/KU. P3 also checks
Admin direction and all 19 scoped pages in each language. Component-location
order and namespaces were not changed.

The route snapshot remains identical: **218 routes, zero duplicate names,
26 API V1 and 19 mobile endpoints**. Syntax, focused Pint and `git diff --check`
passed. No asset reference changed and no frontend build was needed. No browser,
native MySQL, external-client or production acceptance is claimed. The full
step-by-step evidence and five exact failures are in
[LEGACY-RETIREMENT.md](LEGACY-RETIREMENT.md#p4b-verification--2026-09-07).

Only four source deletions, two supporting test files and three documentation
files belong to P4B; earlier working-tree changes were preserved. AGENTS.md did
not require a new rule. V2 compatibility aliases, assets, V1 UI/account replacement,
historical database retirement, API V1/mobile retirement (not approved), financial
reconciliation and pricing decisions remain separate, unstarted work.

## Admin P4A — legacy dependency inventory — 2026-09-07

The audit/classification phase is complete in documentation. The authoritative
[LEGACY-RETIREMENT.md](LEGACY-RETIREMENT.md) inventories current V2 identities,
every V1 workspace, shared backend/result routes, API V1/mobile, Admin source,
assets/translations and historical database relationships. **P4B was not executed
during this inventory; the subsequent bounded removal is recorded above.**
No application code, routes, identities, migrations, prices, balances or historical
records were changed. P0–P3 behavior remains an invariant.

Nine current V2 actions are A; the inspected local catalog contains 18 actions /
13 tools and lacks the source's Apollo 2.0, Vector 2.0 and Leo registrations.
Those missing A records are deployment/import prerequisites, not obsolete entries.
All 21 V1 workspaces still have routes; V2 processing pages do not include the old
processing UIs, but Profile/Billing and the shared account/checkout journey remain
required. Shared controller inheritance, storage, synchronizers and file/code
bindings must survive any later V1 UI retirement.

Four narrow source-only D candidates were proven: the unused AdminController,
unused CustomerApiTtsService, and two shadowed Admin nav components. Installed
Livewire runtime resolution selects the App nav copies for the actual bare tags;
the App copies must remain. No persisted identity, public route, translation key
or public asset was declared safe to delete. Public demo asset references need
separate deployed/runtime evidence. The inventory includes a per-candidate test
impact map and an ordered P4B proposal, with no test deletion.

Read-only direct PDO transactions against the effective loopback target observed
MariaDB 10.4.28: 2,187 jobs, 2,464 files, 6,499 ledger rows, 1,078 service subscription
rows including 29 previous-plan references, 54 prices and 72 entitlements. Pending
payment and file/ledger/string/JSON dependencies remain blockers. Empty API tables
do not authorize removing the intentionally retained API V1/mobile contracts.
No provider/content/object read or SQL write was performed. This is local aggregate
evidence, not native MySQL application/mutation or production acceptance.

An isolated SQLite/array-cache bootstrap registered 218 routes with zero duplicate
names, including 26 API V1 and 19 mobile endpoints, and resolved component paths.
These are audit probes, not a fresh full P0–P3 regression or browser acceptance run.
The prior P3 test results and broader API visibility failures below remain separate.
Historical financial reconciliation, business pricing decisions, external-client/
traffic verification, native MySQL and browser/production acceptance remain open.

## Admin P3 — UI/UX and localization implementation — 2026-09-07

P3 is implemented in source within the existing Livewire view-based components,
Bootstrap layout and route contract. Interactive browser, native MySQL and production
acceptance remain separate. P4 has not started. This work ran no application database
migrations, balance adjustments, price changes or historical repairs; pre-existing
P1/P2/backend changes in the working tree were preserved.

- Navigation separates Dashboard, Services, Customers, Operations and Billing /
  Payments. The duplicated payment-method/currency/catalog navigation group is gone;
  existing route names and Landing CMS functionality are retained.
- Customer detail, Usage and Billing Register share bookmarked Overview, Usage,
  Billing, Jobs, API and Audit links and selected-customer identity. Operational
  trace links preserve the selected customer; resetting operational filters preserves
  the customer and selected section.
- V2 catalog previews separate configured/effective access and limits. Current V2,
  active/inactive and existing classification/diagnostic badges remain visible;
  technical identities, routes and JSON are expandable. Runtime quote, entitlement
  and plan resolution are unchanged. The corrected Total Customers population remains
  intact. Dashboard cache identity now also includes locale because cached analytics
  contain translated labels; charts read current-page translated labels after navigation.
- Operations shows local lifecycle, provider evidence, persistence and fulfillment
  separately from financial amounts and reservation evidence. A recorded provider
  success is never promoted to a confirmed persisted result. Existing read-model
  privacy/redaction and 25-row pagination remain intact.
- `resources/js/admin.js` is the once-installed Admin SweetAlert bridge, bundled
  with scoped `resources/css/admin.css` through Vite. Explicit `data-admin-method`
  and JSON arguments invoke the existing Livewire methods after confirmation.
  Summaries use target identity, allowlisted review fields and translated impact;
  arbitrary component state is not serialized into a dialog. Cancel and navigation
  perform no call; a pending-component guard suppresses duplicate clicks. Existing
  P0 intent IDs are never created/replaced by the bridge. Validation stays inline,
  lightweight notifications use escaped Toastr messages, blocking errors use
  SweetAlert and the P0 sanitized boundary.
- Permission hints disable mutation controls for read-only operators and reuse
  request-local capability results through `AdminUiAccess`; final server mutations
  still perform fresh P0 authorization, dependency and state checks.
- Scoped EN/AR/KU JSON and PHP messages cover the reviewed pages, pagination,
  confirmations and dashboard chart labels. One layout supports LTR/RTL, logical
  spacing and horizontally scrolling tables. Technical codes use LTR isolation,
  customer content uses automatic direction. Skip navigation, explicit form labels,
  icon labels, visible keyboard focus and loading announcements were added. The
  confirmation bridge suspends/restores Bootstrap modal focus trapping and prevents
  stale navigation callbacks from refocusing departed pages.

Verification uses isolated SQLite `:memory:`, array cache/session and fake external
services. Across the regression run and focused corrective reruns, **all 214 Admin
tests passed**, including P0 safety/dependency/race, P1, P2, routes, Billing controls,
dashboard, grouped pricing and eight P3 tests. P3 renders 19 scoped routes in each
of EN/AR/KU (57 HTTP responses), checks customer context, read-only/server permissions,
separate statuses, capability query reuse and financial identity through validation,
correction, refresh and replay. Twelve Node tests pass for bridge/navigation behavior,
cancel/single call/double-click/failure handling and scoped catalog/token coverage.
Focused PHP/Pint checks and the Vite production build passed.

The broader selected Billing suites have **35 passing and two failing tests**.
Both failures were independently reproduced in `SeparateCreditWalletArchitectureTest`:
the legacy API access component's expected `creditBalance`, and the V2 shell's API
credit row visibility. No API implementation or business policy was changed to make
those tests pass. This is **249 distinct passing tests and two remaining failures
across runs**, not a claim that the entire regression command was green.

Browser accessibility-tree inspection of isolated rendered Arabic fixtures identified
pagination and label-association gaps, now covered by rendered regression checks.
These script-stripped fixtures do not verify Livewire interaction or chart rendering.
Full desktop/mobile EN/AR/KU visual, keyboard/modal, Back/Forward and repeated-navigation
acceptance remains open, along with native-speaker review, native MySQL execution,
production acceptance, historical financial reconciliation, business pricing decisions
and P4 legacy cleanup. No legacy deletion or historical repair is authorized by P3.

## Dashboard customer population follow-up — 2026-09-07

The generic Customers card was already an all-record `COUNT(*)` over Customer,
not active/verified/paid/V2-only accounts. There are no customer global scopes,
soft deletes, joins, job filters or subscription filters in that aggregate.
Status is used only for the separate active (`status != 0`) and suspended
(`status = 0`) sums. The period affects the registrations subtitle, not the total.
Directory defaults use the same Customer population; pagination changes page
size, not the total. P0 `users.status` is unrelated to customer account status.

Read-only PDO transactions against both confirmed loopback MySQL targets showed
that cached Laravel configuration and the current environment configuration
selected different databases. The old displayed count matched the cached-config
DB's exact population. Its file-backed `admin-dashboard:overview:30` aggregate
held the same count but was already expired when inspected. Thus the observed
mismatch was stale **configuration**, not a hidden customer subset. The intended
environment target also lacks P0 users.status. No config activation, migration or
application data mutation was executed. Use PRODUCTION-DB-IMPORT.md to activate
the confirmed local target and review its pending migrations before Admin use.

The card is now explicitly Total Customers, with active/suspended counts in its
existing card and EN/AR/KU copy. overviewStats performs one live customer aggregate
per Livewire request, replacing any cached population fields and refreshing the
paid-share denominator. This covers Eloquent changes and direct imports/bulk SQL
that observers cannot see. No new observer, count cache or customer repair exists.
Expensive job/order/subscription analytics retain their five-minute TTL. All six
dashboard cache sections now use `admin-dashboard:v2:<opaque digest>:<section>:<period>`.
The digest uses application path/environment and the effective database endpoint
identity; keys expose no database names, connection credentials or secrets. Old
unscoped keys are bypassed, without flushing unrelated caches. Cached config must
still be refreshed when changing the intended connection; cache namespacing cannot
select a different database than Laravel is configured to use.

Verification: **10 focused dashboard tests / 47 assertions passed**, covering
population without jobs/subscriptions, historical-only jobs, suspension and legacy
nonzero active status, directory/pagination consistency, stale-cache poisoning,
model/bulk changes, database/environment key isolation, EN/AR/KU rendering and
existing revenue/display regressions. Tests use isolated SQLite, array cache and
session, fake HTTP/mail/storage. Native MySQL was used only for the read-only count
and schema diagnostics; application rendering/migration acceptance on the intended
MySQL schema remains unverified. P3 was not started.

Read-only comparison on the chosen local database:

```sql
SELECT COUNT(*) AS total_customers,
       COALESCE(SUM(CASE WHEN status <> 0 THEN 1 ELSE 0 END), 0) AS active_customers,
       COALESCE(SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END), 0) AS suspended_customers,
       COALESCE(SUM(CASE WHEN email_verify = 1 AND phone_verify = 1 THEN 1 ELSE 0 END), 0) AS verified_customers
FROM customers;
```

The former card uses total_customers in this result, not verified_customers or
any subscription/activity subset. To reproduce the default registrations subtitle,
add `SUM(CASE WHEN created_at >= :window_start THEN 1 ELSE 0 END)` using the
application's current time minus 30 days, rounded to local start-of-day.

## Admin P2 — operational visibility implemented in source (2026-09-06)

`admin.operations` (`/{locale}/adm/operations/jobs`) and `admin.customers.detail`
(`/{locale}/adm/customers/detail/{customer}`) share a view-based Livewire component,
`ReadsOperations`, and the local-only `AdminOperations` read model. Customer List,
Billing Register and Usage link to the new detail; the existing list and correction
controls remain unchanged. The Admin navigation has one Operations entry.

- Customer context uses the real effective plan/subscription and storage resolvers,
  including previous plan, cycle/renewal dates, verification, usage/quota and
  customer-aware action access. App Credits and API Credits are distinct cards.
  API holds are already deducted from available wallet balance; holds are not
  labeled final spend. Missing records are not created by these reads.
- Database-paginated sections cover jobs, needs-review queues, API jobs, payments,
  wallet ledger, reservations, files, service/storage subscriptions, orders, API key
  metadata, audit and plan entitlements. Page size is fixed at 25. Filters include
  customer, identity/reference, date, status, channel, action, failure stage,
  provider and debit/credit where relevant. Job/payment traces narrow related
  sections through persisted foreign keys and references, with customer scoping.
- Job metadata derives all nine native family/model mappings from ApiCatalog.
  Local lifecycle is separate from recorded provider evidence and persisted-result
  evidence. API jobs show reservations rather than App debit/refund fields. Job
  history selects presence flags instead of hydrating generated input/output.
- Read-only queues cover ambiguous submission, refund pending, deletion failure,
  active age over two hours, recorded provider success without local finalization,
  stale/terminal/orphan API holds and open payment review. Two hours is a support
  review threshold, not a service SLA or automatic failure classification. Current
  synchronizers do not retain a universal provider terminal status: the terminal
  evidence queue can detect only the persisted `output.provider_success` marker.
  Missing evidence displays as not recorded. No GPU or storage HTTP runs on read.
- Files show metadata, retention, expiry, quota classification and API result
  linkage. Object presence is explicitly not checked; metadata availability is
  separate from physical existence. No signed URLs, object keys, generated text,
  voice-reference content or content preview is sent to the operational UI.
- All reads require fresh active `admin.read`. Payment provider references and
  allowlisted evidence additionally require `admin.finance` or `admin.reconcile`.
  P0 AdminData redaction remains the boundary; audit summaries expose selected
  fields and omit masked values. Historical event outcome is separate from the
  current operation status, so a later successful retry does not relabel a failed event. No new capability or automatic grant was added.
- Customer lookup runs only for at least two search characters (20 results plus
  an explicitly selected customer). Pricing uses 250-row streaming batches to
  build a compact canonical group index, then hydrates at most four channel rows
  per displayed group. The matching-rule scan and group-index memory remain;
  this is not database-native canonical JSON grouping. Existing grouping, ordering,
  fallback, priorities and pricing resolution are preserved. Currency rendering
  reuses one current-rate context and request-local schema capability checks;
  it introduces no persistent financial cache.
- New UI strings are in EN/AR/KU `admin_p2.php`, with existing Admin direction and
  automatic direction for customer text. No P3 redesign or translation backlog work.

Verification: **34 focused P2 tests / 631 assertions passed** on the final source.
The disjoint regression selection also passed all **152 tests**: 48 P0 safety,
21 plan deletion, 24 P1 correctness, 31 route integrity, 7 grouped pricing and
21 Admin billing controls. Total: **186 distinct passing cases across focused
runs**. The P2 performance test caught remaining direct template schema checks;
these now use the same request-local column list as the renderer. Focused Pint
(9 PHP files), PHP/Blade syntax (16 files), and whitespace checks pass. Tests use
isolated SQLite :memory:, array cache/session and fake HTTP/mail/storage. Actual
HTTP rendering covers all new sections and customer detail; generated text and
secrets are excluded. No JS/CSS/bundled asset change requires an asset build.
Native MySQL, interactive browser and production acceptance remain separate. No application DB,
migration, seed, price, balance, provider state or historical financial record was
changed. P3, P4, historical financial reconciliation and business pricing decisions
remain open. No retry, refund, force-complete, provider-ID attachment or deletion
mutation was added to P2.

Date: 2026-09-06. Status: **audit baseline retained; Admin P0, P1 and P2 implemented in source. Deployment acceptance remains open.**

## Admin P1 — V2 correctness implemented in source (2026-09-06)

This section supersedes the older P1 implementation status below. P0 safeguards
remain architectural invariants. P2 operational visibility is implemented above; P3 full UX/localization
and P4 cleanup remain open. No application database, migration, seeder, business
price, balance, feature flag or historical financial record changed.

### Catalog and Services

`AdminV2Catalog` joins MetKurdV2ToolCatalog, ApiCatalog and actual Tool/ToolAction
records. `ApiCatalog::variants()` projects the web catalog through the existing
API definition; no second manually maintained action config or DB merge exists.

| Product | Tool | Persisted action | API scope |
| --- | --- | --- | --- |
| Apollo 1.5 | `xomni` | `xomni.generate` | `v2:speech` |
| Apollo 2.0 | `xomni-v2` | `xomni-v2.generate` | `v2:speech` |
| Vector 1.5 | `clone_xomni` | `clone_xomni.generate` | `v2:voice-clone` |
| Vector 2.0 | `vector-v2` | `vector-v2.generate` | `v2:voice-clone` |
| Leo | `leo` | `leo.transcribe` | `v2:transcriptions` |
| Caption | `caption` | `caption.standard` | `v2:captions` |
| OCR | `ocr` | `ocr.standard` | `v2:ocr` |
| STEM 2 | `stem` | `stem.sep2` | `v2:stem` |
| STEM 4 | `stem` | `stem.sep4` | `v2:stem` |

Services prioritizes Current V2 tools while retaining all other rows. Non-current
job/file references classify as Shared/Historical; recognized V1 API actions as
Legacy; other records as Unmapped. Labels are not deletion permission. Generic
Tool validation accepts hyphens while preserving uniqueness and immutable edit
identities. Missing native records display migration guidance, not automatic repair.

The shared panel on Tools, Pricing, Entitlements and Plans shows family/model,
codes/IDs, metric, web route/path, API service/scope, active state, entitlement and
matching-price coverage. Diagnostics include missing/inactive rows, mismatched
action binding and pricing-review notices for Apollo 2, Vector 2 and Leo.
Migration defaults and Leo's ambiguous copied rate still require business review.

### Scope ownership and mutation consistency

`AdminEntitlementScopes` maintains a runtime-compatible union in
`service_plans.api_allowed_tools`; existing `meta.admin_api_scopes` stores version 1
and `explicit`/`derived` lists. No schema migration is needed. Before ownership
metadata exists, **every configured scope is explicit**. Unknown historical
provenance is never guessed or destructively backfilled.

- The Plan editor edits explicit scopes only; wildcard, exact and legacy grants
  remain until deliberately edited. Derived scopes are displayed separately.
  Generic metadata editing cannot replace the reserved ownership key.
- Derived family scopes come from ApiCatalog and the complete entitlement set.
  API rows override `all` fallback for an action; an API denial blocks that
  action's derived grant. Other allowed siblings retain the family scope.
  Tool/action activation remains a separate runtime access check.
- Create/update/toggle/move/channel-change/permitted-delete operations lock plans
  in ID order and refetch the entitlement under lock. Old and new plan scopes
  update in the same transaction; detected concurrent moves fail safely. Scope
  persistence failure rolls back entitlement and audit writes.
- Only tracked derived scopes are removed automatically. To remove a derived
  grant, change the contributing API/all entitlements; editing explicit scopes
  alone does not override another grant source. Old legacy scopes without known
  provenance remain explicit. V1 parsers/aliases/endpoints remain supported.
- Plan saves lock and atomically save explicit configuration, reserved ownership
  and the derived union. Import, deployment and read-only views perform no backfill.

### Effective configuration, pricing and limits

`CustomerApiAccessService::configForPlan()` is extracted from the runtime customer
configuration method. The panel shows configured/effective API state, RPM and
concurrency, including free-plan denial. Plan-level eligibility combines these
with ApiCatalog scopes and `Customer::isAllowed(action, api)` in an unsaved
plan-only customer context. No customer/key records are created or exposed.
Customer overrides/status, key scopes, balance, rollout and occupied capacity
remain submission-time checks, explicitly stated in the panel.

Grouped App/Mobile/API pricing saves are transactional: validation precedes writes
and a later channel failure rolls back earlier channel/audit writes. Group matching,
fallback rows, conditions, priorities and history remain unchanged.
`Customer::resolvedPricingRuleFor()` exposes the selection previously internal to
`priceCreditsFor()`, which delegates to it. The read preview calls the same runtime
selection/calculation and displays rule ID, channel, plan/global source, priority
and sample credits. Customer override precedence, plan/global fallback, dates,
conditions, channel ordering and rounding remain intact. Plan-only previews do
not include actual customer overrides. STEM forces its mode/output count while
supplying sample seconds/rounded-up minutes; OCR uses selected pages. Zero sample
credits is not submission approval and raw `stem_output` pricing is not a complete
unconditional charge. No business prices were chosen or changed.

The panel documents actual native limits: speech/clone use a 400-character fallback
because Customer lacks `entitlementLimitFor`; arbitrary entitlement JSON does not
change it. Clone references allow 20 MiB with a 20-second worker reference cap.
Leo/Caption/STEM audio and OCR files allow 100 MiB; PDF verification caps at 3,888
pages. Plan fields own API RPM/concurrency and App concurrency. Deployment config
`customer_api.temporary_file_ttl_days` owns retention; proxy/worker limits may be
stricter. P1 invents no new entitlement enforcement fields.

### Localization and verification

`TranslationArea` uses configured `aurl` and Admin route names; the shared helper
and middleware agree. Cached area JSON is replaced, with common JSON/PHP catalogs
preserved, so earlier Landing loads do not supply Admin messages. Existing Livewire
persistent middleware resolves the verified original route, never an untrusted
referrer. Direct GET and real signed-snapshot updates pass in EN/AR/KU, including
Landing separation and configurable prefix checks. Document direction is EN/LTR
and AR/KU/RTL; technical inputs and identifiers are isolated LTR. No Landing
catalog/content or full RTL CSS redesign changed. P1 PHP text catalogs have matching
EN/AR/KU keys.

- Admin/P0/plan/route selection: **167 passed / 1,077 assertions**, including 24
  P1 cases, all 48 P0 safety cases, final deletion and route integrity regressions.
- API V2, V2 localization/core and upload lifecycle selection: **60 passed /
  11,143 assertions**. Tests use isolated SQLite, array cache/session, fake storage,
  mail and HTTP (an isolated bootstrap supplies missing fakes for older tests).
- Shared channel pricing/fallback regressions: **2 passed / 5 assertions**.
  Total across these disjoint selections: **229 passed / 12,225 assertions**.
  Focused Pint (19 files), PHP/Blade syntax (25 files) and whitespace checks pass.
  Fresh isolated route boot lists 216 routes with zero duplicate names. Blade response rendering
  is covered; no JavaScript or bundled stylesheet changes required an asset build.
- Earlier legacy-only scope assertions now verify explicit preservation plus the
  derived V2 union. This is an intentional contract update, not a weakened check.
- Native MySQL, real browser layout/navigation and deployment acceptance remain
  unverified. Historical financial findings and the earlier unrelated legacy API
  voice-listing 403 are not repaired or cleared by P1.

## Routing and production-snapshot follow-up — 2026-09-06

The [focused routing review](ADMIN-ROUTING-REVIEW.md) found no accidental damage
to `routes/web.php`. A pre-existing shared authentication redirect sent Admin
guests to customer sign-in; the narrow middleware repair now selects Admin
sign-in. P0 active/read access and persistent Livewire enforcement passed the
isolated route checks. Missing P0 schema is **PENDING MIGRATION**, not route
damage. The `adm`/`super-admin` localization mismatch remains open for P1.
The final combined route/P0 safety selection passed **79 tests / 670 assertions**;
this focused result does not replace or clear the broader baseline failures below.

The designated final V1 snapshot has eight pending migrations and needs no
general seeder. See [the import plan and billing findings](PRODUCTION-DB-IMPORT.md)
before replacing the local development database. No application database was
imported, migrated, seeded or financially repaired during this review.

**P0 deletion compatibility: fixed in source in the subsequent focused repair.**
The guards had queried nonexistent `customers.service_plan_id`. ServicePlan now
relies on its existing unfiltered `subscriptions()` and `previousSubscriptions()`
relationships, keyed by `customer_service_subscriptions.service_plan_id` and
`previous_service_plan_id`. The redundant Customer query is removed. Its other
catalog, grants, CreditOrder, Payment, PaymentIntent and AdminOperation checks
remain intact, as do the existing API/file dependency guards.

PlanVoiceAccess checks its `servicePlan()` with grouped `whereHas('subscriptions')`
or `whereHas('previousSubscriptions')`. There is no status/date filter: canceled,
expired, ended and previous-plan history remain protected. The access row itself
is not proof of customer use; an access row on an otherwise unsubscribed plan
can be removed even when another subscribed plan uses the same voice. The final
locked guard queries fresh relationships rather than trusting preview results or
loaded relation caches. No schema, UI, financial policy or historical rows changed.

Focused tests reject the invalid customer-column SQL before SQLite executes it;
both query checks reproduced the original defect. Native MySQL execution and
production-engine concurrency acceptance remain unverified. Historical revenue
classification, missing add-on product links and subscription reconciliation
findings remain open in the import plan. P1 has not started.

Focused closure verification (isolated SQLite, array cache/session, fake
storage/mail and HTTP):

- `AdminPlanDeletionTest`, `AdminP0SafetyTest`, `AdminPaymentPlansCreditsTest`
  and `PlanConcurrencyTest`: **84 passed / 328 assertions**, including all 21
  new deletion cases and all 48 existing P0 safety cases. Existing final
  dependency/race and two-process replay cases pass.
- Four existing public/mobile voice-catalog cases: **3 passed, 1 failed / 37
  assertions**. `PublicCustomerApiTest` line 499 expects HTTP 200 for the legacy
  Apollo 1.5 voice listing and receives 403. An isolated comparison with the
  pre-fix deletion guard reproduces the same failure. This unrelated API
  baseline was not changed or counted as P0 closure success. A temporary test
  bootstrap supplies storage/mail/HTTP fakes for those existing API cases.
- PHP syntax, focused Pint and diff whitespace checks pass. No native MySQL
  execution, application migration/seed, UI or financial-data change occurred.

## Admin P0 implementation status — 2026-09-06

This section supersedes the implementation status of the original findings only
where listed. Sections 1 onward describe the pre-implementation audit evidence;
their statements about no tests/changes apply to that earlier review.

| Finding / P0 requirement | Implemented boundary |
| --- | --- |
| P0-01 authorization | Fresh-user Laravel Gates separate read/support, customer changes, catalog, pricing/entitlements, finance and reconciliation. Active-admin middleware persists across Livewire requests; scoped final handlers authorize before trusting public IDs. |
| P0-02 invalidation | `InvalidateAdminReviewPayment` locks and refetches payment, validates customer/review/source state, rejects paid evidence, fulfilled/applied states, records true before/after and actor/reason, and reuses the original operation on replay. |
| P0-03 durable grants / plan-credit boundary | `AdminOperationRunner` persists actor/customer/action/payload identity, locks intent and customer, records the result, rejects changed payloads, and returns completed results without reallocation. Manual plan state and both wallet synchronizations share one DB transaction. Failed intents remain pending for the same-payload retry. |
| P0-04 final Tools deletion | `AdminCatalogDeletion` repeats dependency checks on the locked final target, including deleted/historical jobs, artifacts and API relations; preview approval cannot override these checks. |
| P0-05 provider correction | `AdminProviderEvidence` performs read-only lookup and matches ID, amount/currency, paid evidence and local linkage before reference/state writes. A different reference needs the local merchant reference in provider evidence. Subscription activity alone or a stale callback is insufficient. Lookup/validation failure preserves the original Payment and leaves a safe failed-attempt audit event. |
| P0-06 product relationship | Future `AddonPurchaseService` orders populate `credit_product_id` for provider purchases and Admin grants. No historical backfill. |
| P0-07 revenue | New manual add-on/storage grants explicitly exclude revenue while retaining benefit. Verified-paid correction requires finance plus reconciliation capability, matching customer/product/cycle/payment and fresh provider evidence; existing fulfillment supplies the linked order. Model and query revenue predicates use the same recognized metadata values. |
| P0-08 broader deletion | Final guards cover ServicePlan, StoragePlan, CreditProduct, Coupon, PaymentMethod, pricing rules, voices/access and entitlements, including pending Payment/PaymentIntent and historical relationships where relevant. Existing deactivation controls remain the way to retain referenced catalog rows. |
| Currency final context | Final edit validates locked rate against the selected managed pair and locked edit context. Base currency deactivation is rejected, currency changes are locked, and audit includes currency/rate state. |
| Audit / sensitive display | Additive operation/audit tables retain actor, reason, identity, timestamp, requested change and before/after. Payment events use operation-specific keys. Operational payload display uses a small allowlist; settings and audit recursively mask secret keys/credential strings/URLs, preserving protected server settings on masked edits. Unknown Admin exceptions use a generic response. |

Implementation lives in `app/Services/Admin`, `app/Support/Admin`, the explicit
payment invalidation action and scoped Admin components. New P0 labels/errors
have EN/AR/KU PHP catalogs; existing Admin localization/RTL findings remain open.
No Landing CMS, pricing policy, V2 identities, feature flags or historical
financial data were changed by this implementation phase.

### Rollout and verification limits

The additive migration `2026_09_06_000002_add_admin_operation_safety` has only run
in isolated tests. It adds nullable Admin capability lists, operation/audit
tables and `users.status` only if absent. Existing active Admins default to
read/support; no mutation privileges are implicitly granted. Provision explicit
capabilities using the trusted deployment console as described in
[Infrastructure](INFRASTRUCTURE.md#admin-p0-rollout--2026-09-06).

Replay identity covers a single form intent. Its locked ID stays stable through
retries; **Start new correction** intentionally creates new identities. Reloading
the page creates a new form intent and is not an inferred replay of a previous
business request. Review existing history before issuing another correction.

Atomicity applies to database changes, including the manual plan/credit boundary.
Existing provider fulfillment still has external cancellation/notification side
effects, which cannot be rolled back with SQL. The new outer operation runner
does not automatically retry transactions that may reach those effects. Provider
deployment acceptance and production-engine locking tests remain necessary.

Tests use in-memory SQLite, fake storage/mail and mocked HTTP. The concurrent
replay regression copies only its in-memory fixture to a temporary SQLite file
and starts two independent PHP processes; test-only retries handle SQLite writer
contention. This proves one grant under that engine, not production row-lock or
provider availability guarantees. Browser appearance, deployment migration and
actual operator provisioning were not performed in this phase.

### Recorded checks

- Before implementation: `tests/Feature/Admin`, `tests/Feature/Billing` and
  `tests/Feature/Payments`: **218 passed, 2 failed, 1,202 assertions**.
- After implementation, the same selection: **266 passed, 2 failed, 1,393
  assertions** (184.38 seconds), including all **48** new cases in
  `tests/Feature/Admin/AdminP0SafetyTest.php`.
- Both unchanged failures are in
  `tests/Feature/Billing/SeparateCreditWalletArchitectureTest.php`: “shows app
  credits in the shell and api credits on the api access page” (line 180 expects
  2222, receives null), and “renders the V2 resource meter from the canonical
  shell data without clipping over-limit balances” (line 340 expects API Credits
  in the App resource meter). Neither assertion nor customer UI was changed to
  silence this pre-existing baseline.
- Existing successful-mutation fixtures now explicitly provision capabilities
  and correction reasons. Provider repair fixtures supply fresh paid/amount
  evidence; the eligible invalidation fixture starts unpaid. Financial outcome
  assertions remain intact.
- New coverage includes active/guest/revoked/support/finance boundaries and
  manipulated IDs, current-state invalidation, same/changed/replayed intents,
  two independent concurrent processes, plan/sync rollback, pending-sync target
  changes, App/API isolation, consumed-credit replay, storage period replay,
  revenue/product links, provider lookup failure and linkage, final dependency
  races/history, currency targets, audit before/after, masked settings round trips,
  explicit capability provisioning and EN/AR/KU P0 key completeness.
- Focused Pint and changed PHP/Blade syntax checks pass; `git diff --check`
  passes. No JavaScript/CSS asset changes required a Vite rebuild. Visual browser
  acceptance and production database/provider checks remain unverified.

P1 — V2 correctness, P2 — operational visibility, P3 — UI/localization and
P4 — legacy cleanup remain **open and unimplemented in this phase**.

## 1. Scope, evidence and overall assessment

This review covers Admin Services, Customers, Payments/Billing operations, and
their dependencies on current App V2 and API V2. Landing CMS, marketing content,
and landing translation catalogs are outside scope. Shared routing, localization
and layout are considered only where they affect the scoped pages.

The current Admin has a usable Laravel/Livewire foundation, real App/API wallet
separation, channel-aware pricing, plan controls and several domain-backed billing
corrections. It is **not yet a complete or sufficiently constrained V2 operations
surface**. The most urgent findings concern financial state transitions, repeated
manual actions, deletion guards and the absence of permission separation. The
clearest V2 incompatibility is the entitlement editor's legacy API scope mapping.

Evidence is the current working-tree source, including existing uncommitted work,
models, migrations, callers, catalogs and existing test source. Context reviewed:
[architecture](ARCHITECTURE.md), [services](SERVICES.md),
[GPU lifecycle](GPU-JOB-LIFECYCLE.md), [storage/cache](STORAGE-AND-CACHE.md),
[API V2](API-V2.md), [infrastructure](INFRASTRUCTURE.md),
[localization](LOCALIZATION.md), [production audit](PRODUCTION-AUDIT.md),
[decisions](CHANGELOG.md), and the repository's [AGENTS.md](../../AGENTS.md).

No application bootstrap, database query, test suite, migration, seeder, provider
request, browser mutation, financial operation or feature-flag change was run.
No deployment, actual administrator permissions outside this repository, database
contents, customer balances, displayed runtime prices, or visual browser behavior
is certified. Names below are source-defined defaults, not a live database export.
The user's successful service tests do not establish Admin or production acceptance.
This document is the only file created or changed for this audit.

Priority meanings: **P0** financial/security/data integrity; **P1** incorrect V2
behavior; **P2** operational capability; **P3** interface/localization; **P4** cleanup.
These are implementation priorities, not claims that production incidents occurred.

## 2. Architecture: pages, routes and data ownership

All authenticated paths below have the prefix `/{locale}/{aurl}`; the route group
uses `auth:admin` and `LocalizationMainMiddleware`. The current source sets
`aurl` to `adm` in `AppServiceProvider`, so pages are under `/{locale}/adm`.
The sign-in route is `/{aurl}/signin`, under
`guest:admin`; logout is POST `/{aurl}/logout` under `auth:admin`.

The active implementation is principally anonymous Livewire components in
`resources/views/admin/pages/**/⚡*.blade.php`, with logic in
`app/Support/Admin/Manages*Page.php`. It is not a conventional controller CRUD
panel. Shared customer/payment helpers are `InteractsWithCustomerAdmin` and
`InteractsWithPaymentAdmin`. The table uses component suffixes within
`admin::pages`; each named trait is under `app/Support/Admin`.

| Page / route suffix | Livewire component → implementation | Services / models → principal tables | V2 impact |
|---|---|---|---|
| Dashboard `/home` | `home.app-home` → `ManagesAdminHomePage` | Billing currency + cached aggregates; Customer, MlJob, CreditOrder, subscriptions → customers, ml_jobs, credit_orders, customer_service_subscriptions | Customer/job/revenue totals; not a V2 job recovery console |
| Tools `/services/tools` | `services.adm-services-tools` → `ManagesServiceToolsPage` | Direct Tool/ToolAction CRUD and usage aggregates → tools, tool_actions, ml_jobs | Stable pricing/access identities, active state, names, metadata |
| Voices `/services/voices` | `services.adm-services-voices` → `ManagesServiceVoicesPage` | Voice, PlanVoiceAccess, ServicePlan → voices, plan_voice_access, service_plans | Apollo voice visibility, reference metadata and plan access |
| Rules `/services/rules` | Redirect to voices | No separate rule engine | Compatibility route; not a second access system |
| Pricing `/services/pricing` | `services.adm-services-pricing` → `ManagesServicePricingPage` | PricingRule, ToolAction, ServicePlan → pricing_rules | App/Mobile/API columns; plan/global and legacy fallback rules |
| Entitlements `/services/entitlements` | `services.adm-services-entitlements` → `ManagesServiceEntitlementsPage` | PlanEntitlement + CustomerApiAccessService → plan_entitlements, service_plans | Per-action/channel access and limits; legacy scope synchronization |
| Customers `/customers/list` | `customers.adm-customers-list` → `ManagesCustomerListPage` + customer helper | Customer, profile, wallets, usage, subscriptions, MlJob, CreditOrder; verification support mail | Directory, status changes, quick view, link to billing corrections |
| Ranking `/customers/ranking` | `customers.adm-customers-ranking` → `ManagesCustomerRankingPage` | Customer/job/order aggregates via customer helper | Comparative usage/purchase view; overlaps directory context |
| Billing register `/customers/register` | `customers.adm-customers-register` → `ManagesCustomerRegisterPage` + customer helper | ManualServicePlanGrantService, CreditService, PlanSwitcher, AddonPurchaseService, ConfirmFibPayment, ManualRevenueReclassificationService, PaymentEventRecorder | Customer plan/storage/credit correction, provider reconciliation and payment review |
| Usage `/customers/usage` | `customers.adm-customers-usage` → `ManagesCustomerUsagePage` | Customer, MlJob, Tool/ToolAction aggregates | Counts and service/model/action breakdown; limited individual job context |
| Suspended `/customers/suspended` | `customers.adm-customers-suspended` → `ManagesCustomerSuspendedPage` | Customer status + customer helper | Restore access; no distinct permission boundary |
| Phone countries `/customers/phone-countries` | `customers.adm-customers-phone-countries`, inline logic | RegistrationPhoneCountryManager, RegistrationPhoneCountry → registration_phone_countries | Shared signup/phone-change country availability |
| Plans `/packs/plans` | `payments.adm-payments-plans` → `ManagesPaymentPlansPage` | ServicePlan; currency/recurring helpers; model cache hooks → service_plans | App/API allowances, concurrency, API enablement/scopes/RPM, price, billing intervals |
| Add-ons `/packs/addons` | `payments.adm-payments-addons` → `ManagesPaymentAddonsPage` | CreditProduct and CreditOrder → credit_products, credit_orders | Credit pack catalog; current purchase service credits App wallet |
| Storage `/packs/storage` | `payments.adm-payments-storages` → `ManagesPaymentStoragesPage` | StoragePlan and CustomerStorageSubscription → storage_plans, customer_storage_subscriptions | Storage capacity, price and intervals; not an API retention editor |
| Coupons `/packs/coupons` | `payments.adm-payments-coupons` → `ManagesPaymentCouponsPage` | Coupon/redemptions; payment-mode/target helpers → coupons, coupon_redemptions | Discount eligibility, supported methods/cycles, recurring duration |
| Methods `/packs/methods` | `payments.adm-payments-methods` → `ManagesPaymentMethodsPage` | PaymentMethodCatalog, PaymentProviderManager; PaymentMethod, PaymentIntent, CreditOrder → payment_methods, payment_intents, credit_orders | Checkout visibility/activation/capabilities, configuration readiness and JSON settings |
| Currencies `/packs/currencies` | `payments.adm-payments-currencies`, inline logic | BillingCurrencyService, Currency, CurrencyExchangeRate, CountryCurrencyMap → currencies, currency_exchange_rates, country_currency_maps | IQD base, managed rates and display rounding; financial presentation |

Related billing data includes `payments` and `payment_events` (domain Payment and
PaymentEvent models), `credit_wallets`, `credit_ledgers`, `customer_usages`,
`customer_service_subscriptions`, and `customer_storage_subscriptions`. Customer
results remain `MlJob`/`CustomerFile` state, not Admin-owned worker output.

There is no routed scoped Admin API-key, API-reservation, job-detail/recovery,
wallet-ledger, or general all-customer payment-operations page. Payment review is
embedded in a selected customer's billing register. `AdminController` is not the
handler for the active page routes; no caller was found in the application/routes
search. Treat its old dashboard/profile methods as a cleanup candidate only.

Primary evidence: [routes/web.php](../../routes/web.php),
[Admin support modules](../../app/Support/Admin),
[Admin views](../../resources/views/admin),
[billing domain](../../app/Services/Billing), and
[payment actions](../../app/Domain/Payments/Actions).

## 3. Current V2 service identities and classification

### 3.1 Exact current mappings

Admin tables display `Tool.name` / `ToolAction.name` from the database. There is
no unified V2 family/variant presentation derived from `MetKurdV2ToolCatalog`.
The default names below come from `OmniToolSeeder`, `CaptionToolSeeder`,
`DevDefaultSeeder` and the additive V2 registration migrations. Operator edits or
initializer order can change display names without changing the identities.

For **every row below**, App pricing uses the listed full action with
`pricing_channel=app`; API pricing uses the **same full action** with
`pricing_channel=api`. Entitlements point to that action's `tool_actions.id`
through `PlanEntitlement(service_plan_id, tool_action_id, entitlement_channel)`.
The App/API distinction belongs to the channel, not a renamed ToolAction.

| Current product / class | Admin source display name | Tool.code | Full ToolAction; App and API pricing action | V2 App suffix after `/{locale}/app-v2/` | API V2 service / selector; native contract | Metric |
|---|---|---|---|---|---|---|
| Apollo 1.5 — A | Apollo 1.5v | `xomni` | `xomni.generate` | `text-to-speech/apollo-1` | `speech`, model `1.5`; OMNI `model_1`, builtin reference | character |
| Apollo 2.0 — A | Apollo 2.0v | `xomni-v2` | `xomni-v2.generate` | `text-to-speech/apollo-2` | `speech`, model `2.0`; OMNI `model_2`, builtin reference | character |
| Vector 1.5 — A | Vector 1.5v | `clone_xomni` | `clone_xomni.generate` | `clone-text-to-speech/vector-1` | `voice-clone`, model `1.5`; OMNI `model_1`, owned reference audio | character |
| Vector 2.0 — A | Vector 2.0v | `vector-v2` | `vector-v2.generate` | `clone-text-to-speech/vector-2` | `voice-clone`, model `2.0`; OMNI `model_2`, owned reference audio | character |
| Leo — A | Leo | `leo` | `leo.transcribe` | `speech-to-text/leo` | `transcriptions`; QASR V2 `asr`, `fine_tuned` | minute |
| Caption — A | Caption in V2 migration; Kurdish Caption in seeders | `caption` | `caption.standard` | `speech-to-text/caption` | `captions`; QASR V2 `caption`, `fine_tuned` | minute |
| OCR — A | OCR | `ocr` | `ocr.standard` | `ocr/scanner` | `ocr`; KOCR V2 layout text and selected exports | page |
| STEM 2 — A | Stem Separation / Stem Separation 2 | `stem` | `stem.sep2` | `stem/2-stem` | `stem`, mode `2`; two-track separation | stem_output |
| STEM 4 — A | Stem Separation / Stem Separation 4 | `stem` | `stem.sep4` | `stem/4-stem` | `stem`, mode `4`; four-track separation | stem_output |

STEM submission pricing context includes output count and duration; a generic
metric label alone is not a complete quote. Use the existing quote implementation
when explaining a price. OCR billable pages are verified server-side through
`OcrDocumentProbe`; Admin must not introduce a client page-count billing override.

There is **no source evidence of the scoped Admin hardcoding `stem.2` or `stem.4`**.
The rows are database-driven. Actual erroneous database rows cannot be ruled out
without a later approved data inventory. Do not rename `stem.sep2` or `stem.sep4`.
Leo has its own action; the QASR worker adapter does not make `qasr.standard` Leo.
Caption remains separate from Leo even though they share native infrastructure.

Apollo and Vector are represented as separate variant records and pricing actions,
not accidentally collapsed into one action. The missing feature is an explicit
family/model presentation and a V2 catalog coverage check. The `legacy_tool` and
`legacy_action` keys in V2 configuration are stable bindings, not retirement flags.

Sources: [V2 catalog configuration](../../config/metkurd_v2.php),
[catalog reader](../../app/Support/MetKurdV2ToolCatalog.php),
[API catalog](../../app/Services/CustomerApi/V2/ApiCatalog.php),
[Omni seed definitions](../../database/seeders/OmniToolSeeder.php),
[Caption seed definitions](../../database/seeders/CaptionToolSeeder.php),
[development seed definitions](../../database/seeders/DevDefaultSeeder.php),
and [service contracts](SERVICES.md).

### 3.2 Legacy classification and safe retention

| Class | Source-visible entries/dependencies | Decision |
|---|---|---|
| A — current V2 | All nine variants/modes above | Present as current; preserve exact identities and channel-specific rules |
| B — shared/historical | Existing `xomni`, `clone_xomni`, `caption`, `ocr`, `stem` identities also supporting older routes/data; shared ToolAction relations, voices, plans, ledgers, result controllers and persisted files | These overlap A in historical use. Keep their compatibility responsibilities; an old-looking code is not obsolete |
| C — legacy V1 | `tts.standard` (old speech/Apollo 1.0 surface), `ftts.standard` (F5/legacy speech), `clone_tts.standard` (old clone), `asr.standard`/WASR alias, `qasr.standard`, `tran.standard`; `youtube_audio.mp3`/`.wav` and `youtube_video.p480`/`.p720`/`.p1080`/`.p4k` outside the current V2 catalog | Retain records, history and supported callers. Do not repurpose them as Apollo 2, Vector 2 or Leo |
| C — product label needing exact identity inventory | Neo is excluded from the current V2 target by product direction; no current V2 catalog binding named Neo exists | Do not guess a database ID or automatically map Neo to `ftts`; inventory the actual legacy label/caller before a later migration |
| D — possible obsolete code | Unreferenced old AdminController page methods | Verify routes, external integrations and views before removal; not a tool-data deletion recommendation |
| D — obsolete service records | **None proven removable** by this source-only audit | Runtime references, deleted-job history, financial records and stored artifacts were not inventoried |

The Admin has no explicit A/B/C/D lifecycle classification or default current-V2
filter. Catalog/plan examples still encourage older API scopes (including Apollo
1.0 and Translation). These examples should be modernized later while retaining
compatibility data. No current row or historical record should be deleted to make
the new UI appear clean.

### 3.3 Access, pricing, limits and cache behavior

`Customer::isAllowed` and pricing resolution use active Tool/ToolAction records,
channel-specific plan/customer entitlements and pricing, with supported fallback
channels and date/priority handling. Customer entitlement/pricing overrides exist
in the domain but are not explained by a scoped Admin effective-access screen.
An operator cannot infer a customer's final permission or charge from one plan row.

The pricing editor groups App, Mobile and API rates; it supports global/plan rules,
free rates, units, metrics, priorities, dates and conditional JSON. This is genuine
channel separation. However, saving a three-channel group has no enclosing group
transaction, and deleting/disabling primary rows leaves legacy fallback rows.
Existing tests deliberately preserve those fallback rows. Their resulting effective
price should be previewed; do not silently change fallback semantics.

Entitlement limits are generic JSON, not a typed service-capability editor. Tools
also have freeform category/metadata and metric fields. Admin does not show which
JSON settings are consumed by a native submission path, which limits are hard
server constraints, or which are presentation metadata. Plan App concurrency and
API RPM/concurrency are explicit fields. Upload limits, native document/audio
probes, result persistence, deployment caps and API retention are separate concerns.
No dedicated scoped Admin OCR intelligent-feature entitlement/control was found;
do not claim arbitrary JSON enables or restricts that feature.

Voice management is reusable: it edits active/public voices and plan access.
`Voice` and `PlanVoiceAccess` model save/delete hooks increment the OMNI catalog
cache version. `ServicePlan` model hooks invalidate concurrency/plan caches;
payment-method actions flush the method catalog. **Missing cache invalidation is
not a finding for these existing model-backed operations.**

## 4. Prioritized source findings

### P0-01 — Admin authentication has no action permission separation

The route group protects pages with the Admin session guard. `config/auth.php`
uses the `users` provider and `User` model. The reviewed model, route group and
scoped mutation handlers contain no roles/permissions or per-action Gate/policy
checks. `PaymentPolicy` authorizes customer-owned payment operations and does not
provide an Admin finance policy. Sign-in throttles attempts and regenerates the
session, but its credentials do not check `User.status`; authenticated Admin routes
also do not apply an active-admin check.

Consequently every authenticated Admin principal represented by this application
has the same exposed financial, catalog and customer mutation methods. There is
no source-defined lower-level role whose restrictions can be relied on. This is
not a claim that unauthenticated users can invoke the pages: installed Livewire
persists authentication middleware. Client confirmations and disabled buttons are
not authorization, and public component IDs are not trusted permission decisions.

Later work: define scoped capabilities, enforce active-admin state and authorize
each final server action. Preserve customer ownership checks already present.
Sources: `routes/web.php`, `config/auth.php`, `app/Models/User.php`, Admin sign-in,
`app/Policies/PaymentPolicy.php`, and all scoped Admin traits.

### P0-02 — Invalid-payment action can overwrite a fulfilled payment's status

`ManagesCustomerRegisterPage::markReviewPaymentInvalid` verifies the selected
customer and requires a reason, then locks the payment and unconditionally sets
payment/internal status to EXPIRED. It does not recheck that the row is still an
open review or reject a paid/fulfilled payment. A direct call with an owned
payment ID can therefore relabel a fulfilled record while retaining its credits
or subscription. A native confirmation cannot enforce the missing state rule.

Later work: route through an explicit, locked domain transition with eligible
source states, preserve fulfilled financial history, and record actor plus actual
before/after state. Add a regression for a paid/fulfilled row and a review state
changed concurrently after the dialog opened.

### P0-03 — Manual grants lack a durable operation identity

`applyAddonAdjustment` generates a new reference when blank and invokes
`AddonPurchaseService::purchase`. That service creates a new paid order and App
credit allocation each invocation. It has a transaction and CreditService wallet
locking, but no deduplication of the supplied provider reference/administrative
intent. The provider reference index is not a unique replay guarantee. Repeated
manual clicks/requests can issue multiple grants; new order IDs make their ledger
references distinct rather than deduplicating the operator's intent.

`applyStoragePlanAdjustment` similarly creates paid order/subscription activity
through `PlanSwitcher`. `applyServicePlanAdjustment` invokes a locked manual-plan
grant, then a **separate** credit-sync operation: there is no outer atomic boundary
covering both. A failure between them can leave plan state applied without the
intended wallet correction. Repeating the grant also creates new subscription
history. Existing financial services should be composed safely, not replaced by
direct balance assignment.

Later work: persist a one-use administrative operation identity, lock/revalidate
its target, keep related plan/wallet changes atomic or explicitly recoverable,
and return the original outcome on replay. Preview the exact customer, App/API
buckets, dates and financial classification before confirmation.

### P0-04 — Final Tool/ToolAction deletion bypasses its preview safeguards

`ManagesServiceToolsPage::confirmToolDelete` checks actions; `confirmActionDelete`
checks jobs whose status is not deleted. `performDelete` then deletes the selected
public ID without repeating either dependency check. Deleted jobs are still
durable historical/financial records. The ToolAction migration stores `tool_code`
without a Tool foreign key; MlJob's tool/action foreign keys use `nullOnDelete`;
pricing dependencies include cascades. Other constraints, such as usage-event
restrictions, can reject some deletions, but do not implement a complete guard.

Later work: one server-side dependency policy, rechecked at execution under an
appropriate transaction/lock. Count historical and deleted jobs, all channel
pricing/entitlements, usage, financial references and stored-result dependencies.
Prefer deactivation/retirement when there is any retained history. Do not treat a
warning modal or a database exception as the invariant.

### P0-05 — Provider reference correction commits before verification

`attachCorrectReviewProviderReference` saves a changed `fib_payment_id` before
provider reconciliation. `normalizePaidReconciliationPayment` checks subscription
reference reuse but saves a changed `fib_subscription_id` before fetching and
validating provider state. A lookup/validation failure can leave the new reference
attached even though correction failed. The one-time no-refill reference path
does not itself verify payment with the provider before reporting success.

Existing fulfillment still has paid/fulfilled guards; this finding does not mean
all these paths blindly issue credits. Later work should validate the candidate
reference/object/customer/product against provider evidence first, then atomically
attach and record the approved transition, preserving the previous reference on
failure. Record distinct correction attempts and actor IDs.

### P0-06 — Add-on purchases lose the explicit product relationship

`AddonPurchaseService::purchase` creates `CreditOrder` with `source_type` and
product code/name metadata, but does not populate `credit_product_id`.
`CreditProduct::orders` and the Admin delete guard query that foreign key. Thus
orders created by this path are not counted by that relationship, even though
credits were issued for the product. This also affects the provider fulfillment
path that delegates to the same purchase service. Metadata retains some evidence,
so this is not a claim that all purchase identity is lost.

Later work: preserve the explicit product link on future writes and include
unfulfilled polymorphic Payment references in dependency checks. Any repair of
existing rows requires a separately reviewed, unambiguous data reconciliation;
do not run seeders or match mutable display names as a shortcut.

### P0-07 — Manual storage/add-on corrections can count as paid revenue

Admin storage/add-on actions send `admin_manual`, actor/note and `paid_at`, but do
not supply a non-revenue classification or require a linked verified Payment.
Their domain paths create paid CreditOrders using catalog amounts. Dashboard and
customer paid-order totals include these unless `meta.revenue_excluded` is set.
Unlike these paths, the manual service-plan grant correctly creates no Payment or
paid CreditOrder. Also, `CreditOrder::isRevenueExcluded` recognizes more metadata
forms than `scopeRevenueIncluded`, creating two definitions of exclusion.

No assertion is made about whether a particular manual adjustment represents
actual externally collected money. The defect is the absent explicit distinction.
Later work: distinguish verified paid correction from no-revenue grant, link any
real payment evidence, use one revenue predicate, and retain an auditable reason.
Do not retrospectively reclassify every manual row without evidence.

### P0-08 — Dependency protection is incomplete beyond Tools

Plan deletion rechecks subscriptions, prior subscriptions, pricing, entitlements,
voice access, grants and CreditOrders. Storage checks subscriptions; add-ons check
orders; coupons check redemptions; methods check PaymentIntents/CreditOrders. These
are useful guards. They do not consistently include the newer domain Payment
polymorphic purchasable references or unfulfilled/pending purchases. A pending
payment can exist before an order/subscription/redemption is created. Product/code
edits also lack a general historical-reference impact check.

Later work: extend the final dependency policy to all financial representations
and concurrent checkout, preserve referenced identities, and use retirement when
history exists. Do not weaken the current guards.

### P1-01 — Entitlement scope synchronization is incompatible with API V2

`syncApiScopeForEntitlement` maps an API entitlement through
`CustomerApiAccessService::scopeForActionCode`. Examples are `xomni.generate` →
`tts:apollo-1-5v`, `clone_xomni.generate` → `tts:vector-1-5`, and both STEM actions →
`stem:generate`. There is no mapping there for Apollo 2, Vector 2 or Leo.

`V2\ApiCatalog::scopes` instead recognizes `v2:<service>`, `v2:*`, `*`, or supported
family wildcards; it does not accept those legacy exact scopes. Therefore toggling
an API entitlement does **not** establish V2 access unless a separately configured
scope already permits it. Manual V2 scopes in the plan textarea can work; the
whole API is not intrinsically broken by this editor mismatch.

Additional synchronization defects: only the edited row's new plan/action/channel
is considered, so moving a row can leave old scope state; removing one STEM action
removes the shared legacy scope without considering the other; saving entitlement
and plan scope is not one transaction. `all` channel rows do not trigger API sync.

Later work: make the intended plan-scope policy explicit, use the current API
catalog, account for sibling variants/modes and old/new targets, and show effective
access as the intersection of plan scope, key scope and API action entitlement.
Do not overwrite deliberate plan scopes with an unconditional broad wildcard.

### P1-02 — Catalog editing does not describe the current V2 product reliably

Existing Tool/ToolAction identifiers are correctly immutable in their edit forms.
However, new Tool validation allows only lowercase letters/numbers/underscores,
so the generic create form cannot represent current hyphenated codes such as
`xomni-v2` or `vector-v2`. Seeders/migrations can create them and existing records
can be edited; this is not evidence that those records are missing.

The tool editor does not project the V2 catalog's family/model, route, endpoint
contract or current/legacy status. Editing a database label does not update
`config/metkurd_v2.php` presentation. Caption defaults differ by initializer.
Later work: add a current-catalog coverage/read model, preserve code immutability,
and validate creation against actual identifier conventions rather than renaming
existing records.

### P1-03 — Effective pricing and API configuration can be misleading

Three-channel pricing writes are not atomic and primary-row toggle/delete can
expose supported fallback rates. A free plan may be saved with API fields enabled,
but `CustomerApiAccessService::configForCustomer` forces free-plan API access/RPM/
concurrency off. Plan scope help text supplies older endpoints. Neither the plan
nor entitlement page explains the complete effective result.

Later work: atomic group writes, field/condition validation based on real consumers,
effective quote/access previews, and explicit raw-versus-effective configuration.
Retain current billing policy until separately approved.

### P1-04 — Current Admin routes select the wrong translation area

`AppServiceProvider` sets `aurl` to `adm`, while
`LocalizationMainMiddleware::detectArea` only recognizes localized `super-admin`
paths as Admin. Current `/{locale}/adm/...` page requests therefore fall through
to the Landing area in that resolver. This is an actual source mismatch, not only
a hypothetical custom-prefix risk. Correct route-aware area selection alongside
catalog coverage; do not change Landing content to compensate. See section 9.

### P2-01 — Operational evidence and sensitive-data presentation need boundaries

The register exposes raw serialized `callback_payload` and `status_response` in
readonly textareas (`selectedReviewPayment`). Method configuration exposes settings
and metadata JSON. No field-level redaction is performed at these render boundaries;
some reconciliation errors also interpolate raw exception messages. HTML escaping
prevents markup execution but does not remove credentials or signed URLs if present.
No real payload or credential was inspected, so this is an unredacted boundary,
not a confirmed production secret leak.

Later work: allowlist operational fields before serialization into Livewire/browser
state; redact sensitive nested values and exception diagnostics. Keep legitimate
provider names, object IDs and reconciliation evidence for authorized operators.
If deployed payloads contain secrets, treat remediation as P0.

## 5. Customer state and wallet review

| Area | Exists now | Gap / interpretation |
|---|---|---|
| Identity/profile | Username, email, UID, profile/contact/verification context; verification support email | No complete customer change/audit timeline; mail/status actions need capabilities and actor history |
| Active/suspended | Customer status toggle and suspended restore | Immediate status mutation without reason/consistent confirmation; do not infer that suspension cancels existing paid jobs or revokes every key |
| Plan/subscriptions | Active service/storage plans, recent subscription history and manual correction forms | Current relation/default-plan resolution and historical transitions need one explanatory view |
| App wallet | Explicit App balance and subscription/add-on buckets in register; wallet lifecycle totals loaded | Directory/quick summaries also use generic Balance wording; ledger drill-down missing |
| API wallet | Separately loaded `apiWallet` and explicit register balance/buckets | API reservations, settlement/release and effective spendable state not linked in Admin |
| Storage | Customer usage and storage subscription/quota context | No unified temporary API retention, permanent result accounting and over-quota explanation |
| Service access | Plan/entitlement catalog pages | No customer → effective plan/override → action → App/API availability trace |
| API access | Plan enabled/scopes/allowance/RPM/concurrency controls | No effective customer/key scope intersection or key status/usage view |
| Jobs | Counts, usage breakdowns and bounded recent job lists | No searchable job evidence/detail, channel filter, failure stage or safe recovery workflow |
| Purchases | Bounded recent paid orders and Payment review for a focused customer | No linked all-customer payment → fulfillment → wallet ledger timeline |

`InteractsWithCustomerAdmin::baseCustomerRelations` loads App and API wallet
relations separately. **No combined App-plus-API balance or cross-wallet overwrite
was confirmed in the reviewed Admin controls.** The register labels both wallets
explicitly. Generic summary wording and the App-only add-on operation are operator
clarity risks, not proof of a merged wallet implementation.

The manual add-on path calls `CreditService::addAddonCredits` without an API wallet
argument, so its default App wallet is credited. No dedicated scoped Admin API
top-up, arbitrary deduction, refund-issuance or credit-ledger editor was found.
Do not add these as generic CRUD controls simply to fill a dashboard gap.

`syncCustomerCreditsToPlan` is a financially meaningful correction: it tops up both
App and API subscription buckets under CreditService locking, leaving add-ons and
larger balances intact. An immediate repeat at the allowance is a no-op; after
normal consumption, another sync can replenish spent credits again. It is not a
cycle-idempotent scheduled grant. It supplies actor/reference metadata but no
operator-entered reason. Existing tests intentionally cover this policy.

Manual plan upgrades have a tested full-new-allowance correction policy; do not
silently replace it with a difference-only formula. Plan editing itself preserves
existing customer wallets. The reviewed positive grant/sync methods do not expose
a negative adjustment control or deliberately drive a wallet below zero; this is
not certification of every historical balance or concurrency path.

## 6. Payments, financial flow and dangerous operations

### 6.1 Domain flow to retain

| Operation | Source flow | Wallet/ledger result |
|---|---|---|
| Provider service-plan purchase | Payment purchasable → provider status reconciliation → `FulfillPlanSubscription` → `PlanSwitcher` → CustomerServiceSubscription / CreditOrder | Existing plan credit allocation services maintain separate App/API buckets and ledger/grant semantics |
| Provider add-on purchase | Payment → `FulfillAddonCredits` → `AddonPurchaseService` → CreditOrder | App add-on credits via CreditService; `ADDON-<order-id>` ledger reference; missing explicit product FK noted above |
| Provider storage purchase | Payment → `FulfillStorageSubscription` → `PlanSwitcher` → CustomerStorageSubscription / CreditOrder | Storage entitlement/quota changes, not API credit allocation |
| Manual service plan | Register → `ManualServicePlanGrantService`, then CreditService sync | No-revenue manual subscription; separately synchronized App/API subscription buckets |
| Manual add-on | Register → `AddonPurchaseService` with admin metadata | Paid order + App add-on ledger; duplicate/revenue concerns above |
| Manual storage | Register → `PlanSwitcher::switchStoragePlan` with admin metadata | Paid order + storage subscription; no file deletion in this action |
| API V2 job | API key/scopes/plan checks → ApiSubmission → ApiJob + native MlJob → API reservation → local completion/sync | API-only reserve/settle/release entries; not a Payment purchase or App debit |
| Failed App job | Native lifecycle → verified debit/refund service | Job refund is distinct from a payment-provider cash refund |

`ConfirmFibPayment` delegates to existing synchronization. Fulfillment actions lock
Payment and reject already-fulfilled/non-paid rows. Subscription reconciliation
also has provider evidence and subscription-application checks. The no-refill
recurring repair validates paid evidence and an appropriate existing subscription
before marking linkage/application state. These guards must remain; the Admin
weaknesses above are not a reason to replace them with status/credit assignments.

Provider/internal/local status, purchase type, product/plan, recurring versus
one-time mode, review reason and raw response data are available in the register.
Only recent selected-customer records are shown. Failed/pending/review work is not
presented as a complete cross-customer queue. A payment method's `supports_refunds`
checkbox is capability metadata, not an implemented refund operation.

### 6.2 High-impact action inventory

All rows below currently have the common `auth:admin` boundary and no finer scoped
permission. UI confirmations are described separately from server invariants.

| Action | Confirmation / reason | Audit and idempotency | Financial/customer effect and next safeguard |
|---|---|---|---|
| Suspend/restore customer | Direct button; no required reason | Direct status update; no dedicated change event | Future access impact; authorize, explain effect, recheck target/status |
| Manual service-plan grant | Native confirm; reason category, detail for Other | Actor/source/reason in manual subscription; separate credit-sync ledger; no single replay identity | Changes plan/period and both credit allowances; atomic/recoverable intent |
| Sync plan credits | Native confirm, no entered reason | CreditService ledger/admin metadata; immediate no-op is not lifetime idempotency | Can replenish consumed App/API subscription credits; explicit reason and one-use intent |
| Manual add-on | Direct form action; required note, optional provider reference | Actor/note on order/log; ledger references order; new order per call | App-only credit issuance and paid revenue record; explicit wallet/classification and replay protection |
| Manual storage | Direct form action; required note, optional reference | Actor/note metadata/log; no one-use operation | Replaces storage subscription/period, creates paid order; explicitly represents `provider_schedule` despite manual source, requiring lifecycle review |
| Reconcile paid subscription / quick repair | Native confirm for reconciliation; explicit mode/reason, local-paid acknowledgment when applicable | Domain fulfillment guards; admin metadata/events; no-refill and apply-once distinguished | Preserve provider proof and prevent duplicate credit/subscription application |
| Attach corrected provider reference | Native confirm; required reason/mode | Event key uses payment + mode; some writes precede verification | Validate candidate before commit; retain original reference and each attempt |
| Mark review invalid/expired | Native confirm; required reason | Payment row lock/event; no eligible-source-state check | Can mislabel fulfilled payment; P0-02 |
| Mark internal/non-revenue | Native confirm; required reason | ManualRevenueReclassificationService validates customer, reason and manual/internal evidence; history retained; UI passes `reverseCredits=false` | Changes revenue classification, does not refund; keep evidence checks and add actor-complete event |
| Catalog/price/access activation or edits | Mostly direct save/toggle; no uniform impact reason | Direct Eloquent writes; model cache hooks where provided; sparse actor history | Future access/charge/checkout impact; policy, before/after event and effective preview |
| Catalog deletion | Bootstrap dialogs; final guards vary | Some final dependency checks exist; Tool checks absent at execution | Historical integrity risk; final shared policy and retirement |
| Currency/rate change or deactivation | Direct form/button; source field, no uniform correction reason | Transaction updates currency/current rates; no full actor audit | Future display/payment snapshots affected; constrain target/pair and record revision |
| Refund/revoke-key/arbitrary deduction/delete financial record | No corresponding scoped Admin execution surface found | Not applicable | Do not imply these capabilities exist from labels or model fields |

`PaymentEventRecorder` uses `firstOrCreate(event_key)`. Some Admin handlers reuse
payment/mode-based keys across distinct later corrections and record before/after
status from already-updated state. Several event payloads omit `admin_id` (though
other grant/reconciliation paths do include it). Existing events are useful but
not a complete immutable administrative action log. Preserve them and add an
actor-attributed intent/result record with true pre-change values.

Currency edits are transactional and require positive rates/rounding values.
However, the public `editingRateId` is fetched by ID without rechecking its selected
currency pair. `deactivateRate` also does not apply the create/edit exclusion of
the base currency. These are additional final-action target-validation gaps; a
hidden option or selected row cannot be the invariant.

## 7. API V2 operational compatibility

The API's six service scopes cover the nine product variants/modes. Do not invent
nine unrelated API scope strings: models and STEM modes resolve to separate
ToolActions, while scopes cover API service families.

| Requirement | Existing source/domain | Current Admin capability / necessary next step |
|---|---|---|
| Environment enablement | API feature gate/configuration; deployment prerequisites in API-V2.md | No Admin deployment toggle; retain deployment-owned activation |
| Customer API access | Active customer, eligible paid plan, API enabled and positive RPM | Plan fields exist; expose effective denial reason on customer view |
| Service permission | ApiCatalog + plan scopes + key scopes + `isAllowed(action, api)` | Entitlement synchronization is legacy; repair P1-01 and show full intersection |
| API credits | `api_monthly_credits`, typed CreditWallet and CreditLedger | Plan and register balances exist; no reservation/settlement ledger trace |
| Rate/concurrency | Plan `api_requests_per_minute`, `api_concurrent_jobs`; submission guards | Editable plan fields; no effective customer utilization/current-slot view |
| Keys | CustomerApiKey metadata, hashed secrets and customer key lifecycle | No scoped Admin key list; future view only name/prefix/status/scopes/last usage; never full secrets |
| Durable API jobs | ApiJob links native MlJob; ApiUsageLog and sync services | No linked Admin job evidence view or App/API filter |
| Reservations | ApiCreditReservation + CustomerApiCreditReservationService reserve/settle/release with API wallet locking | No held/settled/released state, amount or linked ledger visibility |
| Unknown submission | Native MlJob ambiguous-acceptance metadata; conservative reconciliation | No searchable unknown-submission queue; do not add blind paid retry or premature refund |
| Results/retention | ApiResultFile, temporary/permanent storage mode and expiry; CustomerFile for owned persisted storage | No combined retention/accounting view; show mode, expiry and persistence state before considering any controls |

Temporary result TTL is deployment configuration (`customer_api.temporary_file_ttl_days`,
default 7 in the current submission path). It is not an existing per-customer Admin
retention setting. An API job list must distinguish reserved, settled and released
amounts rather than calling any single MlJob charge field final API spend.

Minimal next capability is read-only linked evidence: customer → ApiJob → MlJob →
reservation/ledger → owned artifacts. Any later recovery operation needs its own
allowed state transition, actor/reason and replay/financial safeguards. Closing a
provider-unknown case by age alone is incompatible with the current lifecycle.

## 8. Admin UI/UX and provider terminology

The current Bootstrap/Livewire architecture can remain. A customer-V2 visual clone
is unnecessary. Source-observed issues worth addressing:

- The navigation separates Services, Customers and Packs, but actionable payment
  review is under Customers → Register. Directory, ranking, register and usage
  repeat customer context without one linked operational timeline.
- No current-V2/legacy filter or family/model grouping exists in Tools. Plan API
  help examples advertise legacy services. Generic Balance labels in summaries
  obscure the explicit wallet distinction visible in the register.
- Job views emphasize aggregates and short recent lists. They do not distinguish
  job lifecycle, output persistence, credit settlement and provider uncertainty as
  separate states. Do not equate provider completion with a persisted local result.
- Catalog editors use Bootstrap modals, notifications use Toastr, and seven native
  `confirm()` call sites exist in the billing register (two are credit-sync entry
  points). No native `alert()`, `prompt()`, `wire:confirm`, or scoped SweetAlert
  implementation was found. Other impactful actions execute directly.
- The shared layout has top-level inline event registration and `const dispatchToast`
  without a navigation-once guard on that block. Repeated initialization and
  listener/redeclaration behavior need Livewire-navigation browser verification;
  no actual navigation failure was reproduced in this audit.
- Responsive table wrappers and empty-state branches exist, so this is not a
  finding that all tables lack them. Dense correction forms, wide JSON fields,
  pagination, focus return, keyboard use and narrow/RTL layouts need browser QA.

Recommended later confirmation approach: one Admin-owned SweetAlert bridge using
the existing bundled asset and Bootstrap/Livewire lifecycle, translated text,
keyboard/focus handling and explicit high-impact summaries. Retain inline field
validation. A confirmation must accompany, not substitute for, server permission,
target/state revalidation and financial operation identity. Review the existing
native-confirm test when changing this implementation; do not weaken the safety
assertion merely to remove a string match.

| Provider-related content | Classification |
|---|---|
| Provider name, endpoint alias, provider job/object ID, status, failure stage, reconciliation time | Useful operational information for authorized Admin users |
| Full opaque response documents, repeated transport detail in routine lists | Unnecessary technical noise; present selected evidence/detail when needed |
| Credentials, full API keys, bearer tokens, signed URLs, secret nested settings or request headers | Sensitive; exclude/redact before reaching browser/component state, regardless of Admin role |

The customer-facing rule against infrastructure provider names does not prohibit
legitimate internal Admin diagnostics. Do not globally replace provider terminology.

## 9. Localization: EN/AR/KU

Only the Admin catalogs were analyzed. All three JSON catalogs parse and contain
766 keys with matching key sets. A static literal-call scan of scoped Admin views,
auth/layout/shared files and non-Landing Admin support traits found **1,304 distinct
literal `__()`/`@lang()` keys; 590 are absent from each Admin catalog**. This is a
source scan, not a runtime translation-coverage percentage: dynamic keys, validation
messages and other translation forms are not fully counted.

Confirmed absent examples include `Customer Billing Register`, `App Wallet Balance:`,
`API Wallet Balance:` and `Phone Registration Countries`, plus newer correction
instructions and alerts. These calls are translation-ready but fall back to their
English source strings. Sixteen Arabic and 27 Kurdish catalog values equal the
English value; some are legitimate brands/technical terms, so equality alone is
not a missing-translation defect. Source English scope placeholders are also stale.

The layout emits `lang` but no document-level `dir`, loads the LTR Bootstrap asset,
and defines an `.ar-shift` helper rather than a complete AR/KU direction strategy.
Technical codes and IDs need isolated LTR; customer names/content need appropriate
automatic direction. Visual RTL, modal alignment and mixed-content selection are
unverified.

`LocalizationMainMiddleware::detectArea` hardcodes the localized `super-admin`
path, while `AppServiceProvider` currently sets `aurl` to `adm`. Current localized
Admin requests consequently select the wrong area (P1-04). The unlocalized sign-in
route also is not
matched by that localized Admin pattern. Audit/fix area selection based on actual
routing, including Livewire update requests; do not import Landing catalogs to
mask missing Admin keys. No Landing translation content was reviewed.

Later work: catalog coverage for literal and dynamic messages, consistent source
wording, EN/LTR + AR/KU/RTL layout behavior, mixed-direction IDs/content, localized
confirmations, and native-speaker/browser acceptance. No catalogs were changed.

## 10. Performance and verification limits

These are source-level growth/query findings, not measured production latency:

1. `InteractsWithCustomerAdmin::customerDirectoryOptions` loads all customer IDs,
   usernames and emails for selection. The register/usage selectors can grow with
   the full customer population despite paginated main tables. Use bounded remote
   search later; loading a full directory is unnecessary for selecting one customer.
2. `ManagesServicePricingPage::groupedPricingRules` loads matching rules, groups and
   filters them in PHP, and only then constructs a LengthAwarePaginator. Page size
   does not bound the source result set. Preserve group/fallback semantics when
   moving work into bounded queries or a purpose-built read model.
3. Currency rows load the currency/rate sets and invoke `Schema::hasColumn`
   repeatedly inside each mapped row, alongside per-quote derived-rate resolution.
   Cache request-local schema capability checks and profile rate lookup reuse.
4. Customer/directory/ranking/dashboard/tool views use multiple count/sum/max
   aggregates. Many are eager/subqueries, not a proven classic N+1. Profile actual
   filtered query plans, date/channel predicates and indexes with representative
   data before selecting an index migration. No missing-index claim is made here.
5. Recent customer job/order/payment/subscription lists are bounded (including
   12 selected-customer Payments and short recent job lists); most main tables are
   paginated, and dashboard aggregates use caching. No full payment-history load
   was found in the reviewed selected-customer view. Do not replace bounded lists
   with eager full histories when adding operations detail.

Usage aggregates exclude deleted jobs. This is reasonable for some activity views
but cannot substitute for a complete financial ledger/history total. Label their
scope explicitly before using them for reconciliation or customer statements.

Existing test source reviewed under `tests/Feature/Admin`:

| Existing test file | Evidence it already intends to protect |
|---|---|
| AdminBillingControlsTest | No-revenue manual plans, reason checks, App/API correction policy, reconciliation/refill safeguards and existing scope/group behavior |
| AdminCustomerPaymentsReviewTest | Customer review display and invalid-review resolution |
| AdminServicePricingTableGroupingTest | App/Mobile/API grouped rows and preservation of legacy fallback rows |
| AdminPaymentPlansCreditsTest | Separate plan allowances, stored plan values/cache behavior and preservation of existing wallets |
| AdminHomeDashboardTest | Revenue currency consistency and explicitly excluded non-revenue rows |
| CouponAdminTest | Coupon editor and recurring/target compatibility |

Tests were **read, not executed or modified**. Existing test coverage does not prove
the newly identified direct-action, replay, concurrency, pending-dependency, V2
scope or visual/RTL cases. Any later execution must use the isolated environment
specified in AGENTS.md, never the configured customer database.

## 11. Recommended implementation phases and acceptance

No phase below has been implemented by this audit. Sequence is intentionally
financial integrity first, current-product correctness second, then operations/UI.

### P0 — Financial, authorization and historical data integrity

1. Define minimum capabilities for read-only support, customer access changes,
   catalog/pricing administration and financial correction. Enforce on final
   Livewire methods and active-admin sessions. Do not assume new role names exist.
2. Specify eligible payment transitions and provider-reference corrections; move
   stateful execution into domain actions with locked rechecks and verified
   evidence. Preserve fulfilled records and the safe no-refill/apply-once split.
3. Add administrative operation identity, true before/after records and actor/reason
   to financial corrections. Make manual plan plus wallet sync atomic/recoverable.
4. Distinguish paid correction from no-revenue grant; fix future product linkage and
   unify revenue predicates. Review historical data separately without blanket repair.
5. Consolidate final delete/identity guards across old and new payment/history
   representations; include pending checkouts and deleted jobs. Constrain currency
   edit/deactivation targets. Retain model cache hooks and normal wallet locking.

Acceptance: guest/inactive/unauthorized Admin calls denied; modified component IDs
cannot bypass permissions/dependencies; paid/fulfilled payments cannot be expired
by the review action; failed reference lookup preserves the original; repeated and
concurrent intents allocate once; interrupted plan/wallet corrections recover
without double credits; App/API buckets and ledger totals remain separate;
pending/historical dependencies prevent unsafe deletes; existing V1 and provider
fulfillment regression tests still pass. No production data repair is implicit.

### P1 — Correct current V2 management

1. Project all nine variants/modes from the actual V2 catalog beside immutable
   ToolAction bindings, with current/shared/legacy classification and missing-row
   diagnostics. Make create validation compatible with actual code conventions.
2. Resolve the plan-scope synchronization policy using ApiCatalog; handle sibling
   variants/modes and entitlement moves atomically. Correct legacy help examples.
3. Add effective customer access/quote previews including overrides, fallback rows,
   scope intersection and free-plan/API gating. Make grouped price writes atomic.
4. Correct Admin translation-area routing for the actual prefix and Livewire
   lifecycle before relying on the Admin catalogs.
5. Describe limits through actual native consumers and deployment caps; expose
   intelligent-feature availability only after confirming an intended domain rule.

Acceptance: all nine actions resolve correctly for App/API channels; Apollo/Vector
   models remain distinct; Leo is not QASR/WASR relabeling; STEM uses sep2/sep4;
   scope grants/revocations preserve sibling access and deliberate scope policy;
   legacy channels/data remain compatible; effective prices match native quotes;
   invalid grouped writes leave no partial rates. Do not change actual prices.

### P2 — Necessary operational visibility

1. Add bounded customer detail and wallet ledger views with explicit App/API labels,
   plan/access provenance, storage usage/quota and linked payment/fulfillment history.
2. Add read-only MlJob/API detail and queues for provider unknown, refund pending,
   deletion failure and payment review. Include IDs, customer, service/model/channel,
   timestamps, local persistence and financial state without secrets.
3. Show key metadata, reservation states/amounts and temporary/permanent API results.
   Add recovery controls only after separately specifying each safe domain operation.
4. Redact response/settings/exception fields before serialization. Replace unbounded
   customer selectors and PHP-wide pagination; measure representative query plans.

Acceptance: one customer/job/payment can be traced end to end without querying a
   completed GPU job again; reserved and final spend are distinguishable; full key
   secrets and signed URLs never appear in Admin component state; filtered lists
   remain bounded; unknown submissions cannot be blindly retried/refunded.

### P3 — Interface, confirmation and localization

1. Clarify Billing Register navigation and reuse customer context without redesigning
   Admin to match the customer application. Separate job, storage and credit states.
2. Introduce one translated, navigation-safe confirmation bridge with concrete
   customer/wallet/amount/period summaries, focus handling and server-side safeguards.
3. Complete Admin EN/AR/KU messages; fix locale-area detection, document direction,
   technical LTR isolation and dynamic content direction. Remove stale scope examples.
4. Browser-check desktop/mobile tables, filters, pagination, empty states, long names,
   repeated navigation, dialogs, errors and keyboard behavior in all three locales.

Acceptance: no native confirmation islands for the reviewed high-impact actions;
   exactly one event/operation per confirmed click; all new messages translated;
   RTL works in the browser; cancelled dialogs cause no mutation. Preserve the
   current Admin styling architecture and legitimate provider diagnostics.

### P4 — Legacy cleanup and retirement

Inventory actual Tool/ToolAction rows and external callers against A/B/C/D, including
deleted jobs, financial relationships and stored artifact identities. Add retirement
metadata/filters only under an approved design. Remove unreferenced old controller
code only after dependency verification. No category C/D deletion, action renaming,
wallet migration or V1 route removal is authorized by this plan.

Acceptance: dependency report reviewed; current/historical result access and V1
compatibility preserved; any eventual data migration has explicit approval,
verification and recovery procedures. A clean-looking Admin catalog is not evidence
that historical records are disposable.

## 12. Likely future files/modules to modify

These are implementation targets, **not changes made in this audit**. New policies,
domain actions, audit storage or read-model files should be named during design;
no schema migration is assumed before reviewing existing facilities.

| Phase | Likely files/modules | Purpose |
|---|---|---|
| P0 | `routes/web.php`, `bootstrap/app.php`, `config/auth.php`, `app/Models/User.php`, `app/Policies/`, Admin auth component, scoped `app/Support/Admin/Manages*Page.php` and inline currency/country components | Authentication state, per-action authorization and final target checks |
| P0 | `app/Support/Admin/ManagesCustomerRegisterPage.php`, `app/Services/Billing/{ManualServicePlanGrantService,CreditService,PlanSwitcher}.php`, `app/Services/Payments/AddonPurchaseService.php` | Durable manual operation, transaction/replay behavior, explicit wallet/revenue semantics |
| P0 | `app/Domain/Payments/Actions/`, `app/Domain/Payments/Support/{PaymentEventRecorder,PaymentTransitions}.php`, `app/Services/Payments/ManualRevenueReclassificationService.php`, `app/Models/{CreditOrder,CreditProduct}.php` | Verified transitions, audit completeness, product references and revenue predicates |
| P0 | `ManagesServiceToolsPage`, `ManagesServicePricingPage`, `ManagesPayment{Plans,Addons,Storages,Coupons,Methods}Page` traits; relevant model relationships | Final dependency protection, identity stability and atomic grouped changes |
| P1 | `app/Support/Admin/ManagesService{Tools,Entitlements,Pricing}Page.php`, `ManagesPaymentPlansPage.php`, corresponding service/payment Blade views | V2 representation, scope policy, effective quote/access and typed limits |
| P1 | `app/Support/MetKurdV2ToolCatalog.php`, `app/Services/CustomerApi/{CustomerApiAccessService,V2/ApiCatalog}.php`, `app/Models/{Customer,PlanEntitlement,PricingRule}.php` | Reuse current mappings/resolvers; change only where the approved design requires it |
| P2 | `InteractsWithCustomerAdmin`, customer list/register/usage/ranking and dashboard traits/views; new bounded detail/read-model modules | Customer, wallet, job and payment operational evidence |
| P2 | `app/Models/{MlJob,ApiJob,ApiCreditReservation,CustomerApiKey,ApiResultFile,CreditLedger}.php`, `app/Services/CustomerApi/`, job lifecycle services | Reuse durable linked state; add recovery only under explicit domain contracts |
| P2/P3 | `resources/views/admin/layouts/app.blade.php`, shared Admin navigation/components, scoped page views, existing Admin notification assets | Redaction presentation, consistent navigation/confirmations and lifecycle-safe initialization |
| P3 | `app/Http/Middleware/LocalizationMainMiddleware.php`, `resources/lang/admin/{en,ar,ku}.json`, relevant shared Admin styles | Correct area, translations and direction; no Landing catalog changes |
| All | `tests/Feature/Admin/` plus relevant existing billing/API/native lifecycle tests | Focused regressions and compatibility checks in an isolated test environment |
| P4 | `app/Http/Controllers/Admin/` and source-verified unused modules; separately approved data inventory | Conditional cleanup only; no blanket legacy retirement |
| Documentation | This audit and the relevant `docs/metkurd/*.md`, then AGENTS.md when implementation changes engineering guidance | Record implemented behavior and verified acceptance, not unimplemented promises |

Implementation should begin with a small reviewable P0 change set. This audit does
not authorize deployment, billing-policy changes, historical data correction,
feature activation, or removal of compatibility records.
