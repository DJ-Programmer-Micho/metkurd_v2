# Architectural and behavioral decisions

## 2026-09-30 — Legacy authenticated cancellation evidence at cutover

FibStatusEvidence now validates the latest matched persisted subscription GET event,
including paid-to-canceled `provider_status_ignored`, through the existing evidence
validator. ProviderSubscriptionCancellation exposes one read-only confirmation
authority to the cutover inventory, which retains raw provider coverage bounds and
continues blocking future/ambiguous access. A narrow AdminOperationRunner-backed
`payments:review-fib-cancellation` provides explicit audited GET-only confirmation
without date correction, cancellation POST, credit allocation or cutover. No
automatic fake-intent exemption, paid-to-manual conversion, migration, production
access or local financial correction. See [diagnosis and operator re-review](PROVIDER-OBLIGATION-REVIEW.md).

## 2026-09-28 — Final Admin consolidation

Retained the existing route and authority model while consolidating the eight remaining
page-specific modal bridges into the shared Admin lifecycle. Audit status is projected
without hydrating an operation per row; voice availability is batched per page through
the unchanged catalog authority. Navigation, titles, terminal states and legacy labels
are aligned. See [final inventory and acceptance](ADMIN-FINAL-CONSOLIDATION.md).

## 2026-09-28 — Admin Developer metadata and origin trace, Phase 7

Added a read-only projection for Developer/ML operations to existing localized
Operations routes. Explicit persisted MCP identity, owned typed traces, grouped
result evidence and separate processing/reservation presentation replace generic
metadata rows. No job, billing, OAuth, API or feature-gate policy changes. Native
MySQL and full populated external-client browser acceptance remain separate.
See [Phase 7](ADMIN-REDESIGN-PHASE-7.md).

## 2026-09-28 — Admin acceptance projection boundary, Phase 5

Replaced the Customer Detail summary's ineffective `first(columns)` restriction
with an explicit ten-field select and no eager loads. This keeps the summary narrow
even when Operations has a broader payment selection. Accessibility fixes stay in
the shared Admin lifecycle; policy, data and schema are unchanged. Native MySQL
and browser acceptance are still open. See [Phase 5](ADMIN-REDESIGN-PHASE-5.md).

## 2026-09-27 — Operational billing evidence, Phase 4

Added read-only billing presentation and review filters to existing Operations.
Subscription classification consumes existing authority, and financial era retains
the committed reporting boundary. Selected records expose bounded, permission-gated
events/allocations. Payment traces now include matching operation audits; corrections
reuse existing customer-scoped modals and immutable audited actions. No policy,
financial data, provider processing or schema changes. See [Phase 4](ADMIN-REDESIGN-PHASE-4.md).

## 2026-09-27 — Admin service controls, Phase 3

Added read-only service/plan/voice projections and an App/API entitlement matrix.
Runtime resolvers remain authoritative; public visibility comes from the existing
PublicProductCatalog, independent of editorial rows. Matrix edits prefill the
existing channel-specific editor without changing inherited decisions or customer
overrides. Service and plan modals now share the Admin lifecycle; pricing and
business rules are unchanged. See [Phase 3](ADMIN-REDESIGN-PHASE-3.md).

## 2026-09-27 — Admin customer workspace, Phase 2

Customer Detail now owns the customer operational summary; Register retains the
directory and focused actions. A bounded local projection composes existing billing,
API/MCP, concurrency and sanitized Operations reads without new authority or external
calls. Existing action modals retain capabilities, reasons and financial intent IDs;
agreement review makes customer-only overrides explicit. No routes, business policy,
migrations or rollout flags changed. See [Phase 2](ADMIN-REDESIGN-PHASE-2.md).

## 2026-09-27 — OCR V2 page-selection submission correction

Preserve `all` and validated compact ranges between InputBoundary and OCR's
independent submission probe, avoiding a generated page list that exceeds the
255-character range-input limit. Custom ranges are required only in custom mode;
the workspace clears stale errors on mode changes and displays validation beside
Scan Document. Expose the existing TXT selection, retaining TXT + DOCX defaults,
owned exports, actual-page billing and durable duplicate protection. Worker/API
contracts, prices and page limits are unchanged. See [OCR service](SERVICES.md).
The new isolated workspace suite covers 174 verified pages, EN/AR/KU errors,
submission/queue events, billing/replay and all five export downloads using mocked
page probing and provider responses; it is not live worker acceptance.
Verification: 20 new workspace tests, 61 existing OCR/durable-upload/queue tests,
8 OCR API regressions and 18 navigation/queue/API-example frontend tests passed.
Focused PHP syntax and Pint checks passed. Interactive browser acceptance remains
separate: the available customer session redirected to sign-in.

## 2026-09-27 — Admin control-center shell, Phase 1

