# Architectural and behavioral decisions

## 2026-09-06 — Close the Admin P0 normalized-plan deletion defect

- Removed the invalid physical customer-plan column query. ServicePlan retains
  its existing subscription/history and catalog/financial dependency checks.
  PlanVoiceAccess now checks the parent plan's current and previous subscription
  relationships without status/date filters; access alone does not imply use.
- Added regression checks that reject the invalid SQL even under SQLite, and
  cover retained history, dependencies added after preview, disposable fixtures
  and existing catalog/financial protections. Native MySQL execution remains
  unverified. No UI, migrations, pricing/billing policy or historical data changed;
  Admin P1 remains deferred. See [Admin audit](ADMIN-AUDIT.md).

## 2026-09-06 — Admin routing integrity and V1 import baseline

- Preserved the intentional P0/V2 route structure. Fixed the pre-existing shared
  authentication redirect so named Admin pages send guests to Admin sign-in;
  customer and JSON/API branches retain their behavior. Isolated route tests
  cover read access, middleware separation and Livewire status revocation.
- Recorded the final V1 snapshot's eight pending migrations and no-general-seed
  import procedure. Existing seeders can overwrite production catalog settings.
  Historical billing findings and a P0 deletion guard querying a nonexistent
  customer plan column were recorded as unresolved at that review; the later
  guard repair is recorded above. No financial data or catalog logic was changed
  during the routing review. P1 localization remains deferred. See
  [routing review](ADMIN-ROUTING-REVIEW.md) and [import plan](PRODUCTION-DB-IMPORT.md).

## 2026-09-06 — Admin P0 financial, authorization and data integrity

- Added explicit active-admin Laravel capability Gates and final Livewire
  enforcement for scoped customer/catalog/pricing/financial/reconciliation
  changes. Existing active accounts default to read/support until explicit
  trusted-console provisioning. No enterprise roles or Admin redesign.
- Added durable Admin operation identity and sanitized audit history. Replays
  reuse outcomes; payload/actor changes fail. Manual plan plus App/API credit
  sync share a transaction and retain full-new-allowance policy and add-on funds.
- Invalidating a review now locks and validates source state, rejecting paid
  evidence and fulfilled/applied history. Provider correction validates fresh
  paid evidence and local linkage before committing candidate references.
- Future manual add-on/storage grants are explicitly no-revenue unless a
  verified Payment is fulfilled through the existing domain. Revenue predicates
  agree; new add-on orders retain `credit_product_id`. No historical backfill.
- Final catalog deletion rechecks historical and pending dependencies. Currency
  changes verify locked pair/context and protect the base currency. Operational
  payload display is allowlisted; settings/audit redact secrets and signed URLs.
- Added isolated regression coverage, including injected plan/sync failure and
  two-process same-intent replay. Deployment migration, actual provisioning,
  production-engine concurrency and browser acceptance remain separate checks.
  P1–P4 and Landing CMS remain open. See [ADMIN-AUDIT.md](ADMIN-AUDIT.md).

## 2026-09-06 — Workspace job identity and upload readiness

- OCR submission/synchronization invalidates request-cached job, status and text
  together. A new document clears terminal editor state; active work stays in
  focus. The document-labelled status card owns the active poll, preventing a
  previous completed result from presenting as the new job.
- Vector/STEM upload controllers wait for Livewire registration and reconnect
  on navigation; STEM also retries initialization when FilePond becomes available.
  Upload success uses the temporary server identifier and abort uses cancellation.
- These are web workspace state changes. Persisted job identities, paid dispatch,
  storage ownership and app/API wallet boundaries remain unchanged.

## 2026-09-06 — API V2 uses the native V2 core (later phase)

- Supersedes the earlier same-day API deferral. Added the versioned machine API
  and V2 developer portal for Apollo, Vector, Leo, Caption, OCR and STEM 2/4.
- Reused hashed keys, plan rates, API reservations, ApiJob/ApiResultFile and
  retention; excluded legacy service dispatch and provider polling from V2 GETs.
