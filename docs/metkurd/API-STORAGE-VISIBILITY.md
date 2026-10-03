# API result persistence and Storage visibility — 2026-10-03

Source audit and isolated tests only. The operator's production job was not queried.

## Behavior matrix established before the correction

| Category | CustomerFile / ApiResultFile | Permanent quota | Expiry | App Storage before → after | API history / retrieval | Delete / cleanup |
|---|---|---|---|---|---|---|
| Web permanent result | Yes / no | Yes | None normally | Visible → unchanged | Web job history; no API result identity | Existing owned deletion |
| API permanent result | Yes / yes | Yes | None | Visible → visible with API/Permanent labels | ApiJob and authenticated result download | Existing owned deletion |
| API temporary result | Yes / yes | No | Submission + configured API TTL (default 7 days) | Hidden → visible until expiry with API/Temporary labels | ApiJob and authenticated result download until expiry | Existing owned deletion and `api:cleanup-expired-files` |
| API temporary input/upload | Yes for persisted job inputs / no result link | No | Same job TTL | Hidden → hidden | Source of owned job, not an output download | Existing expiry cleanup; ephemeral upload staging has its own cleanup |
| Saved/reusable reference | Yes / no result link | Yes | Existing reference policy; normally permanent | Visible → unchanged | Reference ID, independent of an individual output | Existing owned reference deletion |
| Expired API result | Metadata retained; links marked deleted during cleanup | Temporary: no | Already expired | Hidden → hidden | Job history remains, expired result unavailable | Existing expiry cleanup; no TTL extension |

The API developer portal is documentation/key management, not a separate file
library. API job/status and existing customer job/history surfaces retain their
own authorization. This correction adds no second history or storage subsystem.

## Root cause and trace

`ApiCatalog::definition('speech', ['model' => '2.0'])` selects
`text-to-speech / apollo-2 / xomni-v2.generate`. Omni dispatch uses `model_2`.
`ApiSubmission` creates one idempotent owned ApiJob; `SubmissionContext::charge`
binds one owned MlJob and the API reservation, with API retention in trusted job
input. `ReconcileMlJob` and `XttsJobSyncService` save decoded audio through
`CustomerOutputStorage` to private object storage before marking the job done.
One CustomerFile records MIME, size, customer, `source_type=api_job`, API source ID,
`meta.job_id` pointing to the MlJob, retention, expiry and quota inclusion.

`CustomerApiJobSyncService` links the primary result and all artifacts. Existing
compatibility behavior can create both a `primary` and an `artifact` ApiResultFile
for the same CustomerFile; these are aliases, not duplicate stored objects.
`ApiJobResult` emits one file per CustomerFile, preferring its artifact ID. This
audit does not rewrite existing API IDs. The V2 file endpoint checks bearer
authorization, both owners, deletion, expiry and object existence.

The exclusion was explicit in V2 Storage's `storageFiles()` and `files()` queries
and in `CustomerStorageLibrary::navigation`: non-temporary retention only.
It was an implementation of the old separation wording, now a discoverability
gap against the requested unified owned-result library. Quota exclusion was not
the cause; product mapping for Apollo 2.0 was already correct.

## Correction boundaries

One shared V2 library query admits active, undeleted, unexpired files: existing
permanent files plus API temporary results (`render`, `transcription`, `caption`).
Temporary inputs/references/staging stay hidden. No ApiResultFile join is needed
to list an already-persisted owned result. Navigation, counts, listing, selection
and App download paths use the same visibility rule. Quota still comes from
CustomerUsage and `counts_toward_quota`, never displayed file sizes.

App preview URLs cannot be issued after expiry and their lifetime is capped at
the file's expiry. Bulk download rejects expired selections. API file metadata
includes nullable per-file `expires_at` in addition to existing job expiry,
safe ID, kind, MIME, size and authenticated route. No object/provider path is added.

## Native service audit

Both API storage modes use the same native persistence paths:

| Products | Persistence / result roles | Storage product |
|---|---|---|
| Apollo 1.5 / 2.0 | XttsJobSyncService final audio, `render` | apollo-1 / apollo-2 |
| Vector 1.5 / 2.0 | XttsJobSyncService final audio; references separate | vector-1 / vector-2 |
| Zeta / Theta | XttsJobSyncService one assembled audio; Theta saved references separate | zeta-1 / theta-1 |
| Leo | ASR sync, transcription TXT and SRT when produced | leo |
| Caption | QasrJobSyncService transcription TXT + `srt` | caption |
| OCR Scanner | registerOcrArtifacts: text, json, docx, markdown, html, zip when produced | ocr |
| Harakat | HarakatJobSyncService TXT, `diacritized_text` | harakat-1 |
| STEM 2 / 4 | registerStemArtifacts: original, result_json and every returned stem (vocals/instrumental or vocals/drums/bass/other) | stem |

STEM's returned original is an existing registered output role, distinct from
the uploaded `input_audio`. No role is flattened or reclassified. Harakat's
existing identity was missing from the Storage filter map; both its product
filter and the OCR family filter now include it.

## Acceptance boundaries

No production SQL, provider request, migration, TTL/config/billing change or
deployment is authorized by this review. Operator acceptance must verify one
owned temporary and permanent result against the deployed private storage,
EN/AR/KU mobile/desktop labels, expiry scheduling and multi-node signed downloads.

Storage retains its existing in-memory folder grouping/pagination; this change
does not claim database-level pagination or new query-plan acceptance. The added
metadata presentation introduces no per-file API job lookup.

## Changed files

- `app/Support/CustomerStorageLibrary.php`: shared visibility and retention presentation.
- `resources/views/app/v2/pages/storage/⚡app-storage.blade.php`: shared queries,
  counts, labels and the missing Harakat filters.
- `resources/views/app/v2/components/storage-retention.blade.php` and
  `resources/lang/app/{en,ar,ku}.json`: retention/expiry copy and LTR date isolation.
- `app/Http/Controllers/App/V2StorageFileController.php` and
  `app/Services/Storage/CustomerStorageBulkDownloadService.php`: matching owned download eligibility.
- `app/Services/Storage/CustomerOutputStorage.php`: preview expiry check and signature cap only;
  persistence, TTL and quota calculations are unchanged.
- `app/Http/Controllers/Api/Customer/V2/ApiController.php`,
  `app/Services/CustomerApi/CustomerApiFileLinkService.php` and
  `app/Services/CustomerApi/V2/ApiJobResult.php`: reject expiry at the exact deadline
  and deleted metadata; expose nullable per-file expiry.
- `tests/Feature/Api/ApiV2Test.php`, `tests/Feature/Storage/ApiResultLibraryTest.php`:
  mocked native Apollo flow in both modes and shared persistence/library boundaries.
- This audit, `API-V2.md`, `STORAGE-AND-CACHE.md`, `CHANGELOG.md`: final visibility contract.

## Verification results

- Storage directory + ApiV2Test + V2LocalizationTest: **168 passed, 14,121 assertions**.
- Existing Clone, Leo, Caption, STEM workspace, OCR persistence/security,
  MultiSpeaker and Harakat tests: **85 passed, 739 assertions**.
- MCP persisted-resource/download and read-only job/resource regressions:
  **5 passed, 59 assertions**.
- Combined final PHP coverage: **258 passed, 14,919 assertions**; SQLite memory,
  fake object storage and mocked providers. Shared-boundary fixtures cover all
  twelve native variants in both retention modes; Apollo tests additionally
  exercise submission, reconciliation, API download and the Storage UI end to end.
- All frontend Node tests: **104 passed**. Vite production build passed.
- Scoped Pint (nine PHP files), PHP/Blade syntax checks and `git diff --check` passed.
- The initial run had two locale-fixture failures because direct Livewire tests
  omitted the customer translation middleware. Final tests use the localized
  HTTP Storage route and check EN/LTR and AR/KU/RTL. No application translation
  loader was changed to accommodate tests.
- No live GPU, live storage, production database, or interactive browser acceptance
  was performed. Existing roles, billing separation, deletion gates and expiry
  cleanup are covered locally; infrastructure acceptance remains separate.
