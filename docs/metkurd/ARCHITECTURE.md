# MetKurd architecture

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