Reorganized existing Admin routes into customer/service/billing/developer/storage
workflows and a collapsed technical group. The shared Admin lifecycle now owns
sidebar behavior instead of the template menu-cloning script. Dashboard presentation
retains financial authority and adds existing local review-queue counts. No new routes,
business actions, capabilities, feature flags or migrations. See
[Phase 1 inventory and verification](ADMIN-REDESIGN-PHASE-1.md).

## Admin controls and agreement-specific allowances — 2026-09-27

Admin browsing no longer needs a global reason input; audited confirmations collect
it for each action. Manual financial actions use distinct modals. Existing
entitlements now expose and enforce their speech character limits with channel-aware
resolution (the previous missing helper always yielded 400). External agreement
snapshots support custom App/API allowances and an optional concurrency override
through PlanConcurrencyService. New agreement defaults are five slots; existing
null overrides keep plan limits. Adjustment changes future unallocated allowances,
not prior credits/history. One additive migration remains unapplied. No production,
plan-price, permission or financial data changes. See ADMIN-CONTROL-CLEANUP.md.


## Landing Admin status matches public availability — 2026-09-27

Admin now distinguishes effective Public / Active, Disabled and Legacy / Not Public
using the existing public catalog; raw CMS publication flags cannot label retired
products public. Current-family definitions also bound default import and legacy
activation guards. A narrow idempotent, audited data migration disables the retained
Translation landing row without changing editorial/history fields. Applied only to
the verified local DB (row 6, audit 12); no production deployment or legacy deletion.
See PUBLIC-WEBSITE.md for the data preservation and test record.

## Admin sign-in shares customer Turnstile protection — 2026-09-27

Admin login now requires the existing shared Turnstile widget/rule/verifier before
authentication, with challenge reset and localized validation errors. Admin guard,
throttling, CSRF, session regeneration, active-account and capability checks remain
unchanged. Existing locale middleware now also covers the Admin sign-in route.
Shared verifier logs use exception type instead of raw provider exception
messages. No new CAPTCHA/configuration, local bypass, keys or deployment changes.
See [ADMIN-AUDIT.md](ADMIN-AUDIT.md) for behavior and verification scope.

## Public V2 content and discovery authority — 2026-09-26

Public product availability now projects current V2 definitions onto active
Tool/ToolAction records, with a cached request-reused catalog and explicit family
publication state. Curated EN/AR/KU product/SEO copy replaces stale legacy marketing;
existing landing design, artwork and players remain. Pricing amounts are unchanged;
public feature bullets use current channel entitlements and separately gated API/MCP
access. Music Separation replaces Translation in completed-job metrics; OCR units
are correctly labelled as documents. Demo readers support multiple ordered examples
bound to active current products without relabeling legacy audio. Dynamic sitemap
and llms routes replace static files; private surfaces gain indexing controls without
blocking rendering assets. No flags, economic data, workers or deployment changed.
See [PUBLIC-WEBSITE.md](PUBLIC-WEBSITE.md) for source ownership, Admin follow-up,
cache/hosting behavior and verification limits.

## V2-first customer entry with independent V1 gate — 2026-09-26

Added `FEATURE_APP_V1` (default true) and one customer destination resolver.
Validated local intended routes take priority; default entry prefers V2, then
enabled V1, then localized landing. Disabled V1 workspaces redirect through a
small explicit map while stale Livewire actions are blocked. Shared auth, owned
media and payment controls remain reachable for V2. Generic navigation and payment
success destinations use the resolver; financial behavior, Admin, API and MCP
are unchanged. No application flags or deployment state were changed.

## MCP effective-plan eligibility correction — 2026-09-26

MCP now consumes the authoritative current active non-Free plan without adding a
payment-provenance condition. Configured manual/complimentary grants and custom
plans qualify; no plan names are hardcoded. Free (including retained API credits),
expired/inactive plans and suspended accounts remain denied. Runtime OAuth consent,
family/action authorization, pricing and API-wallet checks remain independent.
BillingSubscriptionAuthority, payment/history/agreement semantics and REST behavior
are unchanged. EN/AR/KU eligibility wording and isolated regression coverage follow
this clarified business policy. Customer 1 passes the corrected local eligibility
check without financial mutations. See MCP.md for live acceptance limitations.

## Focused MCP hardening — 2026-09-26

MCP job tools/resources now share a pure persisted-state projection. Durable API
reconciliation owns artifact links and settlement; REST retains existing behavior.
CIMD keeps exact URL identities in Passport, with bounded pinned public-only
metadata discovery and consent invalidation on relevant changes. Public UUID
preregistration remains supported; DCR is deferred. Dedicated web/native redirect
policies enable IP loopback ephemeral ports and fixed localhost callbacks without
relaxing web HTTPS matching. An additive identity-widening migration is prepared,
not run against the application. `mcp:readiness` reports configuration read-only:
all three local paid tiers still lack recognized V2 scopes. No scopes, prices,
credits, keys or gates changed. SDK stays pinned to 0.8.1. See MCP.md for verification
and required native DB, real-client and production acceptance.

