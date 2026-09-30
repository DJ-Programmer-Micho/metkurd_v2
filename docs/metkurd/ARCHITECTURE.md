# MetKurd architecture

## Batch provider disposition — 2026-09-30

The private CLI review bulk-loads provider obligations and excludes ordinary Free
rows. An immutable identity/source/evidence manifest plus separately selected
per-item decisions feeds one AdminOperationRunner parent. Existing individual paid
coverage authority is reused; unpaid DRAFT GET/merchant attestations append durable
evidence only. Shared retirement validation feeds both cutover inventories, with
stale/ambiguous evidence still blocking. See
[batch review and acceptance](PROVIDER-OBLIGATION-BATCH-REVIEW.md). No automatic
provider cancellation, fulfillment, financial repair or cutover authorization.

## Admin Developer read model — 2026-09-28

`AdminDeveloperWorkspace` composes existing `AdminOperations` queries into bounded,
allowlisted API/MCP/ML/key/reservation/result metadata on the existing Operations
routes. Persisted MCP connection identity distinguishes MCP from API while both
retain the API wallet. Typed customer-scoped evidence links and grouped reads do
not invoke runtime submission/result serializers, providers, storage or financial
recovery. MlJob and reservation authorities are unchanged. See
[Admin Phase 7](ADMIN-REDESIGN-PHASE-7.md) for privacy and acceptance boundaries.

## Customer application entry and V1 retirement gate — 2026-09-26

`CustomerAppDestination` owns customer navigation after password/social login,
verification, already-authenticated guest routes and generic dashboard links.
It consumes a valid local intended URL first, then prefers `app.v2.home`
(`/{locale}/app-v2`), falls back to `app.home` only if V1 is enabled, and uses
the localized landing home if both applications are disabled. EN/AR/KU are
preserved through guest entry and logout. Intended absolute URLs must match
the configured APP_URL origin exactly; only registered customer GET routes
are accepted. Login/logout loops, external URLs and ambiguous paths are rejected.
Local OAuth authorization intentions retain their query without changing MCP.

`FEATURE_APP_V1` defaults true in `config/customer_app.php`. The `app.v1.enabled`
middleware gates legacy workspace/account/purchase/payment pages and is persistent
on signed Livewire updates, blocking stale actions after disabling V1. Safe GETs
redirect to explicit V2 equivalents (profile, storage, billing, API, purchase/payment,
Apollo 1.5, Vector 1.5, Caption, OCR and STEM selection); other legacy pages use
V2 home. Disabling V2 retains its existing 404 boundary. V1-specific service links
remain V1 when enabled; generic dashboard/logo links prefer the current app.

