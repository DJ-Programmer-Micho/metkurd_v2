# MetKurd engineering context

## Post-cutover billing epoch — 2026-09-15

Current financial reads and processing use BillingReportingBoundary from the committed
cutover audit (timestamp plus retired payment/order watermarks). CustomerBillingStateService
and shared effectiveAt scopes apply BillingSubscriptionAuthority: valid current online
coverage, bounded manual grants, bound active cash agreements, otherwise Free. Retained
wallet balances never confer a plan; old/expired manual terms cannot refill carried credits.
Do not mutate historical rows to fix display. Jobs, usage, files and credit activity remain
continuous. Admin financial history defaults to current with a separate Legacy / Pre-V2
History selector. Read [the production runbook](docs/metkurd/V1-TO-V2-PRODUCTION-RUNBOOK.md).
PaymentDomainCutover now shares one mutation algorithm across explicit configured
local-rehearsal/production identity policies. It is disabled by default; production
requires native MySQL, complete Admin/migration readiness, confirmed backup/restore,
resolved provider obligations and stopped writers. No CLI identity bypass or automatic
provider disposition is allowed. Native MySQL/deployment acceptance remains separate.
Local synthetic Payment 176
(customer 1083) is mock evidence, not real revenue; never promote it or poll its reference.


## Local business cutover rehearsal — 2026-09-15

Read [BILLING-DOMAIN-CUTOVER.md](docs/metkurd/BILLING-DOMAIN-CUTOVER.md) before
operating `billing:cutover-reset-payment-domain` or changing current revenue reads.
This separate command intentionally retires all five payment-processing tables on
the explicitly authorized deployment target. It preserves credits, ledger/order/allocation
history and valid local/external access; it performs no provider or storage calls.
One transactional Admin audit stores the reporting boundary and reviewed mappings.
Current reporting uses that boundary; historical evidence does not. Do not weaken
the conservative reset or infer execution approval from source/tests. Operator
review, maintenance, stopped writers and fresh finance/reconcile authority remain
required. Production and post-cutover lifecycle acceptance remain separate.


## Recurring subscription action lifecycle — 2026-09-14

Read [RECURRING-SUBSCRIPTION-LIFECYCLE.md](docs/metkurd/RECURRING-SUBSCRIPTION-LIFECYCLE.md)
before changing recurring cancellation, expiry or plan replacement. Renewal state
and paid access are separate. Preserve committed cancellation intent before HTTP,
GET-only confirmation, retry of expired/superseded provider subscriptions and the
new plan's authority despite old cancellation failure. No new collection timestamp
means no extended coverage; late collections after cancellation require review.
Local reasons/dates/replacement IDs are never FIB cancel request parameters.


## Explicit payment-history reset — 2026-09-14

`billing:reset-payment-history` is a separate maintenance-only operator reset,
default dry-run. Read [PAYMENT-HISTORY-RESET.md](docs/metkurd/PAYMENT-HISTORY-RESET.md)
before changing or operating it. It automatically retains the latest active
(open or paid) Payment; no UUID argument is required. The review hash binds that
selection and aborts on changes. Schema inventory/locks must explicitly scope to
the active database. Execution requires matching review hash, active finance/reconcile Admin, reason, exact confirmation
and stopped-writer attestation. All dependencies must pass; no partial reset.
Keep the selected Payment/events, financial state and normalized history. Only
approved historical subscription/CreditOrder `payment_id` links may be detached;
allocation links and CreditOrder `payment_intent_id` always block deletion.
This intentional PaymentEvent-retention exception does not relax checkout,
abandonment, Admin review or the older legacy cleanup command. Implementation
does not authorize application/production execution. Native-engine acceptance
remains separate from isolated SQLite tests.

MetKurd is a multilingual AI service platform for speech generation and cloning,
transcription/captions, OCR, audio separation, and translation, with customer
credits, subscriptions, file storage, and history. **MetKurd V2 is the primary application and future production target.** The repository still gates `/{locale}/app-v2`
with `FEATURE_APP_V2` (default false); this does not prove production enablement.
V1 `/{locale}/app` is legacy and will be retired. Its continued presence is not
a requirement to modernize it or preserve obsolete behavior.