## Paid V2 MCP external-client interface — 2026-09-26

Added official PHP MCP SDK 0.8.1 and Passport 13.7.6 with preregistered public
OAuth/PKCE, resource-bound short access/rotating refresh tokens, runtime commercial
eligibility and immediate connection revocation. Fourteen strict tools share
REST's native API submission, financial reservation and persisted result services.
Required per-intent UUIDs preserve paid retries across transport reconnects.
Private owned browser uploads replace assumptions about AI chat attachments.
Added EN/AR/KU portal, protected resources/downloads, isolated protocol/security
tests and [MCP.md](MCP.md). API job key linkage becomes nullable for MCP without
manufacturing API credentials. No prices, credits, worker contracts or rollout
gates changed; no application migrations or external OAuth registration ran.
Local paid plans have API allowances but no recognized V2 scopes and need operator
review. Claude Code's default localhost callback conflicts with the required
production policy; Codex requires a reachable reviewed HTTPS callback. External
host/native DB/browser acceptance and inherited dependency advisories remain open.

Verification: 215 distinct focused PHP tests (MCP + REST API V2 + App/core,
localization, maintenance and privacy), 67 frontend tests, Vite build, changed-file
lint/Pint and Composer validation passed. Legacy SDK session values are encrypted
using Laravel Crypt while preserving the SDK response-delivery queue; arbitrary
client metadata is excluded. Native Leo/Caption diagnostic logs no longer include
arbitrary exception messages. No application database or rollout change ran.

## Current V2 release inventory and catalog preflight — 2026-09-26

ProductionPreflight now derives required active Tool/ToolAction identities from
MetKurdV2ToolCatalog, excluding coming-soon entries. Its former nine-action list
could report success without Zeta, Theta or Harakat. No catalog rows, prices,
entitlements, rollout gates or production state are changed by the command.
The isolated regression checks all twelve actions and each new service's missing
identity, inactive action and inactive tool without repairing them.

The [current-state review](CURRENT-STATE-PRODUCTION-REVIEW.md) and refreshed
[production runbook](V1-TO-V2-PRODUCTION-RUNBOOK.md) distinguish source/local/user
reports from production acceptance, record 78 repository migrations and five
endpoint configurations, and retain the historical cutover evidence. Native RDS,
actual catalog economics, live workers/storage/FIB and interactive acceptance
remain operator-only release checks; the committed local cutover must not repeat.

## Branded pre-rendered maintenance with JSON negotiation — 2026-09-26

Use a self-contained `errors.503` document, with embedded local logo/CSS and static
EN/AR/KU maintenance copy. Detect locale and Landing/App/Admin style in the browser
so artisan pre-rendering cannot freeze command-time request context. Wrap Laravel's
unchanged early maintenance output for API JSON 503; retain native secret/cookie,
exclusion, redirect and Retry-After behavior. Plain down uses the same presentation
before auth-dependent exception handlers. Recommend the file-driver pre-render
command in the production runbook; do not change cutover logic or deploy here.

## 2026-09-26 — Global V2 Process Queue

- Replace V2's lock-filtered legacy slots with a bounded customer/App MlJob read
  model and one navigation-owned adaptive updater; leave V1's component intact.
- Use local done for Ready, with per-session terminal acknowledgement, visible
  failure notices, a generic submission event and owned existing result links.
- Keep plan enforcement, provider reconciliation and storage/financial mutations
  outside the shell. No schema, deployment or API change. See PROCESS-QUEUE.md.

## 2026-09-26 — API voice discovery and developer previews

- Preserve GET /api/v2/voices and Voice.code identifiers; present customer-specific
  allowed IDs in the portal with copy/sample controls and translated GET examples.
- Keep Apollo/Zeta on one plan-scoped catalog; exclude malformed references already
  rejected by Zeta instead of advertising unusable IDs.
- Reuse xomni asset routes with catalog membership and App-or-API entitlement
  checks, allowing API-only previews without granting generation access or billing.
- No new API, cache, voice IDs, plan grants, pricing, worker or rollout changes.

## 2026-09-26 — Native API access for Zeta, Theta and Harakat

- Extend API V2 with three public routes, strict public fields and native
  SubmissionContext reservations. Preserve existing endpoints, App billing,
  one project/job/submission and ambiguous-outcome handling.
- Share speech/voice-clone scopes for Zeta/Theta; add independent Harakat scope.
  Admin uses the same catalog for twelve variants and seven scopes. No plan/key
  mutation or scope backfill accompanies deployment or reads.
- Add reference upload through the existing owned-reference uploader. Return IDs
  rather than accepting arbitrary URLs; keep private quota-counted references and
  identical signed URLs per object within each Theta dispatch.
- Allowlist final audio metadata or Harakat text/counts/TXT in normal results.
  Add safe discovery and EN/AR/KU portal documentation with placeholder-only
  cURL/PHP/Python/Node examples. See API-V2 for operator steps.