- Added trusted SubmissionContext to select API entitlement/pricing/reservation
  without altering app-wallet behavior. InputBoundary extracts shared web/API
  text/voice/reference/audio/document preparation; client billing metadata is ignored.
- Customer-scoped hashed idempotency keys enforce one logical paid job. Known
  failures release API reservations; ambiguous outcomes never blindly resubmit.
  The existing reconciler expires unlinked claims after 15 minutes without charge.
- Central native storage registration inherits API retention. Downloads authorize
  key scope, customer, API job, file and expiry and expose no object paths.
- Added migration and disabled-by-default FEATURE_API_V2 rollout gate. Web V2
  navigation uses its own portal; legacy /app/api redirects only with Web V2
  enabled. /api/v1 programmatic contracts remain in place. Billing modernization
  and V1 cleanup remain out of scope. See [API-V2.md](API-V2.md).


Only durable decisions belong here, not commit-by-commit history or CSS/copy edits.
Each entry records date, area, previous/new behavior, reason and compatibility.
Migration filenames establish repository schema dates, **not deployment dates**.

## 2026-09-06 — V2 core is the product and release boundary

- V2 is the primary/future application. Current core scope is Apollo, Vector,
  Leo, Caption, OCR, STEM 2/4 and V2 Storage. Apollo 1.0v, Translation, Neo and
  other V1-only implementations are not production targets for this phase.
- Retain legacy source only after dependency assessment; preserve useful shared
  implementations and customer data, not obsolete V1 behavior that blocks V2.
  No blanket V1 deletion is authorized.
- Future API clients will consume the V2 core service layer. Old API refactoring
  and billing/account/subscription modernization are deferred. Existing durable
  job/debit/refund safeguards remain required; only prevent serious shared-core
  regressions in deferred clients.
- V2 core and its shared infrastructure must pass relevant tests. Unrelated
  legacy/API failures are informational, not V2 release blockers.
- Earlier dated entries describe completed work and the old scope; this decision
  supersedes their product-direction and blanket compatibility assumptions.

## 2026-09-05 — Durable completion and financial recovery

- Added scheduler/queue reconciliation using existing synchronizers and database
  leases shared by browser/API/queue callers; terminal jobs stop GPU queries.
- OCR/STEM/Leo/Caption/Translation now commit local jobs and app debits together
  using existing actions and deterministic charge/refund references. Known failed
  corrections remain retryable; ambiguous acceptance is neither refunded nor replayed.
- Stale cleanup excludes paid/attempted/accepted jobs, including a locked recheck.
- Compatibility: additive poll migration must precede rollout, with scheduler and
  properly configured workers. No API-wallet redesign or live migration occurred.

## 2026-09-05 — Confirmed persistence and deletion reconciliation

- Object writes must succeed before metadata/quota and job completion commit.
  Customer locking and size deltas make application writes and worker registration
  retry-idempotent. Already-deleted objects cannot be re-registered.
- Quota subtraction belongs to one active-file deletion transition. Whole-job
  delete_failed is retryable; file deletion updates per-service history and caches
  in the same database transaction without deleting financial audit rows.
- OCR returned paths are constrained to the owned job namespace. Remote OCR
  cancellation needs acknowledgment and retains inputs when its outcome is uncertain.
- Existing destructive-operation and ownership protections remain required.

## 2026-09-05 — Server-verified OCR pricing and localized validation

- OCR billing no longer trusts browser PDF counts. A bounded server probe and
  deduplicated page selection determine billed pages while retaining ocr.standard.
  pdfinfo availability is a deployment prerequisite; failure rejects before debit.
- Framework validation files complement area JSON catalogs for service upload,
  type, required and size errors in English, Arabic and Sorani. V2 confirmations
  and Livewire error responses use the shared safe SweetAlert boundary.

## 2026-09-05 — Persistent repository knowledge baseline

- Area: engineering context.
- Previous: no root AGENTS.md; a Phase A submission plan no longer represented
  all native V2 workspaces or terminal refund behavior.
- New: concise root guidance and current source-backed architecture/service/
  infrastructure/lifecycle/storage/localization documentation under docs/metkurd.