## Effective service plan / API scope consistency (2026-09-14)

Customer checkout abandonment uses AbandonedCheckoutEligibility's shared Admin
evidence guard plus stricter local-draft checks. Never turn unresolved provider
review into automatic expiry. Retain owned locking, keyed customer events, legacy
coupon reservation safety and provider history. V2 purchase UI no longer accepts
coupon codes. See [PAYMENT-CHECKOUT-V2.md](docs/metkurd/PAYMENT-CHECKOUT-V2.md).

Use CustomerBillingStateService for current normalized service-plan state. Customer
helpers and current relations share its eligibility rules; never trust a partial
loaded plan or a synthetic customers.service_plan_id. Agreements remain scheduling
records until normalized activation. API enablement, V2 scopes, per-action entitlements,
wallet balances and feature gates are separate checks. Legacy exact V1 scopes do not
automatically authorize V2 families. Admin can save explicit scopes separately from
prices through the existing pricing capability/reason/audit and SweetAlert controls.
See [EFFECTIVE-PLAN-CONSISTENCY.md](docs/metkurd/EFFECTIVE-PLAN-CONSISTENCY.md).

## Dated external service agreements (2026-09-13)

Read [SERVICE-AGREEMENTS.md](docs/metkurd/SERVICE-AGREEMENTS.md) before changing
cash/external agreements. Collections and instalments stay external: optional
agreement totals are not receipts or collected revenue. Preserve Admin finance
approval, durable intent identity, inclusive end-date presentation, deferred activation,
monthly allowance snapshots, no rollover, App/API separation, retained add-ons and
shared expiry cleanup. The new agreement migration must be applied by the operator;
source implementation does not authorize migrations or live agreement processing.

## Latest local follow-up (2026-09-06)

- The user reports all V2 services working in their service tests. Record this
  as user-reported local acceptance, not independent production or API acceptance.
- Vector and STEM FilePond initialization must handle initial registration,
  delayed library loading and Livewire navigation. Resolve the current component
  at upload time; use Livewire's temporary upload token and cancellation API.
- OCR uses one document-labelled status card. New uploads clear terminal editor
  results; active scans stay monitored. Invalidate computed job/status/text values
  when submitting, syncing or changing job identity so old results cannot render
  as the new job. Preserve this in future workspace changes.
- Local polling was repaired by applying the existing poll-coordination migration;
  local scanned-PDF probing was repaired by configuring `OCR_PDFINFO_BINARY`.
  Never generalize those local changes to production or commit local binary paths.