- No feature activation, application database migration, deployment or GPU change.

## 2026-09-21 — Harakat 1.0 text-only Arabic diacritization

- Add independent `harakat` / `harakat.diacritize` registration and OCR-family
  workspace `/app-v2/ocr/harakat-1`, preserving Scanner's identity and contract.
- Bill trimmed server-counted characters through durable job/debit handling;
  dispatch one text-only job using `RUNPOD_ENDPOINT_ID_TASHKEEL_V1`. Initial prices
  and plan entitlements snapshot Apollo 2 through an additive operator migration,
  with independent future pricing and no production seeder or public API expansion.
- Persist text and private TXT through existing storage/quota/history boundaries
  before completion. Retain replay protection, ambiguous dispatch review, refunds,
  coordinated polling and App/API wallet separation.
- Add EN/AR/KU text/result/history, RTL, copy/download, Admin projection and focused
  regressions. No application migration, deployment, worker change or live provider
  submission was performed. See SERVICES for the operator command and acceptance.
- Verification passed for Harakat/OCR/Admin lifecycle coverage, frontend/build and
  scoped lint/localization. Updated the shared localization test to recognize the
  existing PHP catalogs as well as JSON and exclude incomplete dynamic prefixes;
  application translations outside Harakat were not rewritten. See SERVICES for
  exact overlapping test counts and remaining live/browser acceptance.

## 2026-09-21 — V2 STEM playback stability and same-origin delivery

- Preserved the keyed result UI and navigation owner; removed corrective playback
  seeks and CORS-triggered player recreation. One AudioContext schedules reusable
  decoded tracks together; gain-only Mute/Solo preserves mute preferences. Normal
  Play All excludes the original comparison recording to avoid doubling the mix.
- Routed only V2 STEM playback through a bounded same-origin stream with proper
  ranges, upstream S3 range reads and the existing private immutable cache policy.
  No generation, billing, storage ownership, MlJob or provider changes.
- Source did not support the reported Mute/Solo-to-Livewire action chain: controls
  were already client-side and result DOM was ignored. The live stutter/console
  stack remains unverified because both browser-control runtimes failed to start.
  `reportAllChanges` was not found locally; its script URL is needed for attribution.
- See V2-NAVIGATION and STORAGE-AND-CACHE for transport, browser memory and initial
  proxy-transfer tradeoffs, automated coverage and remaining acceptance checks.
- Verification: 27 isolated PHP tests / 232 assertions and all 52 frontend tests
  passed, along with build, scoped lint/Pint and EN/AR/KU rendering. The user then
  reported smooth playback with no current error. This is user-reported browser
  acceptance; the earlier `startTime` source remains unidentified.

## 2026-09-21 — Zeta / Theta UI identity and reference selection

- Replaced positional Livewire field bindings with UUID-keyed draft fields and a
  separate validated order list. Native drag sends IDs and drops before its target;
  the existing ordered submission payload and final zero pause remain unchanged.
- Reused one Apollo voice panel per Zeta workspace and Vector's reference history
  with existing audio previews for Theta, targeting a selected segment.
  The shared Omni preview/avatar routes also recognize Zeta access, preserving
  existing Apollo access and rejecting customers with neither permission.
- Completed Theta uploads invoke the existing save path and invalidate reference
  reads/options in the same response. Multiple segments retain one stored file.
  Removed the customer transcript editor while retaining empty `ref_text` behavior.
- No provider, pricing, billing, storage-service or job-lifecycle changes. User
  reports generation working. Automated tests use isolated SQLite, fake storage
  and mocked providers; browser interaction remains unverified because both UI
  automation runtimes failed to initialize. See V2-NAVIGATION for details.
- Verification: 25 Multi-Speaker tests / 238 assertions and 23 Apollo/Vector
  regression tests / 159 assertions passed serially; all 46 frontend tests passed.
  The subsequent preview/avatar access test passed with 8 assertions, covering
  Zeta-only access, existing Apollo access, denial and no job/debit creation.
  Asset build, focused PHP lint/Pint, diff whitespace checks and 55 UI message
  keys/placeholders in each EN/AR/KU catalog passed.

## 2026-09-20 — Native Zeta / Theta Multi-Speaker App services

- Replaced the staged Multi Speaker card with Zeta 1.0v and added Theta 1.0v.
  Both use model_2 on the existing Omni endpoint, with builtin_ref_batch and
  audio_url_batch respectively; Apollo/Vector actions and payloads are unchanged.
- Added one-project submission/billing/polling/output integration, ordered segment
  editing, bounded pauses with no trailing pause, owned reusable reference uploads,
  exact URL reuse and sanitized all-or-nothing failure handling.
- Added an additive catalog migration copying initial Apollo 2/Vector 2 pricing
  and plan entitlements, plus existing storage classification and localized V2 UI.
  No public API service/scope expansion, application migration, deployment, worker
  change or live provider call was performed. Migration execution is an operator step.
