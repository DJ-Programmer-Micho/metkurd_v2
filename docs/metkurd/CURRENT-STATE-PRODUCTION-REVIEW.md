# MetKurd V2 current-state and release review — 2026-09-26

Scope: review, isolated verification and a narrow preflight correction. Base revision
`3517a945672ae2af4818a5afe92c4ce000a1487d`. This is not deployment approval.
The [production runbook](V1-TO-V2-PRODUCTION-RUNBOOK.md) remains the operator procedure.

Verdict for the reviewed V2 scope and tested preflight correction:
**SOURCE READY — PRODUCTION ACCEPTANCE STILL REQUIRED**. The missing standalone
plan badge/CTA is documented as an unimplemented UI expectation; concurrent
maintenance work and the final immutable artifact require separate verification.

Evidence labels used throughout:

- **SOURCE VERIFIED**: inspected implementation/configuration, not a claim about deployed data.
- **LOCAL VERIFIED**: isolated tests or production asset compilation in this workspace.
- **USER-REPORTED WORKER ACCEPTANCE**: the user's successful service, Zeta/Theta,
  Harakat and STEM reports; no independent endpoint/image/GPU certification in this review.
- **PRODUCTION UNVERIFIED**: requires the operator's actual target, processes or approved transactions.

No application/customer database was queried for catalog or migration status. No gates,
credentials, endpoint IDs or economics were changed. No migrations, cutover, provider
calls, storage deletion or deployment were executed against an application database.
Test fixture migrations/seeding use SQLite memory only. The user reports the local
cutover already committed: do not repeat it. Historical MariaDB evidence does not
certify native RDS MySQL.

## 1. Authoritative product matrix — SOURCE VERIFIED

Route column is relative to `/{locale}/app-v2/`. Submission names below are classes
in `app/Services/MetKurd/Jobs`; synchronizers are existing XTTS/ASR/OCR/STEM/Harakat
services. `runpod.endpoints.` prefixes each endpoint key. These are twelve variants,
nine public generation routes and seven API families; model and processing mode
are separate. API availability always means implemented behind its disabled-by-default gate.

| Product | UI route | Tool / ToolAction | Metric | Submission | Endpoint / worker contract | Synchronizer / persisted result |
|---|---|---|---|---|---|---|
| Apollo 1.5 | `text-to-speech/apollo-1` | `xomni` / `xomni.generate` | character | OmniSubmissionService | omni_v2; model_1 + builtin_ref | XttsJobSyncService; private WAV |
| Apollo 2.0 | `text-to-speech/apollo-2` | `xomni-v2` / `xomni-v2.generate` | character | OmniSubmissionService | omni_v2; model_2 + builtin_ref | XttsJobSyncService; private WAV |
| Zeta 1.0 | `text-to-speech/zeta-1` | `zeta` / `zeta.generate` | character, sum of segments | MultiSpeakerSubmissionService | omni_v2; model_2 + builtin_ref_batch | XttsJobSyncService; one private WAV |
| Vector 1.5 | `clone-text-to-speech/vector-1` | `clone_xomni` / `clone_xomni.generate` | character | CloneOmniSubmissionService | omni_v2; model_1 + audio_url | XttsJobSyncService; private WAV |
| Vector 2.0 | `clone-text-to-speech/vector-2` | `vector-v2` / `vector-v2.generate` | character | CloneOmniSubmissionService | omni_v2; model_2 + audio_url | XttsJobSyncService; private WAV |
| Theta 1.0 | `clone-text-to-speech/theta-1` | `theta` / `theta.generate` | character, sum of segments | MultiSpeakerSubmissionService | omni_v2; model_2 + audio_url_batch | XttsJobSyncService; one private WAV |
| Leo | `speech-to-text/leo` | `leo` / `leo.transcribe` | minute | LeoSubmissionService | qasr_v2; type=asr, fine_tuned, ckb/ar/en | QasrJobSyncService; owned input, text/TXT |
| Caption | `speech-to-text/caption` | `caption` / `caption.standard` | minute | CaptionSubmissionService | qasr_v2; type=caption, fine_tuned, ckb/ar/en, SRT/segments | QasrJobSyncService; owned input, text/SRT |
| OCR Scanner | `ocr/scanner` | `ocr` / `ocr.standard` | page | OcrV2SubmissionService | kocr_v2; signed file_url + server-owned layout_text options | OcrJobSyncService; source, text and export artifacts |
| Harakat 1.0 | `ocr/harakat-1` | `harakat` / `harakat.diacritize` | character | HarakatSubmissionService | tashkeel_v1; job_id, source_mode=text, text | HarakatJobSyncService; inline text + private TXT |
| STEM 2 | `stem/2-stem` | `stem` / `stem.sep2` | stem_output | StemV2SubmissionService | stem; stems=2, signed source + upload targets | StemJobSyncService; original, tracks/result metadata, ZIP download |
| STEM 4 | `stem/4-stem` | `stem` / `stem.sep4` | stem_output | StemV2SubmissionService | stem; stems=4, signed source + upload targets | StemJobSyncService; original, tracks/result metadata, ZIP download |

STEM quotes include output count, server-probed seconds and billable minutes;
do not substitute a guessed duration-only metric. Text counts and audio/page probes
are server authority. UI estimates are not billing input authority.

