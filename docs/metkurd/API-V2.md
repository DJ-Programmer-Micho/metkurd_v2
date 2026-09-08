# MetKurd API V2 engineering contract

## Admin P2 visibility — 2026-09-06

The Admin Operations/customer detail view shows effective plan scopes and per-action
customer access, RPM/concurrency, active claims, metadata-only keys, ApiJob/MlJob
links, separate API wallet balances, reservations and result-retention metadata.
Reserved, settled and released amounts remain distinct; available wallet credits
already exclude holds. These are pure persisted reads: they deliberately do not
invoke API status serializers that can settle/release a reservation. No API gate,
key provisioning, secret display or reservation mutation was added. See ADMIN-AUDIT.md.

Implemented in the 2026-09-06 API phase. This supersedes the earlier API deferral.
Repository evidence is not live deployment acceptance. No secrets belong here.

## Admin P1 scope configuration — 2026-09-06

ApiCatalog exposes web variants through its existing runtime definition and the
same configured-scope expansion used for customer access. Admin derives six family
scopes from complete API/all entitlement state, preserving sibling models/modes.
`service_plans.meta.admin_api_scopes` records explicit/derived ownership;
`api_allowed_tools` remains their compatible union. Before ownership exists, every
stored scope is explicit. The Plan editor edits explicit grants; only tracked
derived grants are removed automatically. Entitlement moves update both plans in
one transaction. V1 parsing/aliases/endpoints remain; no gate or scope backfill runs
just by deploying or reading. The read panel reuses runtime plan configuration,
scope and action checks and states remaining customer/key/credit/capacity checks.
No secret keys or reservations are shown. See [Admin P1 policy](ADMIN-AUDIT.md).

## Activation runbook

The portal message **"API V2 is not enabled in this environment yet."** means
`config('customer_api.v2_enabled')` is false. It is an environment feature gate,
not a restriction against localhost. `ApiV2Boundary` returns HTTP 404 with
`invalid_request` while disabled, before bearer authentication.

| Setting | Controls |
|---|---|
| `FEATURE_API_V2` | Machine endpoints under `/api/v2`; defaults false. |
| `FEATURE_APP_V2` | Localized web pages, including `/{locale}/app-v2/api`; defaults false. Independent of the machine API gate. |

These are configuration examples for an operator to apply to the intended
environment; documenting activation does not activate this checkout.

1. Deploy the API V2 code and inspect migration status with
   `php artisan migrate:status`. The existing application/API schema must already
   be present. Before accepting V2 requests, apply these additive migrations if
   pending through the environment's normal migration procedure:

   ```sh
   php artisan migrate --path=database/migrations/2026_09_05_000001_add_poll_coordination_to_ml_jobs.php --force
   php artisan migrate --path=database/migrations/2026_09_06_000001_add_v2_idempotency_to_api_jobs.php --force
   ```

   The first supports durable reconciliation; the second adds the customer-wide
   API idempotency constraint. `--force` is for an intentional production
   deployment. Do not run production migrations as a test. Do not rerun seeders
   to activate the API. The poll migration was applied during local troubleshooting;
   recheck each deployment's status rather than assuming that applies elsewhere.

2. Prepare the existing dependencies: customer plan API access and V2 scopes,
   active tools/actions, API-channel entitlements/prices and API wallet balance;
   configured native processing endpoints; reachable private object storage;
   ffprobe and Poppler pdfinfo in the application/worker runtime. Use
   `OCR_PDFINFO_BINARY` for an explicit executable location if necessary.
   App credits alone do not establish API credits or permission.

3. Set the machine gate in the target environment's `.env` or deployment variables:

   ```dotenv
   FEATURE_API_V2=true
   ```

   To expose the V2 developer portal as well, set `FEATURE_APP_V2=true`.
   Configure `APP_URL` to the base URL clients should use. A process-level
   environment override can take precedence over `.env`.

4. Refresh configuration. For local development, run `php artisan config:clear`
   and restart `php artisan serve` if it retains old environment values. For a
   production deployment, rebuild with `php artisan config:cache`, refresh the
   route cache with `php artisan route:cache` when deploying these routes, and
   reload long-lived application processes using the deployment's normal process
   manager. Run `php artisan queue:restart` for existing queue workers and ensure
   their process manager starts replacements. Keep the existing scheduler running
   every minute and the reconciliation queue serviced: API GETs do not poll GPUs.