- Verification: 141 tests / 988 assertions in the broad regression run; final
  batch suite 23 tests / 158 assertions (overlapping coverage), 46 frontend tests,
  build, scoped PHP lint/Pint and localization checks passed. Tests use isolated
  SQLite, fake storage and mocked providers. See SERVICES
  for contracts/operator steps; real worker/GPU and interactive browser acceptance
  remain separate from source and automated test acceptance.

## Shared V2 navigation ownership — 2026-09-16

Centralize page boot/cleanup and destination progress colors in a small registry.
Wait for actual FilePond/plugin/WaveSurfer readiness, retain the owning Livewire
temporary-upload interface and cancel on disposal. Restore FilePond's original
input before Livewire caches the page, then release its instance. Scope OCR PDF
work and events to the mounted page; discard late completion after navigation.
Load Bootstrap's delegated handlers once and dispose page dropdown instances.
No tool submission, credit, billing or rollout policy changes. Local tests and
browser verification are recorded in [V2-NAVIGATION.md](V2-NAVIGATION.md).

## V2 subscription callback preflight — 2026-09-16

Validate recurring FIB callback configuration before creating V2 service checkout
records or coupon reservations. Explain setup failure in EN/AR/KU instead of
misidentifying it as a billing-history blocker. Preserve the epoch boundary and
genuine review guards; no history, prices or provider rules change. A real local
checkout still requires a public HTTPS callback reaching that same application.

## One cutover algorithm, explicit deployment targets — 2026-09-15

Replace the hardcoded local-schema identity with disabled-by-default deployment
assertions and separate local-rehearsal/production policies. Keep the review, patches,
locks, deletion, audit, epoch and preservation algorithm shared. Bind target/server,
migrations, production Admin, backup/restore references and provider disposition to
the review hash. Production refuses MariaDB/read replicas and unresolved obligations
or remaining paid coverage; remote cancellation is never a cutover side effect.
Require target-specific confirmation and production backup/restore attestations.
Admin capability audit now requires all six reported launch capabilities for exit 0.
Document native MySQL acceptance and actual deployment evidence as outstanding gates.

## Shared post-cutover authority and billing history — 2026-09-15

Reuse the successful cutover audit as the sole billing epoch. Apply it to V2 history,
current Admin financial reads, checkout and provider processing; preserve continuous
AI work/credit activity and expose archived financial evidence separately. Current plan
resolution excludes expired retained manual terms and historical provider authority
without rewriting balances/history. Share service/storage eligibility across reads and
cycle guards; validate new paid coverage while allowing legitimate current expiry.
Add read-only Admin capability audit and release preflight, plus a production runbook
that explicitly stops at missing deployment evidence and the local-only cutover guard.
Local mocked fulfillment is separately labelled and is not provider acceptance.


## Separate local billing-domain cutover — 2026-09-15

Add an explicit local-only all-payment cutover without changing conservative reset
or checkout policies. Bind deletion, nullable detachments and provider-subscription
retirement to a whole-database review hash, fresh Admin capabilities, maintenance
and exact transactional preservation checks. Persist the current-revenue boundary
and retired ID watermarks in the same append-only audit as the manifest; scope
current Admin revenue and cache keys while retaining historical order evidence.
No provider/storage calls, credit allocation or historical accounting reclassification.
See [BILLING-DOMAIN-CUTOVER.md](BILLING-DOMAIN-CUTOVER.md) for operator-review and
acceptance boundaries.


## Recurring cancellation intent and replacement recovery — 2026-09-14

Persist renewal cancellation before remote HTTP and keep previously paid access.
Treat cancel acceptance as requested until authenticated GET confirms CANCELLED;
retry pending requests including superseded/expired subscriptions separately from
renewal polling. Fulfilled upgrades/downgrades retain existing allocation policy
and schedule old-provider cancellation after commit. Reject coverage-only extensions
and route post-cancellation collections to review. Customer and Admin projections
separate renewal, access and pending replacement cleanup. See the
[recurring lifecycle contract](RECURRING-SUBSCRIPTION-LIFECYCLE.md).


## Explicit payment history reset — 2026-09-14

Follow-up: the operator requested automatic latest-active selection, so the CLI no
longer accepts/requires a keep UUID. The review hash still binds the selected row
and refuses changed selection/state. Fixed local dry-run SQLSTATE 42S02 / 1146 by
scoping Laravel table listing, locks and engine metadata to the active schema;
unscoped discovery had included unrelated databases. SQL failures now expose only
safe error codes. See the updated runbook for current syntax and verification.

Added a dedicated `billing:reset-payment-history` command instead of widening legacy
cleanup or customer abandonment. One reviewed Payment remains immutable; exact history
deletions and permitted `payment_id` detachments are bound to a deterministic hash,
fresh finance/reconcile authorization, maintenance and stopped-writer attestation.
The whole transaction includes a durable sanitized Admin manifest and full-row
preservation checks. Unresolved obligations, allocation claims and unapproved
dependencies block the entire reset. No application reset was executed. See
[PAYMENT-HISTORY-RESET.md](PAYMENT-HISTORY-RESET.md); native MySQL/RDS acceptance
remains unverified.

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