| Product | Public API submission / selector | Family scope | App and API pricing / registration |
|---|---|---|---|
| Apollo 1.5 | `/api/v2/speech`, model `1.5` | v2:speech | Independent xomni rules by channel; retained catalog, no new V2 registration migration |
| Apollo 2.0 | `/api/v2/speech`, model `2.0` | v2:speech | Aug15 xomni-v2 registration; Sep7 normalization baseline App 20/API 15 per character |
| Zeta | `/api/v2/zeta` | v2:speech | Sep20 independent rows initially copied from Apollo 2 |
| Vector 1.5 | `/api/v2/voice-clone`, model `1.5` | v2:voice-clone | Independent clone_xomni rules by channel; retained catalog |
| Vector 2.0 | `/api/v2/voice-clone`, model `2.0` | v2:voice-clone | Aug15 vector-v2 registration; Sep7 baseline App 24/API 18 per character |
| Theta | `/api/v2/theta` | v2:voice-clone | Sep20 independent rows initially copied from Vector 2 |
| Leo | `/api/v2/transcriptions` | v2:transcriptions | Aug16 leo registration; Sep7 baseline App 1100/API 825 per minute |
| Caption | `/api/v2/captions` | v2:captions | Aug16 caption access registration; existing rules retained, missing rule baseline all-channel 1000/minute |
| OCR | `/api/v2/ocr` | v2:ocr | Existing ocr action and channel-specific page rules; retained catalog |
| Harakat | `/api/v2/harakat` | v2:harakat | Sep21 independent rows initially copied from Apollo 2 |
| STEM 2 | `/api/v2/stem`, mode `2` | v2:stem | Existing stem.sep2 channel rules; retained catalog |
| STEM 4 | `/api/v2/stem`, mode `4` | v2:stem | Existing stem.sep4 channel rules; retained catalog |

These are **migration baselines, not approved current production prices**. The
current runtime quote uses the effective customer's action/channel pricing and
entitlements. All twelve active tools/actions, effective App/API prices, denies,
customer overrides and plan voice access must be inspected on the actual target.
No source review can assert those production rows exist. Missing/nonpositive
service pricing fails submission. API family permission does not replace action
entitlement or API enablement. Existing balances do not confer a paid plan.

New-service migrations copy pricing_rules and plan_entitlements, including
channel/deny rows, only when creating the target action; they do not copy customer
overrides, voices or automatically expand API scopes. Existing target actions skip
the copy, preserving Admin economics but also requiring explicit review of any
incomplete pre-existing target. Later prices and entitlements are independent.
AdminEntitlementScopes preserves explicit scopes and transactionally recomputes
derived scopes on supported Admin entitlement changes. Inspect scope grants after
migration; copying rows does not by itself grant Harakat API access.

Source: config/metkurd_v2.php, routes/web.php, ApiCatalog, submission services,
RunPodV2Adapter, synchronizers and the named migrations.

Single Omni requests retain text, ref_audio or signed audio_url, ref_text,
language/text_language, model, mode, output_format and return_base64; Vector
also supplies bounded ref_max_sec. Caption adds return_srt/return_segments and
existing subtitle grouping limits. STEM sends audio_ext, htdemucs_ft/cuda,
mp3/192k defaults and signed original_wav/result_json/stem upload targets.
These are internal worker contracts; customer API selectors are translated by
the native submission layer and cannot supply arbitrary worker upload targets.

## 2. Migration inventory and sequencing

**SOURCE VERIFIED:** 78 migration files; the only additions after the Sep15
runbook are Sep20 multi-speaker and Sep21 Harakat. No Process Queue migration.
The older 11-pending count would become **13 only for the unchanged original
snapshot**, and the old R1 count of 10 would become **12 only for that unchanged
baseline**. Neither is a statement about today's local or production database.
Actual target `migrate:status`, including unknown migration rows, always wins.

| Migration stem | Type and purpose / ordering |
|---|---|
| 2026_08_12_000000_add_submission_lifecycle_to_ml_jobs_table | Schema: durable submission/debit/recovery identity |
| 2026_08_15_000000_register_xomni_v2_tool | Catalog: Apollo 2 tool/action, initial plan access/pricing |
| 2026_08_15_000100_register_vector_v2_tool | Catalog: Vector 2 tool/action, initial plan access/pricing |
| 2026_08_16_000100_register_leo_v2_tool | Catalog: Leo action/access and QASR-derived/fallback pricing |
| 2026_08_16_000200_register_caption_v2_access | Catalog: Caption action/access; add pricing if absent |
| 2026_09_05_000001_add_poll_coordination_to_ml_jobs | Schema: shared polling lease/due time |
| 2026_09_06_000001_add_v2_idempotency_to_api_jobs | Schema: V2 API intent/fingerprint identity |
| 2026_09_06_000002_add_admin_operation_safety | Schema: Admin capability, operation and audit readiness |
| 2026_09_07_000001_normalize_v2_launch_pricing | Data: replaces selected global baseline rates; review its impact if still pending |
| 2026_09_09_000001_create_subscription_credit_allocations_table | Schema: subscription allocation evidence |
| 2026_09_13_000001_create_service_plan_agreements_table | Schema: dated external agreements |
| 2026_09_20_000001_register_multi_speaker_tools | Catalog: zeta/theta, source action + active pricing prerequisites; copy independent plan/pricing rows |
| 2026_09_21_000001_register_harakat_tool | Catalog: harakat, Apollo 2 action + active pricing prerequisites; copy independent plan/pricing rows |

