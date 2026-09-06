# MetKurd V2 production-hardening audit

## API V2 implementation phase — 2026-09-06 (current)

The API V2 phase supersedes the earlier API deferral. The native seven-service
API, localized developer portal and shared input boundary are now implemented.
Programmatic V1 remains; the legacy customer portal redirects when Web V2 is
enabled. Authentication, API reservations and file retention were reused after
inspection, while legacy submission/controllers are excluded from the V2 path.
See [API-V2.md](API-V2.md) for the A/B/C dependency classification, complete
contract, focused verification and remaining deployment acceptance. Prior review
results below describe earlier phases, not current API implementation status.
API V2 is not enabled by default and this document does not approve deployment.


### API V2 verification result

- API/security/portal coverage: **30 API V2 tests passed** across the final
  API/dashboard run (38 passed / 349 assertions, including 9 dashboard tests)
  and the later malformed-JSON/oversize guard test (1 passed / 7 assertions).
  Coverage includes both Apollo models, Vector 1.5 upload/2.0 saved reference,
  Leo/Caption/STEM native dispatch, OCR server page counts/ranges/exports, API
  wallet separation, idempotency conflict/replay, known-failure release, ambiguous
  reservation retention, ownership, expired downloads, scopes, concurrency/rates,
  key limits/revocation, EN/AR/KU rendering and gated legacy-page redirects.
- **107 shared-core/dashboard tests passed** in the focused regression set; the
  dashboard subset was rerun after V2-only scope navigation and guest-render fixes.
- Legacy page with Web V2 explicitly disabled: **15 passed, 1 pre-existing
  failure** (monthly API allowance expected 200000, actual seeded 300000). This
  exact mismatch is recorded in the earlier isolated baseline below; no billing
  modernization was made to silence it. In total, these focused checks cover
  152 passing tests and this one legacy failure, not a new full-suite run.
- All nine JSON catalogs parse; all 89 portal/description translation keys exist
  in EN/AR/KU. Technical blocks and customer-name direction were checked in source
  and rendered response tests. Final focused Pint and git diff --check passed.
- 28 generated documentation examples passed PHP/JavaScript/Python syntax checks
  or cURL required-header checks. Three STEM player JavaScript tests passed.
  Vite production build passed. A real local ffprobe run measured a generated
  one-second WAV as one second/one billable minute through InputBoundary.
- Database tests used SQLite :memory:, array cache/session, fake storage and
  mocked HTTP/probes. No production DB, GPU submission, cleanup, migration,
  billing change or rollout operation was performed.
- Remaining acceptance: live GPU/service responses and private storage, actual
  shared-cache/scheduler/queue behavior, deployment migrations/configuration,
  and interactive responsive/RTL browser QA. Portal tests verify rendered HTML
  and Livewire behavior; they do not establish visual browser acceptance.

## Previous core-review scope — 2026-09-06

V2 is the primary/future product. Current readiness covers Apollo, Vector, Leo,
Caption, OCR, STEM 2 and STEM 4 plus V2 Storage/shared infrastructure. Translation,
Neo, Apollo 1.0v and old APIs are excluded; billing/account/subscription changes
are deferred. Prior-phase work below remains historical evidence, not permission
to extend this phase. Unrelated legacy/API test failures are informational only.


## Final V2 core review — 2026-09-06

Scope: the seven services below and Storage. Apollo and Vector each include their
1.5/2.0 catalog variants. Multi Speaker, Caption Edit and Create Ads are coming-soon
entries, not additional implemented services. No API or billing modernization was
performed. Earlier uncommitted hardening, including legacy files, was preserved.

“Checked” below means source tracing and isolated regression evidence, not live
GPU, production infrastructure or visual browser acceptance.