## 2026-09-13 — Dated cash/external service agreements

- Add Admin-approved start/inclusive-expiry dates and monthly allowance snapshots,
  with externally managed collections and optional informational agreement value.
- Keep future agreements out of current-subscription resolution until activation.
  Reuse normalized subscriptions, durable allocation identities, separate App/API
  ledger entries and shared expiry; preserve add-ons and online billing safeguards.
- Add the non-destructive agreement-table migration and provider-independent lifecycle
  command. Application migration and native MySQL/browser acceptance are not performed.
- Document operator controls and boundaries in SERVICE-AGREEMENTS.md.


## 2026-09-13 — Explicit Admin abandoned-checkout resolution

Reuse P0 invalidation and the Admin SweetAlert review panel to close eligible
ambiguous unpaid checkout with both finance/reconcile permissions and an explicit
reason. Check retained local provider/history/obligation evidence, retain the keyed
operation/audit, and release unused coupons. The customer policy remains unchanged;
closed checkout renders expired with a V2 purchase link. No provider contact or
historical bulk repair. See [PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md).


## 2026-09-10 — Checkout expiry and V2 payment status

- Replace indefinite pending-row blocking with shared provider-deadline/evidence
  policy. Preserve real review/unknown cases; close only known unpaid sessions.
- Move final creation coordination into all three Create* actions across V1/V2.
  Keep old closed Payments immutable as purchase identities; late paid callbacks
  require review instead of fulfillment. Coupon replacement waits for closure.
- Add the owned V2 payment page, bounded status polling, safe continuation links,
  V2 history/return links and timezone-correct checkout timestamp persistence.
- Record unchanged financial policy, local evidence and verification limits in
  [PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md).

## 2026-09-10 — V2 purchase entry and shared billing policy

- Add localized subscription/storage/add-on pages and existing account-dropdown
  links. Fresh catalog/method checks delegate to existing Create* actions and
  shared checkout. Pending owned purchases are reused.
- Share PlanSwitcher's existing calculation with the preview: reset subscription
  allowance, retain App/API add-ons. No price or economic changes.
- Block storage replacement without confirmed remote retirement and non-monthly
  one-time storage whose fulfillment loses the interval. Preserve separate
  cancellation actions and non-destructive quota enforcement.
- Record behavior and local verification limits in [PURCHASE-V2.md](PURCHASE-V2.md).

## 2026-09-10 — V2 Profile and Billing

- Add localized V2 account routes/navigation while retaining V1 routes. Extract
  existing profile and usage handlers into shared Livewire bases so both UIs
  use the same validation, verification, storage and billing queries.
- Add independent avatar saving with replacement persisted before old-object
  deletion. Phone changes retain verification invalidation and return to V2.
- Add separate wallet buckets, server-derived charts, paginated owned payment
  and ledger projections, complimentary classification and existing cancellation
  delegation. Financial policy and historical data remain unchanged.
- Document local read-only browser checks and isolated mutation coverage in
  [ACCOUNT-V2.md](ACCOUNT-V2.md); real delivery/provider acceptance is separate.

## 2026-09-09 — Read-only cutover inventory

- Add `billing:cutover-inventory` with identity-first output, aggregate counts,
  optional sanitized internal details and a conservative unknown-blocks verdict.
  MySQL/MariaDB inventory uses one read-only snapshot; no model mutation,
  provider call or reconciliation path is invoked.
- Retain both wallet buckets and ledger history; purchase provenance is reported
  without changing credits. Production evidence remains operator-controlled.
  The conservative legacy cleanup is unchanged and the destructive cutover
  command is not implemented. See [BILLING-CUTOVER.md](BILLING-CUTOVER.md).

## 2026-09-09 — Complimentary grants and guarded legacy-history review

- Preserve the existing non-revenue manual grant path and durable Admin intent;
  add explicit complimentary reason/type and local expiry metadata, separate
  allowance previews and translated customer-history labels.
- Exclude complimentary access from paid-subscriber reporting and retain an older
  subscription's original financial classification when superseding it.
- Add a local-only, reviewed, transactional abandoned-payment cleanup command.
  The local inventory is blocked by active/unresolved/purchase dependencies and
  has no eligible deletion IDs. No destructive execution or historical repair.
  See [LEGACY-PAYMENT-CLEANUP.md](LEGACY-PAYMENT-CLEANUP.md). Phase 3 remains deferred.

## 2026-09-09 — Billing Phase 2: recurring allocation and paid-through expiry

- Subscription allowances now have durable allocation identities, independent of
  observation events. One additive table protects both subscription/cycle and
  Payment/cycle identity; claims, App/API resets, retained add-ons and ledgers
  commit together. Failed attempts remain retryable without consuming a cycle.