The older 65 files provide base schema, legacy catalog structures, payment/API
storage and channel separation; full filenames are listed in the inventory appendix.
Legacy xomni/clone_xomni/ocr/stem catalog data are baseline prerequisites, not
magically supplied by their table-creation migrations. A blank database is not
equivalent to the approved V1 snapshot. Do not use generic seeders to fill a gap.

New registrations require normal timestamp ordering after source registration and
pricing. No new production seeder is required. Do not replay historical registration
or normalization migrations to repair catalog data: some use updateOrInsert and
can overwrite configuration. Review pending data migration effects before approval.
New migrations' down methods deliberately retain history-linked identities.
SQL pretend output cannot validate their copied data or native MySQL behavior.

## 3. Sanitized endpoint and worker checklist

| Config key | Environment name | Services / required capability | Evidence |
|---|---|---|---|
| runpod.endpoints.omni_v2 | RUNPOD_ENDPOINT_ID_OMNI_V2 | Apollo/Vector both checkpoints; model_2 Zeta/Theta batch modes | SOURCE VERIFIED; USER-REPORTED WORKER ACCEPTANCE |
| runpod.endpoints.qasr_v2 | RUNPOD_ENDPOINT_ID_QASR_V2 | Leo ASR and Caption SRT/segments | SOURCE VERIFIED; user's general service acceptance |
| runpod.endpoints.kocr_v2 | RUNPOD_ENDPOINT_ID_KOCR_V2 | OCR signed document, pages/layout text and exports | SOURCE VERIFIED; user's general service acceptance |
| runpod.endpoints.stem | RUNPOD_ENDPOINT_ID_STEM | 2/4 stems and presigned output uploads | SOURCE VERIFIED; USER-REPORTED WORKER ACCEPTANCE |
| runpod.endpoints.tashkeel_v1 | RUNPOD_ENDPOINT_ID_TASHKEEL_V1 | Harakat text-mode success envelope and text/chunks | SOURCE VERIFIED; USER-REPORTED WORKER ACCEPTANCE |

Five endpoint configurations cover current V2. No second Omni endpoint is needed.
RunPod Serverless workers supply concurrency; one complete batch occupies one
worker/model session. Confirm deployed image digest, model mounts, GPU capacity,
job execution/queue limits and result retention privately. Local worker source is
not proof that the endpoint uses it. Legacy endpoint keys remain for outstanding
V1/shared jobs, not fallbacks for missing V2 endpoints.

Shared Laravel configuration: RUNPOD_API_KEY, RUNPOD_BASE_URL, RUNPOD_TIMEOUT,
RUNPOD_V2_TIMEOUT and RUNPOD_V2_INPUT_HOSTS. The adapter accepts trusted HTTPS
input hosts derived from explicit configuration and the S3 configuration. Confirm
the resolved host allowlist matches signed URLs; never publish credentials/URLs.
OCR's provider policy is executionTimeout=900000 ms, ttl=1200000 ms.

### Zeta and Theta

The local Omni worker was inspected read-only at
`G:\ai\00-runpod\omni-runpod-worker`; its documented source image target is
`metkurd/omnivoice-runpod-worker:0.0.11`. Do not infer its deployed version.
**OMNI_BATCH_ENABLED=true is required on the production worker** when enabling
Zeta/Theta; its source default remains false. Laravel sends explicit model_2,
one ordered segments array, output_format=wav and return_base64=true.

Zeta resolves allowed active Voice.code through OmniSpeakerCatalog, using
catalog ref_audio/ref_text, not fabricated style/emotion controls. Verify exact
relative files under OMNI_REF_VOICES_DIR on the worker volume, including historical
spellings and matching transcripts. Paths, model_1/model_2 checkpoints and local
ASR dependencies must exist; catalog metadata alone cannot establish that.
Worker path settings include OMNI_VOLUME_ROOT, OMNI_MODELS_DIR, OMNI_REF_VOICES_DIR,
OMNI_MODEL_PATH, OMNI_MODEL_V2_PATH, OMNI_ASR_MODEL_PATH, OMNI_OUTPUTS_DIR,
OMNI_TEMP_DIR and OMNI_RUNTIME_DIR. Preserve the established default model and
temporary-directory cleanup policy; batch explicitly selects model_2 regardless
of OMNI_DEFAULT_MODEL. Inspect the actual container environment/start command
privately, rather than assuming Laravel's environment configures the container.

Theta uses owned active unexpired reference IDs. Each distinct stored object is
validated and signed once for 120 minutes; identical references receive identical
URLs within that job. Worker download/prepared-reference/prompt reuse is job-local,
with bounded prompt entries; no cross-customer embedding cache. Laravel supplies
ref_max_sec=20/ref_sample_rate=24000. Account quotas apply to reusable references.