Shared authentication/verification, payment status/refresh/cancel/return controls,
owned media/reference downloads and voice assets retain their existing protection
outside the V1 workspace gate because V2 also consumes them. Admin, REST API and
MCP boundaries are unchanged. No billing fulfillment or schema changes are involved.
See the [deployment matrix](V1-TO-V2-PRODUCTION-RUNBOOK.md#customer-application-flags--2026-09-26).

Verification: 167 distinct focused PHP tests passed across the destination, auth,
profile/onboarding, V2 payment/dashboard and affected legacy payment-return runs;
18 navigation/account/payment frontend tests also passed. The flag matrix exercises
EN/AR/KU, real Laravel/Livewire HTTP login, remember-me, intended URLs, logout,
social callback, password recovery, verification and stale signed V1 updates.
Completed-payment status checks preserve payment/wallet/ledger snapshots. Fixtures
used SQLite `:memory:`, array cache/session and mocked external services. PHP syntax,
focused Pint and whitespace checks passed. Interactive browser/deployment acceptance
was not performed; no asset source changed and no rollout flags were applied.

## Remote MCP external client — 2026-09-26

The independently disabled `/mcp` interface uses the official PHP MCP SDK and
Passport public OAuth/PKCE. `McpConnectionPrincipal` and REST's `ApiKeyPrincipal`
feed the same ApiSubmission, API wallet and native V2 jobs; ApiJobResult shares
the existing local serializer. Current active non-Free effective-plan authority and scoped consent
are rechecked at runtime. MCP serialization is persisted-state-only; durable API
reconciliation owns settlement and artifact links, while REST keeps its existing
sync behavior. CIMD retains the metadata URL as OAuth identity; preregistered
public clients remain supported with separate web/native redirect policies.
The separate customer MCP portal documents owned-file
handoff and revocation. No worker, endpoint or MCP wallet is introduced. See
[MCP contract and acceptance limits](MCP.md).

## Service-independent maintenance presentation — 2026-09-26

The standalone 503 view pre-renders local artwork and all maintenance translations.
Browser pathname selects Landing/App/Admin appearance and EN/AR/KU without normal
route localization. A dependency-free wrapper at the public entry point negotiates
JSON only after Laravel's native early script decides the response is maintenance.
It preserves the original bypass/redirect/status checks. The booted 503 exception
handler runs before auth-dependent area handlers. See [MAINTENANCE.md](MAINTENANCE.md)
and the updated runbook for the file-driver, pre-rendered operator procedure.

## Global V2 Process Queue — 2026-09-26

The V2 shell's Process Queue now has a bounded, read-only MlJob projection and
one navigation-managed adaptive updater. It observes locally persisted terminal
state and retains visual acknowledgement per customer/browser session; it never
performs provider reconciliation or storage probes. All current V2 App services
reuse their existing authorized workspace results. See [Process Queue](PROCESS-QUEUE.md).

## Harakat text service — 2026-09-21

OCR presentation now contains Scanner and Harakat 1.0, with independent actions
and worker contracts. Harakat uses existing durable submission, financial context,
poll coordination, reconciliation and CustomerOutputStorage. Its text-only page
submits one Tashkeel job and persists inline text plus private TXT before completion.
No separate storage/billing subsystem or public API route is introduced. See the
[Harakat contract and migration](SERVICES.md#harakat-10--2026-09-21).

## V2 browser navigation lifecycle — 2026-09-16

`resources/js/v2-navigation.js` owns page mounting, morph reconciliation and
cleanup before Livewire replaces or snapshots the page. Tool controllers register
their root, dependency preparation and scoped listeners instead of installing
another navigation handler. Assets wait for real load completion; FilePond inputs
are restored synchronously before history snapshots. Bootstrap's delegated
handlers load once. See [V2-NAVIGATION.md](V2-NAVIGATION.md) for the contract,
destination progress colors and local verification scope.

## Cutover deployment identity — 2026-09-15

PaymentDomainCutover keeps one business review/mutation algorithm. CutoverIdentity
selects local-rehearsal or production policies from explicit disabled-by-default
deployment assertions, without altering Laravel's connection. Production preflight
binds migrations, the exact active Admin's capabilities, backup/restore references
and persisted provider-obligation disposition into the review. Unresolved obligations
and still-valid coverage block deletion; no provider action runs in review/execution.
Local SQL hostname checking is not applied to RDS. See the production runbook for
the command contract and native MySQL acceptance still required.

## Effective authority and financial epoch — 2026-09-15

After the successful local cutover, BillingSubscriptionAuthority separates effective
service/storage access from preserved balances and provenance. The same eligibility
is used by CustomerBillingStateService, current Customer relations/helpers, checkout
application assessment, Admin metrics and cycle operations. Manual metadata deadlines
are strictly parsed; expired/ambiguous terms are not indefinite grants. Old provider
rows have no current authority unless an explicit audited provider-coverage disposition
is retained by the cutover (see below). New online coverage requires an owned, plan-matched,
paid current-epoch Payment and valid term. Cash access requires the actual agreement.
Lifecycle authority permits expiry of current obligations while refusing refills/expiry
mutations from grants already expired at cutover. Reads do not rewrite those rows.

BillingReportingBoundary is the single DB-backed epoch: committed Admin audit timestamp
plus retained payment/order watermarks. V2 Payment History previously merged detached
CreditOrders without this filter; both UNION branches now use it. Current Admin financial
reads, checkout blockers, provider reconciliation and fulfillment use the same boundary.
Legacy Admin history remains separately readable. ML jobs, files, usage and ledger activity
are continuous. See [the launch runbook and table matrix](V1-TO-V2-PRODUCTION-RUNBOOK.md).


## Reviewed retained provider coverage — 2026-09-30

`ProviderCoverageDispositions` stores a first-class bounded legacy paid-provider term,
not a manual/cash grant or new Payment. The operator approves exact identities, dates
and authenticated cancellation evidence through AdminOperationRunner. Cutover alone
activates it, records its ID in BillingReportingBoundary, and verifies full-row
fingerprints and effective plan identity. The existing normalized subscription retains
its provider provenance and approved period; no second subscription authority is added.
Shared effective-plan reads permit access until the exact exclusive millisecond end;
newer history/supersession prevents resurrection. Recurring lifecycle/allocation reads
exclude it. Fake/manual intents proven to have no remote identity are preserved with
their financial children/order links, separately from unresolved remote obligations.
See [the disposition contract and operator flow](PROVIDER-OBLIGATION-REVIEW.md).

## Local payment-domain cutover — 2026-09-15

The separately authorized local business cutover can retire all online processing
records while retaining orders, ledgers, allocations, credits and normalized history.
It ends only old provider-derived local authority and preserves explicit internal
and external access. A transactional Admin audit stores the cutover timestamp,
retired ID watermarks and dependency manifest. Current Admin revenue uses this
boundary; historical evidence remains readable. No migration, provider call or
credit allocation is part of the operation. See [BILLING-DOMAIN-CUTOVER.md](BILLING-DOMAIN-CUTOVER.md)
for exact guards and the distinction between implementation, dry run and execution.


## Recurring subscription actions — 2026-09-14

Renewal state is now tracked separately from paid access using durable local
cancellation intent, authenticated GET confirmation and a bounded retry scheduler.
Paid plan replacement commits before cancelling the superseded provider subscription.
Late collections after cancellation/expiry/supersession enter financial review without
restoring access. Existing App/API allocation and add-on policy remains authoritative.
See [RECURRING-SUBSCRIPTION-LIFECYCLE.md](RECURRING-SUBSCRIPTION-LIFECYCLE.md).


## Explicit payment history reset — 2026-09-14

The separate `billing:reset-payment-history` operator command uses a frozen retained
Payment identity in the full-state review hash for an all-or-nothing maintenance
transaction. Latest active (open or paid) selection is automatic; no UUID argument
is required. Inventory and locking explicitly scope to the active database.
It preserves the kept Payment/events, financial state and normalized history; only
approved historical `payment_id` links detach, with mappings in a transactional
Admin audit. Allocation claims and unapproved retained dependencies block deletion.
It makes no provider calls and changes no checkout or reconciliation policy. See
[PAYMENT-HISTORY-RESET.md](PAYMENT-HISTORY-RESET.md) for guards and the operator
procedure. No application reset or native-engine acceptance was executed.

## Customer checkout abandonment — 2026-09-14

The V2 payment page has an owned, locked, idempotent local-draft cancellation action
with stricter eligibility than Admin review. The Admin evidence predicate is shared,
while PaymentCheckoutState and final creation guards remain unchanged. Historical
NOT_FOUND/ambiguous paid-reference review still requires Admin resolution. V2 purchase
pages no longer accept coupons and refresh open reviews after closure. Provider fields,
financial history and legacy coupon services remain intact. See
[PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md#customer-abandonment-of-unavailable-checkout--2026-09-14).


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


## Dated external agreements — 2026-09-13

`ServicePlanAgreement` is a scheduling/approval record, not a second subscription or
payment engine. It creates `CustomerServiceSubscription` only when due; monthly
access uses SubscriptionCreditAllocation and the existing App/API wallets and ledger.
AdminOperationRunner authorizes the immutable deal. A provider-independent scheduler
processes only these agreements; shared expiry covers their credit cleanup. No online
provider or collected revenue is implied. See [SERVICE-AGREEMENTS.md](SERVICE-AGREEMENTS.md).


## Admin abandoned-checkout resolution — 2026-09-13

The existing Admin review invalidation now closes eligible ambiguous checkout by
explicit operator decision. Both finance/reconcile capabilities and the durable
P0 operation are required; locked local evidence and retained fulfillment links
can reject closure. History and provider fields remain, unused coupons release,
and the unchanged customer policy then reads expired. Paid provider verification
remains a separate reconciliation path. See [PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md).


## Checkout lifetime and V2 payment status — 2026-09-10

PaymentCheckoutState separates actionable checkout, known unpaid closure and
financial review using actual provider deadlines/evidence. CheckoutCreationGuard
now wraps all Create* actions across V1/V2, while locked terminal transitions retain
history and prevent late observations from reviving old checkout. Creation/status
timestamps preserve their provider instant when stored in the application timezone.
V2 payment status uses owned safe projections and bounded polling through existing
confirmation services. See [PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md).

## V2 purchase pages — 2026-09-10

V2 subscription/storage/add-on routes share `Account\PurchasePage` presentation
and CustomerPurchaseCheckout entry coordination, delegating to existing Create*
payment actions and shared FIB checkout. Catalog mode is fixed per record; the
selector filters records. A pure PlanSwitcher calculation supplies both actual
switch balances and separate App/API previews without changing allowance policy.
Pending purchases are reused; rendering remains provider-free. Storage replacement
and unsupported one-time intervals are blocked where existing domain handling is
incomplete. See [PURCHASE-V2.md](PURCHASE-V2.md) for behavior and verification limits.

## V2 customer account pages — 2026-09-10

`/{locale}/app-v2/profile` and `/{locale}/app-v2/my-billing` use the V2 shell
and existing customer authentication, active/verified middleware and App V2 gate.
V1 route names remain. The V1 and V2 view-based components share the extracted
`App\Livewire\Account\ProfilePage` and `BillingPage` handlers; V2 billing extends
them with customer-owned display projections. No account tables or billing engine
were introduced. Shared phone/email OTP and password-reset pages remain the sole
verification/reset implementation; changed-phone verification returns to V2.

Profile retains shared validation and avatar storage. The independent V2 avatar
action uses the same S3 customer avatar prefix and removes the previous object
only after the replacement is stored and the profile saved. Billing reads separate
App/API buckets, persisted usage, paginated payment and ledger history; cancellation
delegates to `ScheduleServicePlanCancellation`. Reads never settle payments, renew
subscriptions or contact providers. See [ACCOUNT-V2.md](ACCOUNT-V2.md).

## Complimentary grants and legacy payment review — 2026-09-09

Admin service-plan grants continue through the existing durable operation runner
and manual grant service. They create a normalized subscription and genuine App/API
credit ledgers, with explicit non-revenue classification and local expiry, without
Payment or CreditOrder creation. Complimentary access is excluded from the dashboard's
paid-subscriber count. Supersession preserves the previous purchase's financial metadata.
The operator-only legacy reset command is a guarded, local maintenance operation,
not a scheduler or customer flow. Its current dry run is blocked and retains all
purchase evidence. See [the focused runbook](LEGACY-PAYMENT-CLEANUP.md).

## Billing Phase 2 — 2026-09-09

Recurring financial allocation is separate from provider status observation.
`subscription_credit_allocations` retains unique subscription/cycle and
Payment/cycle claims; claims, locked App/API bucket resets and ledger writes are
atomic. Customer-first locking serializes plan switches and renewal, and old
normalized subscriptions cannot regain authority. Verified annual terms support
monthly allowances; paid monthly plans require a new verified collection.
`SubscriptionCyclePolicy` shares the paid-through boundary and `ExpireSubscription`
creates the explicit Free row at expiry. Reads do not invoke that mutation.
Cancellation preserves paid history and access through the verified term. See
[BILLING-AUDIT.md](BILLING-AUDIT.md) for schema, source status and acceptance limits.
The operator applied the additive migration locally. Rollback-only acceptance
passed against the existing MariaDB application schema with historical fingerprints
preserved; native MySQL/RDS concurrency and real-provider acceptance remain separate.

## Admin usability follow-up — 2026-09-08

The current Admin UI keeps P0/P1/P2/P3 contracts. Landing CMS mutations now also
require the existing admin.catalog capability and change reason, with database
CMS changes recorded by the Admin audit observer. Translation file saves audit
the affected key names. This extends the existing Admin contract without changing
the CMS persistence architecture. Dashboard window correctness, read-only job
groups and Admin-to-App/Landing consumer fixes are detailed in ADMIN-AUDIT.md.
Currency edits refresh existing display caches after commit; no billing policy,
customer financial history, service identity or route architecture changed.


## Admin P2 local operational reads — 2026-09-06

AdminOperations supplies allowlisted local read projections to one shared
view-based Operations/customer-detail component. Reads require fresh admin.read;
finance/reconcile gates the deeper payment evidence. Job/payment trace filters
use persisted relationships. No serializer that settles API reservations, billing
repair, remote polling, object probe or result download runs from these pages.
Existing P0/P1 mutation boundaries and runtime domain resolution remain unchanged.
See ADMIN-AUDIT.md for sections, performance limits and verification.

## Admin P1 V2 projection and configuration — 2026-09-06

AdminV2Catalog joins MetKurdV2ToolCatalog, ApiCatalog and persisted records for nine
model/mode views without merging identities. AdminEntitlementScopes atomically
maintains explicit/derived scope provenance in existing plan metadata and a
compatible `api_allowed_tools` union, including sibling and plan-move handling.
Existing scopes default to explicit ownership; no import/read backfill runs.
Grouped channel pricing writes are transactional. Read-only previews reuse
Customer's runtime rule selector/calculator and CustomerApiAccessService's plan
configuration. P0 authorization/audit/dependency boundaries remain intact.
See [Admin P1 implementation](ADMIN-AUDIT.md). P2/P3/P4 remain separate.

## Admin P0 safety boundary — 2026-09-06

Admin retains `auth:admin` and adds fresh active-user capability Gates on final
scoped mutations. `users.admin_capabilities` is provisioned explicitly; active
accounts without a list have read/support access. `SecureAdminComponent` and
persistent active-admin middleware also cover Livewire requests.

`AdminOperationRunner` owns durable financial intent identity and locks the
operation/customer around existing domain services. Manual plan grants and App/API
credit synchronization now commit or roll back together; full-new-allowance and
separate wallet semantics remain unchanged. Add-on balances are retained.
Completed identical intents return recorded outcomes; changed actor/customer/
action/target/reason under an existing identity is rejected. Failed execution
retains a pending intent and a sanitized failed-attempt audit entry.

Explicit payment invalidation verifies current locked state. Provider reference
correction verifies fresh paid evidence and local relationships before writes.
Manual add-on/storage grants default to no revenue; verified-paid corrections
require matched provider-backed Payment evidence and existing fulfillment.
New add-on CreditOrders retain the product FK. No historical repair runs.

Final catalog deletion checks history, pending purchases and relevant API/file
relationships; referenced entries must be retained/deactivated. Admin audit
records are additive and redact sensitive values. Provider payloads exposed to
components use an operational allowlist, while editable settings mask secrets
and preserve their server copy. Database atomicity does not cover external
provider cancellation/notification effects in existing fulfillment.

See [Admin implementation status](ADMIN-AUDIT.md#admin-p0-implementation-status--2026-09-06)
and [rollout prerequisites](INFRASTRUCTURE.md#admin-p0-rollout--2026-09-06).
P1–P4 and Landing CMS remain outside this change.

## API V2 phase — 2026-09-06 (current)

The public /api/v2 namespace and /{locale}/app-v2/api developer portal now consume
the native V2 services. ApiSubmission validates and claims an ApiJob identity;
SubmissionContext atomically attaches MlJob and reserves the API wallet inside
the core transaction. Existing adapters, synchronizers, reconciliation and
CustomerOutputStorage handle processing. API status reads only persisted state.
See [API-V2.md](API-V2.md) for the exact public/security contract and migration.
FEATURE_API_V2 and FEATURE_APP_V2 remain disabled by default; source implementation
is not evidence of deployment. The previous core-review scope below is historical.

The API-disabled portal notice reflects the machine gate, not a localhost
restriction. [API activation](API-V2.md#activation-runbook) documents the independent
API/web flags and prerequisites. The latest [local follow-up](PRODUCTION-AUDIT.md)
records user-reported working services, local infrastructure repairs and the
subsequent OCR status/Vector-STEM uploader fixes, with verification limits.


## Previous core-review scope — 2026-09-06

The seven V2 services and Storage define the current release boundary. Shared
backend classes are acceptable; obsolete V1 behavior is not a compatibility
constraint on V2. Do not delete legacy code before tracing its V2 callers.
Future architecture: Web V2 and Future API -> V2 Core -> GPU -> owned persistence.
This is direction, not a claim that the future API has been implemented.


Repository baseline: 2026-09-05, inspected from commit `e052648`. Current source
is authoritative; this baseline does not attest to production configuration.

## Application boundaries

MetKurd provides customer AI workspaces, credits/subscriptions, storage and job
history in English, Arabic and Kurdish Sorani. V2 is the current development
target, but `config/metkurd_v2.php` defaults its feature gate to false. The route
tree in `routes/web.php` keeps V1 `/app` and V2 `/app-v2` under a locale prefix.
Both use the `app` customer guard, active/verified middleware and shared domain
models. V2 adds `app.v2.enabled`; individual tools add entitlement middleware.
Admin pages use a separate area. Landing pages have their own routes/catalogs.

```text
Blade/Livewire customer workspace
  -> customer entitlement + pricing + concurrency checks
  -> V2 submission service
  -> MlJob / CreditService / CustomerOutputStorage
  -> provider adapter or service-specific payload builder -> GPU HTTP API
  -> coordinated browser/queue polling -> per-service sync -> persisted output
  -> customer-owned history, preview, download, storage library
```

PHP ^8.2 / Laravel 12 / Livewire 4 are declared in `composer.json`. Livewire
components are primarily anonymous classes inside `⚡*.blade.php` under
`resources/views`, configured by `config/livewire.php`; do not search only for
class-based components. Browser behavior combines Alpine, bundled Bootstrap
assets, FilePond uploads, WaveSurfer audio and SweetAlert notifications. Vite 7
and Tailwind 4 are declared build dependencies, not the entire UI implementation.

## Domain and ownership

- `Customer` is the account boundary; `CustomerProfile`, service/storage
  subscriptions and entitlements determine account capabilities.
- `Tool` / `ToolAction`, pricing rules and plan entitlements remain the billing
  authority. `MetKurdV2ToolCatalog` and `config/metkurd_v2.php` map UI slugs to
  those identities. Do not derive a database action from a visible product name.
- `MlJob` has a string UUID key, customer/tool/action foreign keys, status,
  JSON input/output/error, provider job identity, credit/storage amounts and
  optional lifecycle/lock fields. Its active states are queued/running/saving.
- `CreditService` locks wallets and records ledger mutations. App and API wallets
  are distinct; subscription credits are spent before add-on credits. References
  support idempotency only where callers supply them. Split-bucket charging may
  legitimately produce multiple ledger rows for one logical charge.
- `CustomerFile` records an object's customer, disk/path, purpose, size, MIME,
  status, retention, source and quota behavior. `CustomerUsage` stores aggregate
  usage. `MlJob.output` and files together describe results; caches are read views.
- `Voice`, `CustomerVoice` and plan voice access support speech catalogs;
  reusable V2 clone uploads are `CustomerFile` reference records.

## Shared services and legacy inventory

Native V2 submissions are in `app/Services/MetKurd/Jobs/`. Existing synchronizers
in `XTTS`, `ASR`, `OCR`, `STEM` and `Translation` own result handling. V2 does not
create a parallel web wallet/history/storage subsystem. See [SERVICES.md](SERVICES.md).

`routes/api.php` also exposes `/api/mobile` with Sanctum and `/api/v1` with the
customer API middleware. `app/Services/Mobile/` and `CustomerApi/` have separate
submission/access layers. The public API has `ApiJob`, reservations and result
links tied to shared processing/storage; it must not be confused with V2 web
history or charged from the app wallet by accident.

V1 routes and implementations are retained pending dependency-led retirement.
Existing V2 data and correct V2 contracts must remain usable; obsolete V1
contracts are not a blanket compatibility requirement. Apollo 2 and Vector 2 have distinct
tool identities; Leo history is isolated from historical QASR. Caption preserves
its established worker options. Translation remains a V1 workspace, absent from
the V2 service route allowlist. Placeholder products are not implemented tools.

## Evidence and limitations

### Admin P3 presentation boundary

Admin continues to use namespaced Livewire view-based components and Bootstrap.
`resources/js/admin.js` owns a once-installed, navigation-safe SweetAlert/notification
bridge, with scoped styles in `resources/css/admin.css`. High-impact controls declare
an existing method and JSON arguments; confirmation calls that method without changing
P0 financial intent identity. Browser hints use request-local `AdminUiAccess`; server
capability, transaction and dependency checks remain authoritative. Customer-context
bookmarks reuse existing routes. Status badges keep provider/local/persistence and
financial evidence separate. P2 read bounds and content redaction are unchanged.
Dashboard analytics cache identity includes locale as well as environment/database
identity because cached display labels are localized. See ADMIN-AUDIT.md for tests
and the outstanding interactive browser and native MySQL acceptance boundary.

Primary references: `routes/web.php`, `routes/api.php`, `config/metkurd_v2.php`,
`config/livewire.php`, `app/Models/{Customer,MlJob,CustomerFile,CreditWallet}.php`,
`app/Services/Billing/CreditService.php`, and service files linked in this folder.

The earlier [shared submission plan](../architecture/metkurd-v2-shared-submission-plan.md)
describes a historical Phase A. Its fallback/migration-order and global refund
statements are not a current implementation inventory. Read it as rationale,
then verify against this baseline and source. Live GPU revisions, running queue
workers, deployed schema, feature enablement, backups and production topology
cannot be established from these source files.

## Durable completion and submission (2026-09-05)

The Laravel scheduler queues ReconcileMlJob for due active GPU jobs. Shared
synchronizers coordinate browser/API/server calls using database poll leases and
intervals; finalizers retain their existing locks. Closing the browser no longer
removes the application completion path. DurableUploadSubmission commits job and
app debit together for OCR/STEM/Leo/Caption and the existing Translation page.
Apollo/Vector retain their established local transactions and share conservative
failure/refund handling. Ambiguous dispatches need operator evidence, never an
automatic paid replay. See GPU-JOB-LIFECYCLE.md for deployment and recovery limits.

## V2 independence — 2026-09-06

The obsolete generic V2-to-V1 workspace fallback was removed. Shared backend
classes and persisted ToolAction identities remain intentional; legacy-named
result/reference routes and deferred shell/account links are temporary migration
dependencies. See PRODUCTION-AUDIT.md for their A/B/C classification and the
Livewire input validation/probing to share with a future API.