| Service | Submission | GPU lifecycle | Persistence | Cache/reopen | History | Preview/download | Deletion | UI | Remaining |
|---|---|---|---|---|---|---|---|---|---|
| Apollo | Checked: omni_v2, model 1/2 | Coordinated; server reconciliation | Owned WAV before done | Local state, private audio | Owned tool/model history | Authorized audio | Storage | Locked catalog/limits; V2 only | Browser/live-worker acceptance |
| Vector | Checked: owned reference, omni_v2 | Same terminal/ambiguity guards | Owned WAV/reference | Scoped references/history; private audio | Model-scoped | Authorized audio/reference | Storage | Locked catalog/limits; V2 only | Browser/live-worker acceptance |
| Leo | Checked: qasr_v2/asr | Coordinated QASR | Transcript + source metadata | Local text, scoped cache | Deleted results excluded | Private transcript/source | Storage | Completion visible in same response | Browser/live-worker acceptance |
| Caption | Checked: qasr_v2/caption | Coordinated QASR | Text/SRT/segments | Local exports, scoped cache | Deleted results excluded | Private text/SRT/source | Storage + retryable workspace delete | Localized delete failure, immediate completion | Browser/live-worker acceptance |
| OCR | Checked: kocr_v2, server page probe | Coordinated; acknowledged cancel | Owned namespace and verified writes | Local text/source/exports | Owned jobs/artifacts | Authorized source/text/exports | Guarded workspace/Storage | One active polling element, labelled correction switch | Browser, live exports, deployed pdfinfo |
| STEM 2 | Checked: stem.sep2 / 2-track payload | Coordinated STEM | Register required owned tracks | Private tracks; local ZIP cache | Mode-scoped | Tracks + ZIP | Storage + retryable workspace delete | Stable player; transport controller tested | Browser audio/CORS; multi-node ZIP acceptance |
| STEM 4 | Checked: stem.sep4 / 4-track payload | Coordinated STEM | Register four required tracks | Same as STEM 2 | Mode-scoped | Tracks + ZIP | Same as STEM 2 | Mute/solo/play-all controller tested | Same as STEM 2 |

Concrete corrections from this review:

- Explicit V2 endpoint keys are authoritative. Missing configuration cannot fall
  through to a legacy worker or imply accepted work failed/refunded. Accepted jobs
  remain retryable and recover when their endpoint is restored. OCR cancellation
  with missing configuration does not mark cancellation intent or call transport.
- STEM no longer destroys and downloads every waveform on unrelated Livewire
  commits. Keyed, ignored player roots survive updates; removal/navigation releases
  instances. A changed track set changes the key. Three Node controller tests cover
  continuity, volume/transport behavior and cleanup using DOM/media doubles.
- Leo/Caption polling is five seconds, and both refresh their job/computed state
  before returning completion. Apollo/Vector compare the refreshed status and OCR
  clears stale computed state. OCR has one active polling element. Header updates
  remain tied to status transitions. Storage overview reuses request-local files.
- STEM/Caption expose delete_failed retry actions and localized deletion states;
  Leo/Caption omit deleted/deleting history entries. Stored audit rows remain.
- Removed the unused V2 legacy-workspace view, its catalog route/name fields and
  generic OCR/ASR placeholder. Generic speech workspace accepts only native Apollo/
  Vector kinds. Server-derived voice catalogs, limits and STEM mode are locked.
- V2 resource-panel Storage link opens V2 Storage. STEM upload feedback uses the
  shared SweetAlert bridge; its persistent controller reads current-page locale
  messages, including generic upload failures rather than raw transport errors.

### V1 dependency classification

| Dependency still used by V2 | Class | Decision |
|---|---|---|
| MlJob, CustomerFile, ownership controllers, output storage, deletion, synchronizers, execution locks, pricing/debit/refund guards | A — acceptable shared backend | Keep; V2 contracts are selected explicitly, not inherited from V1 UI. |
| Tool/ToolAction and catalog legacy_tool/legacy_action fields | A — stable persisted identities | Keep bindings; names do not imply legacy payloads. Renaming actions would affect customer records and charges. |
| Shared locale middleware/area catalogs, auth/logout, WaveSurfer/FilePond assets | A — shared infrastructure | Keep; V2 notifications use their own SweetAlert boundary. |
| Apollo/Vector render and reference URLs; OCR source/text/JSON routes still named app.renders.* / app.ctts-references.* | B — temporary route migration dependency | Controllers authorize owned results; introduce V2 aliases before retiring the old route group. No old workspace submission is called. |
| Shared process-slot component and account/billing links in the V2 shell | B — temporary migration dependency | Preserve shared job visibility and deferred account/billing navigation; later separate the shell contract when those surfaces migrate. |
| Legacy non-S3 clone reference import and historical STEM path aliases | B — historical data migration dependency | Keep until customer data is migrated; fresh V2 payloads use current contracts. |
| Generic V2-to-V1 workspace fallback and resource-panel V1 Storage destination | C — obsolete coupling | Removed in this review. No global V1 deletion. |