| Bound | Laravel / public contract | Worker source default and environment |
|---|---|---|
| Segments | 25 | OMNI_BATCH_MAX_SEGMENTS=25 |
| Characters per segment | 500 | OMNI_BATCH_MAX_SEGMENT_CHARS=500 |
| Total characters | 5000 | OMNI_BATCH_MAX_TOTAL_CHARS=5000 |
| Pause | 0, 500, 1000, 2000 ms; final forced 0 | OMNI_BATCH_MAX_PAUSE_MS=2000; any bounded integer internally, no trailing pause |
| Reference size | 20 MiB each, 100 MiB distinct total | OMNI_BATCH_MAX_REFERENCE_MB=20; OMNI_BATCH_MAX_TOTAL_REFERENCE_MB=100 |
| Reference transcript | App max 4000 | OMNI_BATCH_MAX_REF_TEXT_CHARS=5000 |
| Reference duration | 20 seconds supplied | OMNI_BATCH_MAX_REF_SECONDS=30 |
| Prompt cache | internal | OMNI_BATCH_PROMPT_CACHE_ENTRIES=4 |
| Generated audio / encoded file | no extra public capacity promise | OMNI_BATCH_MAX_AUDIO_SECONDS=600; OMNI_BATCH_MAX_OUTPUT_MB=8 |
| Final encoding timeout | internal | OMNI_BATCH_FFMPEG_TIMEOUT_SECONDS=120 |

These are source bounds, **not load-tested production capacity recommendations**.
The 8 MiB encoded limit may be reached well before 600 seconds with WAV; base64
adds transport overhead. Test maximum accepted projects, provider response limits,
CPU/VRAM, repeated warm jobs and queue delay before approving launch capacity.
Do not raise limits just to make an acceptance test pass.

Worker assembly copies matching PCM16 WAV frames incrementally using SoundFile,
inserts exact rounded silence frames between segments and uses FFmpeg for final
conversion where needed. No new final normalization or resampling; reference
preprocessing retains its existing behavior. One model session spans all inference.
Failures are all-or-nothing, partial files and job-local cache are cleaned. A
worker job_id is correlation, not retry idempotency: reconcile ambiguous dispatch
before any full-batch retry. Laravel completion additionally requires persistence.

### Harakat

Dedicated Tashkeel endpoint, independent harakat.diacritize character quote.
App/API expose direct UTF-8 text only, bounded by HARAKAT_MAX_TEXT_CHARS (5000
default). Worker request input is exactly job_id/source_mode=text/text. No URL
or file contract is exposed by these products. Synchronization validates explicit
success, matching identity/mode, bounded nonempty UTF-8 output and positive chunks;
private harakat.txt is registered before done. Storage failures retry persistence
for the same accepted job. Align actual worker limits with Laravel's limit.

## 4. API boundary — SOURCE VERIFIED

Nine POST service routes are in the matrix. Auxiliary routes: POST /api/v2/references,
GET /api/v2/voices, GET /api/v2/services, GET /api/v2/jobs/{id}, and
GET /api/v2/files/{id}/download. FEATURE_API_V2 remains false by default and is
independent of FEATURE_APP_V2. Authentication, customer/key rate limits, effective
API-enabled plan, plan/key family scopes and per-action API entitlement all apply.
v2:jobs:read/v2:files:download are auxiliary access scopes. Legacy exact V1 scope strings
do not automatically authorize V2 families; supported explicit wildcards are distinct.

Idempotency-Key binds the customer request fingerprint, including uploaded bytes.
Accepted identical replay returns the same API job, mismatches fail 409. Native
SubmissionContext attaches one MlJob and one API reservation, separate from App
debits. Completion settles once; failure releases once; ambiguous dispatch stays
under durable recovery, not blind paid resubmission. Status uses persisted MlJob
and can settle local reservations; it is not the Process Queue's pure read path.

Public serialization allowlists public service/model selectors, sanitized status,
counts/text and owned application download links. Internal model_1/model_2, action
IDs, provider identifiers, signed object URLs and worker envelopes are not public
generation results. Public Apollo/Vector selectors 1.5/2.0 are intentional.
Voices returns only Voice.code id/name from the effective plan's valid catalog.
Voice catalog cache is plan/locale scoped; model updates invalidate it, while bulk
SQL changes require explicit invalidation or TTL expiry.

References upload requires v2:voice-clone plus Theta's API action entitlement;
it creates a reusable private quota-counted file, no generation/reservation.
Foreign/expired references are rejected. Zeta segments use public voice; Theta
segments use reference_id/reference_text; both accept text/language/pause_after_ms.
No public internal worker fields are needed. API OCR accepts multipart local file
bytes (100 MiB maximum before hosting limits), not file_url. It probes document
pages before pricing, stores the owned source, and creates the worker URL internally.
See API-V2.md for exact public JSON/multipart examples and portal behavior.

## 5. Process Queue, topbar and navigation

**SOURCE VERIFIED:** CustomerProcessQueue performs one customer-scoped MlJob query
with a ToolAction join, active first/recent 24-hour terminal rows, 13-row fetch and
12-row display. It projects no content/provider/file/financial payload. No sync,
refund, reconciliation, storage probe or billing mutation is reachable from its
refresh. Only persisted done means Ready; saving is not Ready. All twelve action
identities link to their proper localized result workspace through queue_job;
the workspace rechecks customer, App markers, action and entitlement.

The shared navigation registry owns one controller; disposal removes timers and
listeners. A module-wide in-flight guard serializes old/new shell reads. Active
interval is 8 seconds after response, idle/error 60 seconds; hidden tabs schedule
no reads. Mount/visible/open/generic metkurd:job-submitted trigger immediate reads.
Active yellow overrides unseen failed red, then unseen successful green, else gray.
Per-customer session tokens are capped at 512; opening acknowledges, no DB seen
column. At more than twelve active rows, terminal notices can wait for list space.
PROCESS-QUEUE.md agrees with source. Interactive Network-tab/mobile/RTL acceptance
and native MySQL query-plan/latency remain **PRODUCTION UNVERIFIED**.