- Reason: future sessions must not repeatedly rediscover contracts or treat a
  historical plan as current production evidence.
- Compatibility: documentation establishes no rollout, migration or provider
  change. V1 routes/data remain supported; uncertain deployment facts stay marked.

## 2026-09-05 — V2 customer notification and error boundary

- Area: localization and customer errors.
- Previous: AreaJsonTranslations treated V2 routes as landing; the V2 shell had
  no alert-event bridge/SweetAlert assets, and several error displays rendered
  raw backend messages. V1's toast listener could survive navigation into V2.
- New: V2 shares the app catalog, loads its guarded notification bridge, and
  maps displayed errors through CustomerFacingError in the active locale.
- Reason: deliver customer notifications and avoid English/provider diagnostics
  leaking into localized error displays.
- Compatibility: V1 retains Toastr; internal provider keys/logs and financial/
  processing behavior stay intact. Unknown dynamic errors use generic localized
  wording until specific safe message mappings are added. Routine translation
  additions are summarized in the audit rather than separate changelog entries.

## 2026-08-12 — Additive submission identities (schema date)

- Area: jobs and billing.
- Previous: provider IDs/indexed ledgers and execution locks did not provide a
  durable customer request identity; older jobs had no shared lifecycle fields.
- New: nullable submission_key, endpoint/model keys, unique charge/refund
  references, attempt/refund timestamps and failure stage on MlJob. Native
  Apollo/Vector now use these to avoid sequential duplicate paid submissions.
- Reason: commit local financial intent before an independent provider request,
  with repeatable correction on known local failure paths.
- Compatibility: nullable additions preserve historical rows. Not all services
  use all fields; no verified provider-side idempotency/cancellation contract.
- Evidence: `2026_08_12_000000_add_submission_lifecycle_to_ml_jobs_table.php`,
  Omni/Clone submission services, CreditService and MlJobRefundService.

## 2026-08-15 / 2026-08-16 — Versioned V2 tool access (schema dates)

- Area: service identities and compatibility.
- Previous: existing xomni/clone_xomni and qasr/caption service identities.
- New: migrations register xomni-v2, vector-v2 and leo; Caption access is
  registered for the V2 flow. Current source has native upload/result workspaces
  for Vector, Leo, Caption, OCR and STEM in addition to Apollo.
- Reason: keep version-specific entitlement/pricing/history separate while
  reusing shared job/storage models and established compatible payloads.
- Compatibility: Leo does not merge historical QASR history; Caption retains
  type=caption worker options. These migration dates do not establish when each
  workspace or GPU worker was deployed.
- Evidence: register_xomni_v2_tool, register_vector_v2_tool, register_leo_v2_tool,
  register_caption_v2_access migrations; config/metkurd_v2.php and native services.

## 2026-06-15 — Separate app and API wallets (schema date)

- Area: billing/channel ownership.
- Previous: legacy combined wallet data and service credit allowances.
- New: app/API wallet types and API credit allowances; current CreditService
  serializes per-wallet mutations and spends subscription/add-on buckets.
- Reason: separate API usage accounting from application usage (as evidenced by
  migration, services and wallet architecture tests; wider business rationale
  is not recorded here).
- Compatibility: preserve migrated balances and explicit API wallet selection;
  never collapse API reservations into native web charges during refactoring.
- Evidence: `2026_06_15_000000_split_credit_wallets_and_add_api_credit_allowances.php`,
  `tests/Feature/Billing/SeparateCreditWalletArchitectureTest.php`.

## 2026-09-06 — Final V2 core corrections

Made explicit V2 endpoint configuration authoritative and retryable on absence;
removed the obsolete V1 workspace fallback; locked server-derived speech catalogs,
limits and STEM mode. Preserved STEM players across unrelated updates, corrected
Leo/Caption completion refresh and polling, removed duplicate OCR polling, exposed
failed-deletion retries, and pointed the resource panel to V2 Storage. Added endpoint
recovery and UI/controller regressions. API and billing modernization remain deferred.