### Storage, alerts, privacy and locale review

Storage root -> owned job folder -> Back to Storage removes folder while retaining
q/product/type/sort. Breadcrumbs and readable labels remain available even when
filters empty a folder. Bulk ownership/size/count limits and destructive-operation
guards remain enforced; no destructive flag was enabled. Library identity mapping
covers Apollo/Vector variants, Leo, Caption, OCR and both STEM modes. Historical
customer files remain visible. Overview reuse removes a redundant active-file query;
large-library grouping remains O(n).

V2 customer popup confirmations and alerts use SweetAlert; normal upload/field
validation remains inline. The V2 source scan finds no native alert/confirm/prompt
calls or wire:confirm. Customer errors are translated/sanitized; remaining provider
name occurrences are internal metadata access and privacy filters. This establishes
coverage of reviewed application-generated messages, not a claim to censor arbitrary
customer text or transcriptions. EN is LTR, AR/KU RTL; dynamic result text uses auto
direction. Browser rendering, locale navigation and third-party controls still need
visual acceptance; source/response/controller checks are distinguished from that.

### Future API preparation — report only

Already reusable: all seven submission services, durable local job/debit boundaries,
V2 payload adapter/STEM builder, synchronizers, poll coordinator, owned persistence,
and authorized artifact controllers. These do not require browser polling to finish.

Before exposing a new API, extract/reuse the actual remaining input boundary:
Apollo/Vector character limits and voice catalog selection/entitlement in app-tool;
Leo/Caption audio type/size/duration probing and billable-minute derivation in their
Livewire upload hooks; STEM upload/duration/plan limits and mode selection; OCR
upload/export-option/range orchestration (the authoritative page probe is already
shared). Service callers currently rely on some locked, server-derived workspace
values; an API must compute these itself and must not accept billable duration or
voice reference metadata as trustworthy request fields. History pagination, preview
selection, clipboard, FilePond and player state are presentation concerns and can
stay in Livewire. No speculative extraction or API refactor was implemented.

### Current acceptance boundary

Remaining V2 release acceptance is actual desktop/mobile and EN/AR/KU browser QA,
live worker contracts, and deployed migration/scheduler/queue/database/storage/CORS/
pdfinfo configuration. Unknown provider acceptance and interrupted deletion require
operator recovery; they are intentionally not blindly retried/refunded. Legacy/API
baseline test failures are informational and excluded from V2 blockers.

### Final verification for this phase

- Selected V2 core and shared infrastructure suite: **157 passed, 11096 assertions**
  (146.64 seconds), using isolated SQLite memory DB, array cache/session, fake storage
  and mocked provider calls. It includes native submissions/workspaces, persistence,
  coordinated reconciliation, Storage, stale cleanup and app/API wallet separation.
- After final completion-response and locale tests: **36 passed, 10483 assertions**
  (16.58 seconds). This covers all seven native workspace component renders in
  EN/AR/KU, Apollo/OCR completion/header transitions, locked fields, delete retry,
  architecture and localization. A separate final endpoint/core run passed
  **29 tests / 141 assertions** after formatting the synchronizers.
- STEM controller: **3 Node tests passed**, exercising the actual workspace script
  with DOM/media doubles. These establish behavior, not visual or real audio QA.
- Production asset build passed; focused PHP Pint passed, all nine V2 component
  PHP blocks passed syntax checks, and git diff --check passed.
- All 12 language files enumerated. Nine JSON catalogs parse; app catalogs contain
  **1706 keys each** with matching keys. Admin has 766 each; landing 985/985/986
  (existing Kurdish extra entry). App/landing values contain no provider name.
  Locale rendering/validation and customer exception privacy tests pass.
- No new legacy/API suite run was needed. The prior full-suite failures below
  remain informational, independently reproduced at the baseline revision.
- No production deployment, migration, GPU submission or destructive operation
  was performed. The earlier local browser access block remains the limit on
  actual desktop/mobile, playback and locale-navigation verification.

## Prior hardening evidence — 2026-09-05

Date: 2026-09-05. Starting revision: e052648. This report supersedes the initial
knowledge-baseline audit. Findings describe repository code and isolated tests;
no production database, billing ledger, object storage, GPU job or rollout was changed.