- Paid monthly provider plans no longer receive anniversary-only refills. Annual
  prepaid allowances are monthly slices inside verified coverage. Free and explicit
  manual grants retain their separate calendar policy; initial plan-switch policy
  remains unchanged. Customer/subscription and wallet locks serialize these paths.
- Verified collection evidence survives incomplete observations, paid timestamps
  and coverage remain monotonic, and older/superseded subscriptions cannot become
  authoritative again. Cancellation intent survives ACTIVE responses; a shared
  expiry operation creates an explicit Free subscription at the known boundary
  while retaining paid financial history and existing wallet/add-on balances.
- Missing historical boundaries remain reviewable, with no fabricated receipts,
  period extensions or bulk repairs. Populated allocation history cannot be dropped
  by migration rollback. After implementation, the operator applied the migration
  locally; 10 rollback-only MariaDB acceptance cases passed with saved financial
  fingerprints preserved. Native MySQL concurrency/provider acceptance and B15 creation ambiguity remain
  open. Phase 3 was not started. See [BILLING-AUDIT.md](BILLING-AUDIT.md).

## 2026-09-09 — Billing Phase 1: provider evidence and callback trust

- FIB callbacks are bounded, rate-limited wake-up notifications. Current/stored
  callback paid claims are never financial proof; authenticated GET identity,
  returned money and optional merchant reference are validated before application.
  Rejected observations retain safe reason-coded review evidence, not raw payloads.
  Local paid-application retry requires validated stored GET evidence or a fresh
  GET; neither a local paid flag nor stored callback claims suffice alone.
- Subscription dates use explicit millisecond/strict date parsing and preserve the
  UTC instant through the application's database time zone. Supported values fit
  the current TIMESTAMP era; invalid supplied dates fail closed. Nonterminal stale
  observations cannot regress payment time or coverage; normal terminal sync
  preserves last-payment time while allowing existing period-end handling.
- Legacy Areeba processing requires enabled, configured authenticated delivery and
  provider-scoped intent matching. Raw auth headers and banking extras are excluded
  from new callback receipts. No provider signing protocol is invented.
- Shortened scheduled event source labels fit the existing 40-character schema;
  no migration/history rewrite. Same-Payment fulfillment and Admin P0 remain.
  Monthly/provider credit allocation, durable cycle identity, supersession and
  cancellation precedence are explicitly deferred. See [BILLING-AUDIT.md](BILLING-AUDIT.md).
  No live FIB, native MySQL/RDS or deployment acceptance is claimed.

## 2026-09-08 — Admin usability and configuration consumers

- Dashboard activity now follows the selected period, with lifetime/current labels,
  localized charts, morph refresh and a scoped cache version change.
- Operations gets local-evidence attention groups and a compact expandable job
  table; customer context separates wallets and connects retained records.
- Service actions explain missing capabilities; pricing/access previews become a
  secondary reference. Landing CMS adopts the existing catalog/reason checks.
- Fixed edited exchange rates remaining non-current after bulk deactivation and
  stale currency caches; fixed Landing no-demo rendering and ignored configured
  favicon/Apple icons. Global SEO fallback precedence remains unchanged.
- Added isolated Admin-save-to-consumer regressions. No application database price,
  wallet, ledger, historical data or feature-gate changes. See ADMIN-AUDIT.md.


## 2026-09-07 — Simplify to the existing local database upgrade

The operator cancelled the separate Docker/MySQL acceptance environment. Remove
its prepared Compose/verifier tooling; no container/import was performed. Use the
existing local database, retaining customer/history/financial data, without new
environments, snapshot imports, broad seeders or historical repairs. Actual status
is 66 recorded / eight pending with Admin P0 already applied. Preview succeeded;
normal migration remains manual after a straightforward local export backup.
Compare the captured counts, financial fingerprints, catalog/pricing and schema
afterward. This local MariaDB rehearsal is not RDS MySQL execution evidence.

## 2026-09-07 — R2A retains development MariaDB and isolates MySQL acceptance

The operator chose a separate native MySQL environment rather than replacing the
existing XAMPP/MariaDB installation. Prepare a pinned MySQL 8.4.8 Docker instance
from operator-reported server evidence, on loopback 3307 with separate credentials,
schema and volume. Use only the hash-verified original V1 snapshot: its 65 recorded
migrations imply nine pending, unlike the mutated development database's 66/eight.
Do not seed, transform business data, start workers/reconciliation or enable gates.
An external private environment and guarded config-only/identity entry point avoid
normal development configuration. Preparation does not start Docker, import,
run migrations or establish native/RDS acceptance. See RELEASE-R2A-MYSQL.md.
## 2026-09-07 — R2 requires native MySQL evidence