**UI discrepancy:** resources/views/app/v2/partials/topbar.blade.php currently has
language, API, Process Queue and profile dropdown controls. It does **not** render
a standalone effective-plan badge or Subscribe button. The dropdown's subscription,
storage and add-on links correctly target V2. Do not claim the requested badge/CTA
already exists; adding it is a separate feature decision, not part of this audit.

AppShellData already obtains currentServicePlan through shared billing authority
and prepares Free/Student/Pro/Premium presentation. Its keys include effective
plan/subscription, customer revision, billing epoch and catalog version; committed
subscription/wallet changes invalidate customer shell state. Carried credits do
not determine the plan. No new authority is introduced in the topbar. Process
Queue allowance is PlanConcurrencyService at mount/plan-update, not each poll.

Vite app.js includes navigation/assets/upload/multi-speaker/STEM/Process Queue/
waveform/account/payment controllers. FilePond cleanup/readiness, WaveSurfer and
SweetAlert remain under established asset/navigation ownership. EN/AR/KU server
rendering and frontend lifecycle tests are local evidence, not visual acceptance.
Verify translated errors, mixed text direction, locale navigation, Back/Forward,
mobile dropdown fit and no duplicate reads/listeners against the release artifact.

## 6. Storage — SOURCE VERIFIED, production objects unverified

CustomerOutputStorage owns private S3-compatible input/output registration and
quota accounting. Verify AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY (or approved
credential mechanism), AWS_DEFAULT_REGION, AWS_BUCKET, AWS_ENDPOINT, AWS_URL and
AWS_USE_PATH_STYLE_ENDPOINT as appropriate, without printing values. App files
are permanent unless otherwise recorded. API temporary output defaults to seven
days via CUSTOMER_API_TEMP_FILE_TTL_DAYS, excluded from permanent quota; permanent
API results count toward quota. API expiry denies access before physical cleanup.
Theta's standalone reusable references stay permanent/quota-counted.

Persist one Zeta/Theta WAV, Harakat TXT, OCR owned source/artifacts, Leo/Caption
transcription files, and STEM tracks before local completion. Preview/download
rechecks ownership and active/unexpired file metadata; routes performing reads
also check the object. Preserve private buckets and signed worker input URLs;
confirm clock synchronization and URL lifetime cover worker queue delay. STEM
source signing lasts eight hours and the worker receives presigned upload targets.

V2 STEM playback always uses the owned same-origin StemAudioStream; bounded/open/
suffix Range gives 206, unsatisfiable range 416, HEAD metadata, bounded streaming.
No bucket CORS is needed for that proxy. Other direct object-browser paths still
need their actual CORS/Range headers accepted; test only required app origins and
methods rather than broadening bucket access. GPU signed downloads/uploads are
server-to-server and independent of browser CORS. Verify S3-compatible Range and
reverse-proxy buffering/throughput. Existing private long-lived audio caching
cannot revoke bytes already delivered to a browser.

Keep STORAGE_ALLOW_DESTRUCTIVE_OPERATIONS=false. Do not validate readiness by
deleting customer files or enabling cleanup deletion. Inspect retention metadata,
quotas, owned reads and dry/read-only operational evidence; separately authorize
any physical cleanup policy. No destructive storage test was run against real data.
With this gate false, the scheduled API expiry command cannot physically delete
objects: StorageFileDeletionService checks the gate first. Access expiry still
applies. Plan capacity/monitoring for retained expired objects until an independently
approved cleanup policy exists; a scheduled command is not proof of reclamation.

## 7. Completion workers, scheduler and multi-node operations

Submission -> durable MlJob/provider ID -> scheduled ml-jobs:reconcile -> queued
ReconcileMlJob -> service synchronizer -> owned persisted output -> done -> Ready.
The browser is not required. Provider completion alone is insufficient. Poll
leases/due times coordinate callers; persistence retries do not regenerate output.
Ambiguous attempted dispatch with no provider ID requires recovery/review.

SOURCE VERIFIED schedules: ml-jobs:reconcile --limit=200 each minute; stale cleanup
30-minute queued/60-minute processing every ten minutes; service agreements each
minute; monthly refill daily 00:15. FIB reconciliation gate schedules cancellations
and payment checks every five minutes, subscriptions every ten. API expiry cleanup
every ten minutes. Stale cleanup excludes jobs with provider ID, charge reference
or submission-attempt timestamp; it is not a blanket paid/long-GPU timeout.

ReconcileMlJob timeout=240 seconds/tries=1. Database/Redis/Beanstalk retry_after
defaults to 10800 seconds, above the ML timeout and shared legacy YouTube's 7200.
Do not shorten below the longest job served; SQS visibility has its own operator
setting. Confirm actual queue connection/names, workers, timeout, stop grace and
capacity from process-manager configuration. Default database connection is not
proof any consumer is running. No Horizon dependency or invented manager unit.

Overlap locks exist. Shared database/Redis/memcached/DynamoDB cache enables
onOneServer; file/array cache does not coordinate a fleet. Nodes need common DB,
cache namespace, job leases and release configuration. Require observed schedule
execution plus queued/persisted completion with browser closed, crash/restart
recovery and no duplicate settlement across nodes. schedule:list alone proves
only registration. Do not run schedule:run as a read-only review command.

## 8. MySQL, cutover, billing and FIB