**The four original P1 failure paths are fixed in code. Production readiness is
not established:** deployment acceptance, actual browser verification and the
V2 core acceptance checks below remain open. Legacy/API failures are informational.

## P1 fixes and evidence

| Original blocker | Implemented behavior | Regression evidence |
|---|---|---|
| Failed object writes could become successful results | WAV/text/uploads and OCR direct exports require put === true. Throws/false prevent metadata, quota and done. Registration and writes lock the customer, account size deltas once, and reject resurrecting deleted rows. | StoragePersistenceTest: false and thrown writes for all three helpers, retry/size accounting, worker registration, failed finalization followed by safe success. OcrPersistenceSecurityTest: inline result write failures. |
| Repeated partial deletion decremented quota again | Only the first counted active-file deletion subtracts bytes. Already-deleted artifacts are no-ops; historical unregistered paths do not subtract guessed sizes. Whole-job delete_failed can be retried, including XTTS/OCR/STEM. | StoragePersistenceTest: first artifact succeeds, second fails, retry preserves unrelated quota. Existing deletion/ownership/guard tests and StorageNavigationAndHistoryTest. |
| OCR/STEM charges could lose their durable job/refund identity | DurableUploadSubmission commits job and app debit together, deduplicates customer/submission keys, preserves existing ToolActions, and retains refund_pending when correction fails. Applied to Leo/Caption/Translation after the same boundary was found there. | DurableUploadSubmissionTest covers duplicate action identities, rollback after debit, confirmed rejection, refund failure/recovery, timeout/503 ambiguity. UploadServicesLifecycleTest exercises OCR and both STEM submitters; TranslationLifecycleTest exercises the real V1 component. |
| Closing the browser stopped completion | Each-minute scheduler queues existing synchronizers. Database leases and 10–60 second shared intervals coordinate browser/API/worker checks. Terminal rows stop GPU calls; temporary faults remain retryable. Finalizers recheck active state under lock. | JobReconciliationTest covers completion without a browser, stale browser instances, shared intervals, transient network failures, terminal/leased states and stale-cleanup races. |

Unknown acceptance (timeout/5xx/missing provider ID or local failure after dispatch)
is never blindly replayed or refunded. Known failed app refunds require the actual
app debit. API reservations retain their own wallet settlement. Stale cleanup
cannot age-fail a charged, attempted or accepted job, including a locked recheck.

## Service audit

All rows were traced through source validation/pricing, local job/debit, provider
submission/ID, synchronization, persistence, customer history, preview/download,
delete and failure. Contract verification is against current application source
and mocked provider responses; it does not establish the deployed worker version.
Full option details remain in [SERVICES.md](SERVICES.md).

| Service | Submission / current worker contract | Completion, cache, history and deletion |
|---|---|---|
| Apollo | xomni.generate / xomni-v2.generate; omni_v2; model_1/model_2; builtin_ref, language/text_language, WAV/base64. Existing local transaction retained; locked request key rotates after success. | Coordinated XTTS sync requires completed success and stored audio. Owned render routes and browser-private audio caching remain; history uses local jobs. Retryable whole deletion and file/history reconciliation; safe display errors and conservative submission failure handling. |
| Vector | clone_xomni.generate / vector-v2.generate; omni_v2; model_1/model_2; audio_url mode, trusted signed owned reference, ref_text, max 20-second reference. | Same finalizer; reusable references remain separate from rendered audio. Customer/version reference and history caches retained and invalidated on changes. No repeated cache invalidation on unchanged polls. Upload/legacy-reference ownership and deletion tests remain applicable. |
| Leo | leo.transcribe; qasr_v2; asr, fine_tuned, ckb/ar/en, intelligent 0/1. Audio MIME/100-MB validation and server duration; computed billing metadata locked. | Coordinated QASR sync stores transcript and job text. Private text/audio routes, 20-second inactive-history cache. Source deletion clears preview links; result deletion clears paths/history and cache; whole-job partial failure is retryable. |
| Caption | caption.standard; qasr_v2; caption, fine_tuned, language/intelligent, SRT and segments, established 8-word / 6-second / 1-second caption options. | QASR persists transcript and available SRT/segments before done. Private owned downloads and inactive-history cache retained. Deleting one result preserves remaining artifacts; final result deletion marks history deleted and invalidates Caption cache. Inline SRT can still be generated from retained application state when available. |
| OCR | ocr.standard; kocr_v2; layout_text, verified pages, intelligent, DPI 160, max_pixels 1000000, max_tokens 4000, correct_tables. Internal compact HTML plus selected exports; 900000 execution timeout / 1200000 TTL policy. | Coordinated OCR sync requires persisted text; text/JSON/all artifact keys must be inside the owned job root. Selected DOCX/Markdown/HTML/ZIP exports are checked writes. Local job history, document/text previews and owned downloads; retryable guarded deletion. Remote cancellation needs acknowledgment and retains inputs if uncertain. |
| STEM 2 | stem.sep2 (catalog corrected); stem endpoint; stems=2, htdemucs_ft, cuda, MP3/192k, signed GET source and application-generated signed PUT targets. | Coordinated STEM sync requires vocals/instrumental objects, registers outputs/quota once, then done. Local mode-specific history and private tracks/ZIP. Individual deletion removes missing tracks from presentation; whole deletion retains retryable failure state. |
| STEM 4 | stem.sep4; same builder and endpoint with stems=4 and existing pricing context. | Requires vocals/drums/bass/other. Same persistence, mode-specific history, caching, ownership, deletion and errors as STEM 2. |