5. Refresh `/{locale}/app-v2/api`; the disabled notice should disappear. A simple
   read-only gate/auth check, using the actual environment base URL, is:

   ```sh
   curl -i -H "Accept: application/json" http://127.0.0.1:8000/api/v2/services
   ```

   Without a key, expect 401 `authentication_failed` once enabled, rather than
   the gate's 404 `invalid_request` (assuming the request is not rate-limited).
   Use `curl.exe` in Windows PowerShell if `curl` resolves to its web-request
   alias. For a deployed server, replace the localhost origin with its HTTPS
   origin. Verify routes with `php artisan route:list --path=api/v2` if needed.

6. Create a V2 key in the portal for an API-enabled customer plan. Use the key
   privately as `Authorization: Bearer YOUR_API_KEY` on `GET /api/v2/services`;
   successful access returns 200. This read does not start a job or charge credits.
   Then use the portal's service examples when ready for a paid acceptance job;
   every POST needs an `Idempotency-Key`. Do not paste real keys into docs or logs.

Local clients can use `http://127.0.0.1:8000/api/v2`. Other machines cannot reach
this host through their own localhost address. Local full processing still needs
the configured processing API, reachable HTTPS object-storage inputs/outputs,
the correct schema, account permissions and reconciliation. The API does not
require production hosting merely to enable its routes.

If the notice remains, check the loaded feature flag, process-level environment,
configuration cache and the app instance serving that URL. A 403 after activation
indicates a separate access/scopes issue; insufficient credits is separate from
the gate too. To disable new API access, set `FEATURE_API_V2=false` and refresh
configuration/processes again. This does not cancel accepted jobs or stop their
reconciliation; disabling the web gate is not necessary to disable the API.

## Routes and product boundary

Machine endpoints have no locale prefix. All require a customer bearer key.

| Method | Route | Native service |
|---|---|---|
| POST | `/api/v2/speech` | Apollo 1.5 / 2.0, `OmniSubmissionService` |
| POST | `/api/v2/voice-clone` | Vector 1.5 / 2.0, `CloneOmniSubmissionService` |
| POST | `/api/v2/transcriptions` | Leo, `LeoSubmissionService` |
| POST | `/api/v2/captions` | Caption, `CaptionSubmissionService` |
| POST | `/api/v2/ocr` | OCR, `OcrV2SubmissionService` |
| POST | `/api/v2/stem` | STEM 2 / 4, `StemV2SubmissionService` |
| GET | `/api/v2/jobs/{id}` | Owned persisted job and result |
| GET | `/api/v2/files/{id}/download` | Owned private result download |
| GET | `/api/v2/voices` | Allowed Apollo voice identifiers/names |
| GET | `/api/v2/services` | Supported public services, speech models, stem modes |

No Translation, Neo, Apollo 1.0 or coming-soon services. Public `model` values
1.5/2.0 map to existing Apollo/Vector catalog variants, default 2.0. STEM `mode`
2/4 maps to `stem.sep2`/`stem.sep4`. Clients never select internal actions,
provider endpoints, object keys, worker URLs or pricing metadata.

## Existing API assessment

| Classification | Existing pieces | V2 decision |
|---|---|---|
| A — secure shared infrastructure | CustomerApiKey/CustomerApiKeyService, bearer middleware, customer plan rate configuration, API wallet reservations/ledger, ApiJob/ApiResultFile, owned file metadata and expiry cleanup | Reuse. Add V2 scopes/issuance, consistent error boundary, public serializers and customer idempotency constraint. |
| A — local settlement | CustomerApiJobSyncService::syncFromMlJob and CustomerApiCreditReservationService | Reuse idempotent API settlement/release from persisted MlJob. |
| B — legacy API business logic | V1 controllers and CustomerApi submission services selecting legacy actions, estimates and provider payloads | Keep for V1 migration/history; never call from V2 submissions. |
| C — obsolete coupling for V2 | Old customer API page, V1-only scope catalog as V2 product discovery, CustomerApiJobSyncService::refresh GPU polling | V2 portal replaces the page; ApiCatalog owns V2 public scope mapping; V2 GET never invokes refresh. |

`/api/v1/*` and mobile routes remain unchanged. `/{locale}/app/api` redirects to
`/{locale}/app-v2/api` when FEATURE_APP_V2 is enabled; the legacy page remains
available when that gate is off. V2 shell links point directly to its portal.

## Keys, access and request limits