The read-only production preflight checks migration inventory, required schema,
active catalog actions/tools, optional epoch, production/debug profile, native
MySQL version, FIB profiles/HTTPS callback configuration and asynchronous queue.
This review corrects its stale nine-action list to derive all current catalog
actions. It does not certify endpoint health, pricing, Admin authority or RDS identity.

Production cutover's stronger policy separately requires APP_ENV=production,
mysql/native engine and server UUID, configured expected RDS host/schema/port,
actual schema/port, writable/read-only flags, FK enabled, no replica channels,
no split connection/socket/prefix, InnoDB tables, migration readiness and fresh
active Admin with all six capabilities. Triggers/cross-schema references block
unreviewed execution. Exact runtime privileges, engine facts, TLS, backup and
restore proof remain PRODUCTION UNVERIFIED. Local MariaDB is not equivalent.

The cutover is disabled by default through BILLING_CUTOVER_ENABLED. Production
also requires configured target/identity/Admin/backup/restore references, maintenance,
stopped writers (including callbacks/schedulers), provider disposition, fresh hash
and explicit operator approval. The current command is:

```sh
php artisan billing:cutover-reset-payment-domain --target=production --dry-run --admin="$ADMIN_ID"
# Only after final authorized review under maintenance/stopped writers:
php artisan billing:cutover-reset-payment-domain --target=production --execute --review-hash="$FINAL_REVIEW_HASH" --confirm="RESET-V2-PRODUCTION-BILLING-DOMAIN" --admin="$ADMIN_ID" --reason="$CUTOVER_REASON" --workers-stopped --backup-confirmed --restore-confirmed
```

These commands are documentation, not execution authorization. Local target and
RESET-V2-BILLING-DOMAIN are not production equivalents. An existing committed
epoch blocks another cutover. Whole-schema/row fingerprints include migrations,
new tools, prices and entitlements: any change invalidates the review hash. Apply
approved migration/catalog changes before final review; never reuse the old hash.
Valid paid coverage and unresolved remote obligations block rather than receiving
automatic disposition. Neither target sends provider requests. Preserve wallet,
ledger, allocation, job/file and historical evidence; current financial reads use
BillingReportingBoundary plus shared subscription authority.

FIB requires FIB_ENV=production, approved FIB_ENABLED, separate FIB_PAYMENT_CLIENT_ID/
SECRET and FIB_SUBSCRIPTION_CLIENT_ID/SECRET, reviewed payment/subscription base
URLs and PAYMENTS_FAKE_ENABLED=false. Inspect resolved profiles privately because
explicit/generic values can override environment-suffixed/legacy defaults.
FIB_CALLBACK_BASE_URL must be public HTTPS. POST /payments/webhooks/fib and
/payments/webhooks/fib/subscription are bounded/throttled notification routes;
authenticated provider GET evidence, not notification payload, proves payment.
Optional local secret-header filtering requires provider delivery support.

Recurring cancellation persists intent before HTTP, confirms via GET and retries
expired/superseded failures. New plan authority survives old cancellation failure;
no new collection timestamp means no new paid coverage. Reconciliation gate does
not disable agreement/refill writers. Do not poll the historical local mock Payment
176 or promote that synthetic receipt as production revenue.

Operator-approved real production acceptance must cover initial payment, duplicate
callback, renewal/new timestamp, expiry, cancel confirmation/recovery, upgrade/
downgrade, late collection review, one credit allocation and epoch-correct revenue.
No FIB requests or real transactions were made for this review.

## 9. Release artifact and ordered operator acceptance

1. Freeze/review one immutable revision containing this correction and separately
   reviewed concurrent work. Build a clean artifact; record revision and asset/image
   digests. Do not deploy this mutable workspace or include .env/logs/local storage.
2. Install locked production dependencies with composer install --no-dev --prefer-dist
   --optimize-autoloader, check platform requirements on the actual PHP/Linux runtime,
   and include npm/Vite production assets. Do not use composer setup (it migrates).
   Verify PHP extensions, audio probing/FFmpeg, OCR pdfinfo and worker mounts.
3. Privately review production config: APP_ENV/debug/key/URL/timezone, native MySQL
   TLS identity, queue/shared cache, private storage, five endpoint IDs and profiles.
   Keep App/API rollout and destructive storage gates disabled during preparation.
4. Confirm backup + tested restore, target migration status and reviewed pending data
   effects. Obtain the maintenance/stopped-writer/callback intake plan and actual
   process-manager commands. Apply only approved pending migrations; no generic seeders.
5. Audit all twelve active tools/actions, independent App/API runtime quotes,
   entitlements/denies, scopes and voice files. Review authoritative effective-plan
   reads with carried credits. Preserve Admin-customized economics.
6. Audit the named production Admin through the existing read-only command. Any
   capability grant is a separate explicit privilege decision, not automatic repair.
7. If this production database has no epoch and cutover is approved: resolve every
   provider obligation, establish backup/restore references, stop every writer,
   enter maintenance, obtain a fresh production dry-run and review its manifest.
   Execute only the production command above after final operator approval. If an
   epoch already exists, do not rerun; verify its evidence instead.
8. Check post-cutover preservation and epoch. Run production preflight with
   --production --require-epoch on the confirmed target. A pass is only a static gate.
9. Compile configuration, routes and views for the final environment; restart queue
   consumers under the actual process manager. Verify scheduler leadership/shared
   locks, effective timeouts/visibility, callback intake and all background writers.