- API V2 remains disabled in the environment reported by the user. It can run
  locally: `FEATURE_API_V2` controls `/api/v2` independently of `FEATURE_APP_V2`,
  which controls the web portal. Use the activation runbook in
  [API-V2.md](docs/metkurd/API-V2.md#activation-runbook). An explanation or docs
  update is not an instruction to enable a gate or apply migrations.
- Latest UI regression run: 19 focused PHP tests plus 13 frontend tests passed;
  build, focused PHP lint/Pint and translation checks passed. New navigation/status
  changes still need interactive browser acceptance. See the dated follow-up in
  [PRODUCTION-AUDIT.md](docs/metkurd/PRODUCTION-AUDIT.md) for verification scope.

## Admin P2 operational reads (2026-09-06)

Operations and customer detail now read persisted local evidence through
AdminOperations. Preserve fresh admin.read and deeper finance/reconcile evidence
gates, App/API wallet separation, bounded tables and secret/content exclusion.
Never call GPU polling, API serializers that settle reservations, storage probes,
billing repair or recovery mutations from Admin history. Provider completion is
not local persistence; absent provider evidence remains unknown. Read the current
P2 section in docs/metkurd/ADMIN-AUDIT.md. P3/P4 and deployment acceptance remain open.

## Product scope (2026-09-06)

Admin P1 V2 correctness is implemented in source. Read the current P1 section in
[ADMIN-AUDIT.md](docs/metkurd/ADMIN-AUDIT.md) before changing catalog, entitlement,
pricing or plan controls. Derive variants/scopes from MetKurdV2ToolCatalog and
ApiCatalog. Preserve P0 invariants and `meta.admin_api_scopes` explicit/derived
ownership; pre-existing scopes are explicit, sibling grants and plan moves sync
transactionally. Use runtime resolvers for access/quote previews. P3/P4,
business pricing decisions, MySQL and browser acceptance remain separate.

For the designated final V1 database snapshot `eu-metkurd-v1-260906.sql`, follow
the [local import plan](docs/metkurd/PRODUCTION-DB-IMPORT.md). It has eight pending
migrations; their data migrations supply the new V2 catalog. General development
or billing seeders can overwrite production settings and are not an import step.
Historical billing findings remain unresolved. The P0 deletion guard's invalid
customer-column query is fixed in source: use the plan's unfiltered subscription
and previous-subscription relationships, never `customers.service_plan_id`.
Native MySQL acceptance remains separate from SQLite regression coverage. Read the
[routing review](docs/metkurd/ADMIN-ROUTING-REVIEW.md) before attributing missing
P0 schema to route damage; the subsequent P1 phase fixed the localization mismatch.

- Current core: Apollo, Vector, Leo, Caption, OCR, STEM 2 and STEM 4, plus V2
  Storage and shared infrastructure those services need. Report any additional
  genuine V2 service before adding it to this scope.
- Apollo 1.0v, Translation, Neo and other V1-only services are outside V2 readiness.
  Do not fix or optimize them unless a shared dependency is directly needed by V2.
- API V2 is now in scope: /api/v2 and the localized /app-v2/api portal. Read
  docs/metkurd/API-V2.md. API requests must use the native V2 core with an explicit
  SubmissionContext, server-probed inputs, existing API reservations and private
  owned results. FEATURE_API_V2 defaults false; do not enable rollout as a shortcut.
  Preserve programmatic V1 routes during migration; do not modernize their business logic.
- Billing/account/subscription modernization is deferred. Keep durable submission,
  debit identity, duplicate prevention and safe correction intact; do not redesign
  wallets, plans, packages, payment providers or billing UI in this phase.
- Optimize decisions for V2. Classify older dependencies as acceptable shared,
  temporary migration dependencies or obsolete coupling. Check V2 callers before
  removing anything; this direction does not authorize blanket V1 deletion.

## Read before changing code

Start with [architecture](docs/metkurd/ARCHITECTURE.md), then the relevant
[service map](docs/metkurd/SERVICES.md), [GPU lifecycle](docs/metkurd/GPU-JOB-LIFECYCLE.md),
[storage/cache](docs/metkurd/STORAGE-AND-CACHE.md), [infrastructure](docs/metkurd/INFRASTRUCTURE.md),
[localization](docs/metkurd/LOCALIZATION.md), [API V2](docs/metkurd/API-V2.md), and [decisions](docs/metkurd/CHANGELOG.md).
These describe repository evidence, not a verified live deployment. Inspect the
actual implementation, callers, configuration, migrations, and tests before
refactoring; older plans and comments can lag implemented behavior.
The [production audit](docs/metkurd/PRODUCTION-AUDIT.md) records unresolved release
issues and the limits of verification; it is not deployment approval.

## Navigation and stack

- PHP ^8.2, Laravel 12, Livewire 4 view-based components, Blade, Alpine, Vite 7,
  Tailwind 4 build dependencies; the application also loads bundled Bootstrap,
  FilePond, WaveSurfer, and SweetAlert assets. See `composer.json`, `package.json`.
- `resources/views/app/v2/`: V2 pages, layout, shared components. Most Livewire
  logic is inside `⚡*.blade.php`, **not** `app/Livewire/`.
- `resources/views/app/pages/`, `resources/views/app/partials/`: V1 workspaces
  and reused components. `resources/views/admin/` and `landing/`: other areas.
- `app/Services/MetKurd/Jobs/`: native V2 submissions and shared refunds;
  `app/Services/MetKurd/V2/`: provider adapter and workspace caches.
- `app/Services/{XTTS,ASR,OCR,STEM,Translation,Storage}/`: result synchronization
  and persistence; `app/Services/Providers/RunPodProvider.php`: HTTP transport.
- `app/Http/Controllers/App/Services/`: authorized preview/download endpoints.
  `app/Models/`, `app/Support/`, `app/Services/Billing/`: shared domain state.
- `routes/`, `config/`, `database/migrations/`, `tests/Feature/`: contracts and
  verification. `resources/lang/{app,admin,landing}/{en,ar,ku}.json`: translations.

## Production change rules

1. Protect V2 ownership, persisted data, job billing identities and correct V2
   worker contracts. Shared code must not silently fall back to V1 endpoints or
   payloads. Keep app/API wallets separate; do not preserve obsolete V1 behavior
   if it obstructs a safer or simpler V2 core. Presentation slugs are not database
   action codes (notably STEM). Trace V2 dependencies before changing legacy code.
2. `MlJob` is the shared customer job record; GPU workers compute, while MetKurd
   owns persisted customer results and their preview/download/history lifecycle.
   A safely persisted completed result must not require repeated GPU queries.
3. Do not blindly retry ambiguous paid submissions or assume all services have
   identical charge/refund/idempotency semantics. Inspect the service first.
4. Keep private results private; authorize customer/file/job access on the server.
   Preserve storage quotas, deletion safeguards, cache scoping, and invalidation.
   Do not enable `STORAGE_ALLOW_DESTRUCTIVE_OPERATIONS` or V2 rollout as a shortcut.
5. Never copy `.env` values, credentials, signed URLs, or sensitive runtime data
   into documentation, tests, logs, or commits. Config variable names are enough.
6. For customer text, inspect **all relevant files under `resources/lang/*`**,
   including PHP/JSON files if added. Use the existing translation system in
   Blade/PHP/JavaScript, with EN/LTR and AR/KU/RTL; use `dir="auto"` for mixed
   dynamic text where appropriate. Translate alerts, errors, labels, and modals.
   Customer wording must not expose RunPod; retain internal provider identifiers.
7. Admin P0 mutations must preserve fresh active-user capability checks, durable
   financial intent identity, locked payment transitions, final dependency guards
   and sanitized audit/display boundaries. Read [Admin implementation status](docs/metkurd/ADMIN-AUDIT.md)
   before changing these paths. Never automatically provision privileges, bypass
   provider evidence or backfill historical financial rows. P2 reads must not invoke
   these mutations. P4 legacy cleanup remains a separate phase.
8. Admin P3 high-impact controls use `resources/js/admin.js` with explicit
   `data-admin-method` / JSON arguments and translated impact text. Keep one
   navigation-safe SweetAlert bridge; do not add native dialogs or replace P0
   intent IDs during confirmation/retry. `AdminUiAccess` is a request-local display
   hint, never final authorization. Preserve customer-context bookmarks, bounded
   P2 queries, separate operational statuses and EN/AR/KU direction/copy. Run the
   Admin Node tests as well as relevant PHP tests when changing this bridge.

## Verification and documentation maintenance

- Run relevant existing Pest tests with isolated SQLite `:memory:`, array cache
  and session, fake storage, and mocked HTTP. `phpunit.xml` defines test defaults;
  check inherited environment/cached configuration before running DB tests.
  Do not run tests, migrations, cleanup, or billing operations against production.
- For lifecycle changes cover duplicate submission, failure/refund, ownership,
  persistence and terminal polling. V2 core/shared infrastructure must pass;
  unrelated V1/API failures are informational and not V2 release blockers. For UI/text
  changes check EN/AR/KU and alerts/direction; distinguish static checks from
  actual browser verification. Run `npm run build` when assets change.
- Use focused PHP lint/Pint checks for changed PHP; `composer lint:check` and
  `php artisan test` are the broader checks. Report failures and unavailable
  deployment checks honestly; do not rewrite unrelated code to silence checks.
- Before completing a material change to architecture, GPU contracts,
  endpoints/adapters, storage/cache, DB relationships, queues, service ownership,
  localization architecture, or billing/job lifecycle, check the corresponding
  `docs/metkurd/*.md` and update only if documented behavior actually changed.
  Record meaningful decisions in `CHANGELOG.md`, not routine copy/CSS edits.