`Authorization: Bearer YOUR_API_KEY` uses the existing SHA-256 key lookup.
Only a hash/prefix is stored. Keys belong to one customer and are active/revoked;
usage updates the existing last-used fields. The V2 portal shows prefix, name,
creation, last use and status. Creation locks the customer before enforcing
CUSTOMER_API_MAX_KEYS (default 5). Revocation always rechecks customer ownership.
The secret is returned once in a Livewire event, held only in client memory, and
cleared on dismiss/navigation. Static examples never receive the user's secret.

New keys receive explicit `v2:{service}`, `v2:jobs:read`, `v2:files:download`
scopes permitted by the current plan. Plan service rules accept `*`, `v2:*`, an
explicit V2 service or an existing family wildcard (`tts:*`, `asr:*`, etc.). Old
exact V1 tool scopes do not silently grant V2 access. Operators must configure
V2 plan scopes plus active tools/actions, API-channel entitlements and pricing before rollout.
Service submission checks both scope and action entitlement. Current plan access
is checked on reads too; revocation or loss of API access takes effect immediately.

The existing plan request/minute bucket covers submission, status and downloads
and is shared with V1. Concurrent accepted/queued/processing API jobs are bounded
under the customer lock by the plan limit. A separate failure-only IP bucket
defaults to 60 failed authentications/minute and is configurable with
CUSTOMER_API_V2_AUTH_FAILURES_PER_MINUTE. Use a shared production cache and correct
trusted proxy configuration. Rate errors return 429 with Retry-After.

## Trusted inputs

Speech accepts JSON. File services use one multipart `file`; do not manually set
the multipart boundary. PHP/Python/Node examples use their standard multipart
builders. API input allowlists discard supplied duration, minutes, page counts,
paths and unrelated metadata. Uploaded content is stored in customer-owned
native namespaces before dispatch. There is no arbitrary URL upload mechanism.

`InputBoundary` is shared with web V2 for trimmed text/character limits, allowed
Apollo voice lookup, audio upload validation/probing, reference checks and OCR
preparation. Web editor, FilePond, counters and waveform state stay in Livewire.
Model and STEM-mode mapping is the API facade's allowlisted responsibility.

| Input | Enforced contract |
|---|---|
| Apollo/Vector text | Required, trimmed, 400-character current fallback limit. Character pricing derives from accepted text; voice lookup is customer/plan-scoped. |
| Speech model | 1.5 or 2.0, default 2.0. |
| Language | `ckb`, `ar`, `en`, default `ckb`; applies to speech/clone/Leo/Caption. UI Kurdish locale is `ku`. |
| Intelligent | Boolean, default false; applied to Leo/Caption/OCR. Multipart examples use 0/1. |
| Audio | Maximum 100 MiB, supported audio MIME, successful server AudioProbeService, positive finite duration. Minutes = ceil(seconds / 60), never client-provided. No new plan duration limit invented. |
| Vector reference | Exactly one uploaded file or `reference_id`. Maximum 20 MiB, supported reference audio MIME; FLAC is excluded by the native clone contract. Existing reference must be active, owned, unexpired, present in storage and reprobed from a bounded temporary local copy. Native worker uses at most the first 20 seconds. Optional `reference_text` accepts up to 4000 characters. |
| OCR | PDF/JPEG/PNG/WebP/BMP/GIF/TIFF up to 100 MiB. Server pdfinfo verifies PDF count (1–3888 pages); server validates/expands selected `pages` (`all` or e.g. `1-3,5`). Native service rechecks before charge. `exports`: txt/docx/markdown/html/zip; defaults txt/docx. |
| Caption | Existing fixed defaults: SRT plus segments, 8 words, maximum 6 seconds, minimum 1 second. No new worker option surface. |
| STEM | Required `mode` 2 or 4; probe duration and shared pricing context; existing payload builder and owned track/ZIP persistence. |

Infrastructure body/time limits may be lower than application limits. ffprobe and
OCR_PDFINFO_BINARY must be installed in both the applicable web/worker runtimes.

## Idempotency, accounting and recovery

Every paid POST requires `Idempotency-Key` (1–128 characters). The raw key is not
stored. A unique `(customer_id, idempotency_hash)` constraint makes an identity
customer-wide, including across keys/endpoints. The input fingerprint includes
service, validated fields and actual file SHA-256. Reuse the same field values
and file bytes for retries; semantically equivalent differently encoded payloads
are not promised to normalize identically. Changed content returns 409.

After validation/probing, ApiSubmission locks the customer, checks concurrent jobs
and creates the claim. Repeats return local state before another probe, storage
upload, debit or dispatch. Core SubmissionContext creates the API reservation
and attaches MlJob inside the same transaction as native job creation. The native
submission identity derives from ApiJob ID. API entitlement/pricing channels are
explicit; app credits/refunds remain separate.