10. Under approved smoke access, exercise all twelve products and persisted owned
    results. Test Zeta/Theta order/pauses/repeated references/failure/warm batches;
    Harakat UTF-8/TXT/max length; STEM seeks/ranges; OCR local PDF/pages/exports.
    Observe completion with browser closed and independent App/API accounting.
11. Verify API auth/scopes/action denies, idempotency/replay/mismatch, reservations,
    all nine generation routes, discovery, owned references and private downloads;
    temporary expiry/permanent quotas without destructive storage validation.
12. Verify EN/AR/KU, mobile/RTL, Back/Forward/locale changes, single queue poll owner,
    active/ready/failure acknowledgment and all twelve result links. Record the
    standalone plan badge/Subscribe CTA as absent unless separately implemented.
13. Perform separately approved FIB live payment/recurring/callback/recovery acceptance
    and financial preservation checks; do not infer these from mocked tests.
14. Enable App V2 only after its evidence is approved; enable API V2 separately after
    API acceptance. Refresh config/restart processes as required. Restore approved
    reconciliation, leave maintenance only with sign-off and monitor real completion.

Remaining release blockers are operational evidence: immutable final artifact;
native RDS migration/cutover preservation; actual catalog economics/access; private
storage/probes/range throughput; deployed worker versions, batch capacity and
reference files; running scheduler/queue fleet; public callbacks and live FIB
lifecycle; independent API rollout; interactive locale/mobile/navigation acceptance.
The absent plan badge/standalone CTA is an explicit product gap, not fabricated
as implemented or silently added during this review.

## 10. Verification record

The current broad regression command is `php artisan test --compact` with the
following explicit files (relative to tests/Feature); this is a selected suite,
not `php artisan test` over the whole repository:

```text
MetKurd/OmniSubmissionLifecycleTest.php
MetKurd/CloneV2WorkspaceTest.php
MetKurd/MultiSpeakerTest.php
MetKurd/LeoV2WorkspaceTest.php
MetKurd/CaptionV2WorkspaceTest.php
MetKurd/OcrV2WorkspaceStatusTest.php
MetKurd/OcrPersistenceSecurityTest.php
MetKurd/OcrDocumentProbeTest.php
MetKurd/HarakatTest.php
MetKurd/StemV2WorkspaceTest.php
MetKurd/StemAudioStreamTest.php
MetKurd/ProcessQueueTest.php
MetKurd/V2CoreReviewTest.php
MetKurd/V2LocalizationTest.php
MetKurd/JobReconciliationTest.php
MetKurd/DurableUploadSubmissionTest.php
Storage/V2StorageSafetyAndLibraryTest.php
Storage/StoragePersistenceTest.php
Storage/StorageNavigationAndHistoryTest.php
Api/ApiV2Test.php
Account/V2AccountPagesTest.php
Account/V2PurchasePagesTest.php
Account/V2PaymentCheckoutTest.php
Billing/PlanConcurrencyTest.php
Billing/BillingEpochTest.php
Billing/CutoverTargetsTest.php
Billing/PaymentDomainCutoverTest.php
Billing/RecurringActionLifecycleTest.php
Admin/AdminP1CorrectnessTest.php
Admin/AdminP2OperationsTest.php
Admin/AdminP0SafetyTest.php
Support/MlJobsMarkStaleFailedCommandTest.php
```

Explicit process overrides: APP_ENV=testing, DB_CONNECTION=sqlite,
DB_DATABASE=:memory:, DB_URL empty, CACHE_STORE=array, SESSION_DRIVER=array,
QUEUE_CONNECTION=sync, MAIL_MAILER=array. No cached application configuration
existed when the suite was started. Fake storage/mocked provider boundaries are
used by the selected fixtures; no production-derived database is a test target.

LOCAL VERIFIED frontend command: `node --test tests/Frontend/*.test.mjs` with
PHP_BINARY set to the local PHP executable: **65 passed, 0 failed, 0 skipped**.
`npm run build`: **passed**, 18 modules, app manifest and production assets emitted.
This run preceded concurrent maintenance tests added to the directory.

New `Support/ProductionPreflightCatalogTest.php` first failed as expected against
the old command (1 failure/4 assertions): its required-action set omitted all
three new products. After the minimal command correction:

| Check | Result |
|---|---|
| Selected 32-file PHP regression before the correction | **681 passed, 19,383 assertions**, 0 failures, 1261.20 seconds |
| ProductionPreflightCatalogTest + BillingEpochTest after the correction | **11 passed, 120 assertions**, 0 failures; ten cases overlap the broad suite |
| Distinct PHP cases across these successful runs | **682**; only the affected preflight/epoch checks were rerun after the correction |
| Frontend tests before concurrent maintenance additions | **65 passed**, 0 failed/skipped |
| npm run build | **Passed** |
| PHP syntax and focused Pint for the command/new test | **Passed**, both files |
| Scoped git diff --check | **Passed** |

The deliberate red test above is resolved; there are no remaining failures in
the selected checks. The historical Sep15 V1 FibPaymentFlowTest English-versus-
Arabic assertion was not rerun and is not counted as a current failure/pass.
No full repository PHP suite or broad composer lint was run. Current config/route/
view cache compilation and production Composer platform acceptance were not run;
the runbook's previous isolated compilation evidence is historical. Recompile
all three caches and verify the actual platform for the frozen deployment artifact.
No claim is made for a full repository test run, native MySQL, real GPU inference,
live provider/storage, interactive browser, or production process execution.
During this review, concurrent maintenance-page work appeared in bootstrap/app.php,
public/index.php, MaintenanceResponse, the 503 view/translations/tests and related
documentation. It was left untouched and is not certified by this audit. The
pre-existing deleted storage/framework/lsp-7b413ec377de3edb.php was also left untouched.
A final immutable release must incorporate separately reviewed work and repeat
its relevant checks; this running workspace is not a frozen release artifact.