The operator confirms MySQL locally and Amazon RDS for MySQL in production as
the intended platform. Rename the phase to R2 — native MySQL migration acceptance.
A fresh `SELECT VERSION()` through Laravel's active `mysql` connection returned
`10.4.28-MariaDB`, so native-engine acceptance stopped before migration or
application acceptance. Retain accurate historical R1 MariaDB observations, but
do not treat them as MySQL/RDS evidence. Await the operator's decision on replacing
the local engine or rehearsing on MySQL; no application code, database, environment,
feature flag or worker state was changed. See PRODUCTION-DB-IMPORT.md.

## 2026-09-07 — Approved V2 launch parity through an additive pricing correction

Preserve original Apollo 2/Vector 2/Leo registration migrations because their
execution history across maintained environments is unknown. Append
`2026_09_07_000001_normalize_v2_launch_pricing`: global all/App/Mobile/API rates
are Apollo 2 20/20/20/15 per character, Vector 2 24/24/24/18 per character, Leo
1,100/1,100/1,100/825 per minute; minimums 1/1/1,100, unit 1, ceil/step 1.
Use explicit approved economics, never a tied QASR selection. Reuse stable row
identities, normalize four active global rules and retain superseded global rows
inactive; preserve scoped overrides, legacy rates, grants and P1 API ownership.
Fail transactionally on absent/mismatched registrations; repeated execution is
stable. Pretend skips the data-dependent body; down retains the approved policy.
No customer financial/history mutation or automatic rollout is part of this
change. R1's last observed target would now need eight migrations (74 total after
success), subject to actual history. See PRODUCTION-DB-IMPORT.md and ADMIN-AUDIT.md
for test evidence, explicit manual maintenance and unverified native execution.

## 2026-09-07 — Admin P4B bounded legacy source retirement

Remove only the P4A-proven unused AdminController and CustomerApiTtsService and
the two shadowed Admin navigation components. Retain the runtime-selected App
navigation copies and Livewire location order, current Admin auth/Livewire pages,
active V1/V2 API submission services, public/mobile routes and shared result/job
infrastructure. Source obsolescence does not authorize catalog/history deletion,
V1 UI/API retirement or asset cleanup. Verify each deletion independently against
isolated tests; preserve unrelated baseline failures. See LEGACY-RETIREMENT.md
and ADMIN-AUDIT.md for results and remaining compatibility work. No application
database access, billing-policy or deployment change belongs to this removal.

## 2026-09-07 — Admin P3 presentation and localization

Keep the existing Admin route/Livewire/Bootstrap structure. Consolidate high-impact
confirmation into an Admin-owned SweetAlert bridge that retains P0 operation identity,
blocks duplicate pending clicks and discards stale navigation confirmations. Use
request-local permission hints without replacing server checks. Connect customer
bookmarks, separate operational evidence badges, and expose configured/effective
catalog previews with advanced technical details. Complete scoped EN/AR/KU copy and
RTL presentation, including pagination and current-page chart/dialog labels. Include
locale in dashboard cache identity. No price, wallet, historical data, schema, API
behavior or legacy cleanup changes belong to P3. See ADMIN-AUDIT.md for verification,
two broader customer API test failures and remaining browser/MySQL acceptance.

## 2026-09-07 — Dashboard customer population and cache correctness

Confirmed that the customer-count discrepancy came from cached Laravel configuration
selecting a different local database. Preserve the all-record metric and label it
Total Customers. Read its population/status aggregates once per request; retain
five-minute caching for heavier analytics with opaque database/environment key
separation. No account data, schema or local connection activation was changed.
See ADMIN-AUDIT.md for native read-only evidence and isolated regression results.

## 2026-09-06 — Admin P2 operational visibility

Added read-only Operations and customer detail with separate App/API wallets,
normalized subscription/storage context, bounded job/ledger/payment/API/audit
traces and conservative review queues. Local persisted evidence only; no remote
polling, recovery mutation or historical repair. P0 capabilities/redaction and P1
catalog/runtime scope semantics remain intact. Customer search is bounded;
pricing streams a canonical group index and loads page detail only; currencies
reuse request-local read context. New EN/AR/KU copy follows the Admin area.
See ADMIN-AUDIT.md for verification and remaining acceptance work.

## 2026-09-06 — Admin P1 V2 correctness

- Added a V2-first read projection from existing web/API catalogs and DB rows,
  preserving nine action identities and legacy history. Missing records and
  unresolved migration prices are diagnostics, never automatic repairs.
- Entitlement/configuration writes now preserve explicit scope ownership while
  transactionally deriving V2 family scopes from complete sibling entitlement
  state, including both sides of plan moves. No schema/backfill or V1 removal.
- Grouped App/Mobile/API pricing writes are atomic; read previews reuse runtime
  pricing and effective plan access rather than an Admin pricing/access algorithm.
- Corrected configured Admin area routing for direct/verified Livewire requests,
  with basic direction and technical LTR isolation. P0 invariants remain intact;
  business prices, P2/P3/P4 and deployment acceptance remain open.
  See [Admin P1 implementation and tests](ADMIN-AUDIT.md).

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