Provider queued/capacity states map to queued; in-progress states remain running.
Only COMPLETED/SUCCESS can finalize. FAILED/ERROR/CANCELLED/TIMED_OUT and explicit
output success=false/ok=false cannot be converted into done by partial output.
Local deleting/deleted/delete_failed/cancelled/failed/done states do not poll GPU.

### Additional OCR billing correction

The original UI trusted clientPdfPageCount. OcrDocumentProbe now verifies PDF
pages before display pricing and again before debit. Custom ranges are bounded
before expansion, deduplicated and sent as the same pages being billed. Invalid,
unreadable, unverified and over-3888-page PDFs fail before charging. Image inputs
retain the existing one-page worker contract. Local and shared-disk temporary
uploads are supported; shared uploads are streamed into a bounded temporary file
and removed after probing. Tests use a mocked process, not a live production parser.

This requires maintained Poppler pdfinfo on application servers, configurable
with OCR_PDFINFO_BINARY. Existing API/mobile/V1 OCR entry points retain their old
client-count contracts; this change secures the native V2 OCR path only.

## Performance and GPU queries

- V2 browser polling changed from 500 ms/1.5–2 seconds to 5 seconds. A shared
  database interval allows at most one due provider check per job across callers,
  starting at 10 seconds and backing off to 60. In-flight claims expire after
  five minutes. Terminal/history/preview/download requests use owned state.
- Header refreshes happen on status changes instead of every unchanged tool poll;
  Vector history invalidation moved to actual lifecycle mutations.
- Storage source metadata is reused within a request instead of querying it per
  folder/row. Folder date sorting uses timestamps; byte formatting is corrected.
- Existing plan/locale speaker catalogs, customer reference/history caches,
  private audio response caching and stable keyed players are retained. No public
  cache was added for customer data. No latency/load benchmark is claimed.
- Storage root grouping still materializes the customer's file set. This remains
  O(n) for very large libraries; no speculative data-model rewrite was introduced.

## Storage experience and deletion semantics

The folder view has Back to Storage plus a readable breadcrumb. Returning removes
folder while preserving q/product/type/sort. Owned filename/job metadata supplies
the primary label instead of a UUID, including filtered-empty folders. Other
customers' labels and objects remain inaccessible.

Filters, focus states, action labels, file-type distinctions, long filenames,
mobile rows and empty states were improved. Root empty, folder empty and no
matching results have different messages. Bulk selection excludes folders and
retains owned download/delete handling and existing archive limits (25 files,
100 MB). Deletion guards remain mandatory; no production flag was enabled.

File deletion and history reconciliation commit together. Source deletion only
clears source references. Result purposes include render, transcription, caption
and target_text. Partial results retain usable artifacts; deleting the last
registered result marks the job deleted without destroying the financial record.
Leo/Caption/clone caches invalidate. Already delivered browser-cached bytes cannot
be recalled, and signed links still expire under their existing policies.

## SweetAlert, provider privacy and localization