## Appendix: complete repository migration filenames

The following inventory is generated from database/migrations for this review.

```text
0001_01_01_000000_create_users_table.php
0001_01_01_000001_create_cache_table.php
0001_01_01_000002_create_jobs_table.php
2026_03_01_161532_create_customers_table.php
2026_03_01_161554_create_customer_profiles_table.php
2026_03_04_174918_create_profiles_table.php
2026_03_05_103616_create_customer_usages_table.php
2026_03_05_103640_create_service_plans_table.php
2026_03_05_103659_create_storage_plans_table.php
2026_03_05_103700_create_customer_payment_methods_table.php
2026_03_05_103733_create_customer_service_subscriptions_table.php
2026_03_05_103826_create_customer_storage_subscriptions_table.php
2026_03_05_103842_create_tools_table.php
2026_03_05_103857_create_tool_actions_table.php
2026_03_05_103928_create_customer_entitlements_table.php
2026_03_05_104015_create_customer_pricing_rules_table.php
2026_03_05_104033_create_voices_table.php
2026_03_05_104054_create_plan_voice_accesses_table.php
2026_03_05_104126_create_customer_voices_table.php
2026_03_05_104141_create_credit_wallets_table.php
2026_03_05_104203_create_credit_ledgers_table.php
2026_03_05_104259_create_credit_monthly_grants_table.php
2026_03_05_104320_create_credit_products_table.php
2026_03_05_104321_create_payment_intents_table.php
2026_03_05_104322_create_payment_transactions_table.php
2026_03_05_104323_create_payment_webhook_events_table.php
2026_03_05_104324_create_payment_methods_table.php
2026_03_05_104327_create_credit_orders_table.php
2026_03_05_104347_create_customer_files_table.php
2026_03_05_104429_create_ml_jobs_table.php
2026_03_05_104937_create_plan_entitlements_table.php
2026_03_07_140700_create_pricing_rules_table.php
2026_03_07_141000_create_usage_events_table.php
2026_03_28_120000_create_registration_phone_countries_table.php
2026_03_30_090000_create_currencies_table.php
2026_03_30_090100_create_currency_exchange_rates_table.php
2026_03_30_090200_create_country_currency_maps_table.php
2026_04_14_100000_create_landing_tool_pages_table.php
2026_04_14_100100_create_landing_settings_table.php
2026_04_14_100200_create_landing_social_links_table.php
2026_04_14_100300_create_site_meta_settings_table.php
2026_04_14_100400_add_square_image_to_landing_tool_pages_table.php
2026_04_16_100000_create_payments_table.php
2026_04_16_100100_create_payment_events_table.php
2026_04_16_100200_add_payment_id_to_billing_tables.php
2026_04_16_120000_update_fib_fee_config_for_pass_through_pricing.php
2026_04_18_100000_add_concurrent_jobs_limit_to_service_plans_table.php
2026_04_18_112033_create_personal_access_tokens_table.php
2026_04_19_090000_add_fib_subscription_fields_to_payments_and_payment_events.php
2026_04_22_120000_create_coupons_table.php
2026_04_22_120100_create_coupon_redemptions_table.php
2026_04_22_120200_add_coupon_fields_to_billing_tables.php
2026_04_23_000100_add_supported_payment_methods_to_coupons_table.php
2026_04_23_120000_normalize_landing_media_paths_for_shared_disk.php
2026_04_26_000001_add_telegram_register_notification_columns_to_customers_table.php
2026_04_30_120000_add_payment_mode_to_service_and_storage_plans.php
2026_04_30_140000_add_billing_intervals_to_service_and_storage_plans.php
2026_05_08_120000_create_ad_conversion_events_table.php
2026_05_10_120500_add_demo_fields_to_landing_tool_pages_table.php
2026_06_10_140000_add_application_review_fields_to_payments_table.php
2026_06_14_100000_add_public_api_fields_to_service_plans_table.php
2026_06_14_100100_add_public_api_fields_to_customer_files_table.php
2026_06_14_100200_create_public_customer_api_tables.php
2026_06_15_000000_split_credit_wallets_and_add_api_credit_allowances.php
2026_06_15_120000_add_channels_to_pricing_and_entitlements.php
2026_08_12_000000_add_submission_lifecycle_to_ml_jobs_table.php
2026_08_15_000000_register_xomni_v2_tool.php
2026_08_15_000100_register_vector_v2_tool.php
2026_08_16_000100_register_leo_v2_tool.php
2026_08_16_000200_register_caption_v2_access.php
2026_09_05_000001_add_poll_coordination_to_ml_jobs.php
2026_09_06_000001_add_v2_idempotency_to_api_jobs.php
2026_09_06_000002_add_admin_operation_safety.php
2026_09_07_000001_normalize_v2_launch_pricing.php
2026_09_09_000001_create_subscription_credit_allocations_table.php
2026_09_13_000001_create_service_plan_agreements_table.php
2026_09_20_000001_register_multi_speaker_tools.php
2026_09_21_000001_register_harakat_tool.php
```