The HTTP request waits for input preparation and dispatch acknowledgement only.
It never waits for GPU completion. Accepted responses use 202; a repeated accepted
identity uses 200. Existing `ml-jobs:reconcile`/ReconcileMlJob and synchronizers
finish jobs without a connected client. GET jobs does no provider polling, though
it may idempotently settle/release reservations using local terminal state.

Known failures release only the API reservation, once. Timeouts, missing remote
IDs and ambiguous dispatch outcomes remain reserved with a safe public processing
status; operators must inspect the existing internal review markers. No blind
provider replay or speculative refund is introduced. An unlinked claim older
than 15 minutes is marked failed by the existing reconciler. Attaching/charging
requires an accepted claim, preventing delayed requests from charging after that
correction. Linked native jobs retain the existing durable recovery policy.

## Responses, private files and retention

All jobs return `id`, `status`, `service`, `created_at`, `completed_at`,
`expires_at`, `result`. Status is queued/processing/completed/failed/cancelled.
Failed jobs include a safe error code/message; no provider state names are public.
Completed `result.files` entries contain id, kind, mime_type, size_bytes and an
authenticated `/api/v2/files/{id}/download` URL. Additional service fields:

- Apollo/Vector: owned audio in files.
- Leo: text and persisted transcript file.
- Caption: text, SRT, whitelisted start/end/text segments and persisted files.
- OCR: persisted inline text and requested exports.
- STEM: persisted tracks and ZIP when generated by the existing core.

The serializer allowlists public values; it never returns raw MlJob output,
provider metadata, storage paths or signed worker input URLs. Every download
checks key scope, customer, V2 API job, file ownership, active state, expiry and
object existence. Responses use private/no-store cache headers.

`storage_mode` defaults to temporary; permanent is explicit. Temporary API inputs
and results inherit the existing TTL, CUSTOMER_API_TEMP_FILE_TTL_DAYS (default 7)
from submission time and do not count toward permanent quota. Permanent API files
count toward the existing quota. Previously saved references preserve their own
policy. Expiry hides API results immediately (`expired: true`, empty files), even
if cleanup has not run. The existing cleanup command and deletion safeguards
control physical removal; this phase does not enable destructive operations.
Web permanent storage semantics remain unchanged.

Errors use `{"error":{"code":"invalid_request","message":"Check the supplied fields."}}`.
Validation may add a list of field names. Codes: invalid_request (400/422),
authentication_failed (401), permission_denied (403), job_not_found/file_not_found
(404), idempotency_conflict (409), invalid_file/unsupported_format/insufficient_credits
(422), rate_limit_exceeded/concurrency_limit_exceeded (429), server_error (500).
Failed job results use processing_failed or storage_limit_exceeded as applicable.
Unexpected exception text, stack traces, class names and provider bodies are not
returned. Error normalization also covers framework-rendered API exceptions.

## Portal, verification and rollout

The localized V2 portal has overview, authentication, keys, quickstart, seven
service sections, jobs, errors, idempotency, limits and retention. Desktop uses
navigation/content/code columns; small screens use a section select and stacked
panels. cURL/PHP/Python/JavaScript examples are generated from ApiDocumentation;
these are snippets, not official SDKs. Technical blocks stay LTR, customer names
use dir=auto, and all explanatory/key UI copy is in EN/AR/KU catalogs. Revoke uses
the V2 SweetAlert bridge. Copy uses the clipboard with localized fallback.

Focused evidence: 30 API V2 tests, 107 shared-core/dashboard tests and 15 legacy
checks passed; one previously documented legacy allowance fixture still fails.
Full details are recorded in the current API phase section of PRODUCTION-AUDIT.
Tests use isolated in-memory SQLite, array cache/session, fake storage and mocked
provider/probe calls. This proves local behavior, not live GPU acceptance.

Before deployment: apply the idempotency and poll-coordination migrations; verify
API plan scopes, API-channel prices/entitlements and balances; check shared cache,
trusted proxies, scheduler/queue operation, probes, upload limits, private object
access and expiry cleanup policy. FEATURE_API_V2 and FEATURE_APP_V2 default false.
Neither gate was enabled in a deployment. Live worker results, real storage/queue
operation and browser responsive/RTL acceptance remain deployment checks. Do not
modernize billing or delete V1 endpoints as part of this rollout.