V2 contains no remaining native alert()/confirm()/prompt() or wire:confirm action
handlers. The shared SweetAlert bridge handles confirmations, bulk delete and
Livewire request errors; validation stays inline. It is guarded across navigation,
uses text content, translated buttons, and document direction. JavaScript stub
checks include evaluating the script twice, one error hook, confirmation direction
and provider-error fallback. These are not actual browser interaction tests.

Audited V2 system error displays, public component error assignments, app exception
responses (including debug mode), app/landing catalog values and notification
bridges filter provider diagnostics. Internal configuration/class names, logs,
compatibility translation keys and customer-generated result text are preserved.
No customer-facing app/landing catalog value contains RunPod. This is a source/test
claim for the audited boundaries, not proof of every historical user-generated
record or live backend response.

EN uses LTR; AR and Sorani KU use RTL. Editors, results, filenames and breadcrumbs
use dir=auto when their content can differ from the UI language. PHP validation
groups now translate workspace required/file/MIME/size/type rules and field names
in all three locales. Catalog/token/rendered-response checks cover all relevant
JSON and new PHP files. Native-speaker and visual browser acceptance remain open.

## Prior-phase verification

- Final isolated full Pest run: **460 passed, 5 failed, 12831 assertions (219.43 seconds)**.
- Four API failures reproduce unchanged in an isolated e052648 worktree: API
  wallet expectation (200000 vs 300000), scoped product voices (200 vs 403), and
  two renamed product-route fixtures inserting pricing_rules.tool_action_id=0.
  The existing updated-phone verification test also reproduces its missing
  digit1 Livewire property on e052648. These are not claimed fixed by this phase.
- Focused storage/reconciliation run: 41 passed / 197 assertions. Focused
  persistence/probe/localization/upload run: 37 passed / 10364 assertions before
  the last added shared-upload/race cases. Clone workspace: 11 passed / 84 assertions.
  A final targeted run covers the subsequent cancelled-OCR cleanup and
  OCR workspace locale checks: **65 passed / 10509 assertions**.
- All nine JSON catalogs parse; app catalogs have 1704 keys each. No baseline
  keys or replacement tokens are missing across EN/AR/KU; app/landing values
  contain no provider name.
- Vite production build passed. Changed PHP Pint passed (48 files at its last
  pre-final-suite check); git diff --check passed. Notification stub checks passed.
- SQL tests used SQLite :memory:, array cache/session, synchronous test queue,
  fake storage and mocked provider calls. PDF process behavior was fault-injected.
  No production migration, charge, cleanup, deployment or live GPU call was made.
- A separate local SQLite/file-storage browser fixture was prepared. The browser
  tool blocked both 127.0.0.1 and localhost with ERR_BLOCKED_BY_CLIENT. Consequently
  no actual desktop/mobile playback, modal, selection or navigation pass is claimed.
- One intermediate full run overlapped fake-storage suites and produced a clone
  file failure; its isolated rerun passed. The final full suite ran alone. The temporary browser
  server was stopped and the baseline comparison worktree removed.

## Remaining risks and release acceptance

1. Actual browser verification remains blocked: Storage desktop/mobile, long
   filenames, folder/root navigation, selection/bulk actions, previews/players,
   SweetAlert and EN/AR/KU locale switching still need visual acceptance.
2. Apply the additive polling migration before new code and verify scheduler,
   worker timeout (240 seconds), retry_after, deployed database locking and queue
   throughput against provider result TTL. Confirm pdfinfo availability, temp
   disk probing, S3 ACL/CORS and PHP/ingress/Livewire upload limits. None was
   verified in production; do not enable V2 solely on these repository tests.
3. Lost acceptance responses without provider IDs, interrupted preparation and
   interrupted whole-job deletion need operator evidence/recovery. Durable rows
   preserve intent; there is no unsafe automated replay/refund or historical
   ledger repair. Monitor queue lag, pending refunds and unknown submissions.
4. Large Storage libraries still have O(n) root grouping; STEM ZIP caches on local
   disk need shared-cache/multi-node acceptance. Browser-cached results cannot be
   revoked after delivery. These are retained architectural limits, not new cache promises.

The cancellation transport was checked against the provider's
[operation reference](https://docs.runpod.io/serverless/endpoints/operation-reference)
and [job states](https://docs.runpod.io/serverless/endpoints/job-states).
No live cancellation or deployed worker contract acceptance is claimed.
