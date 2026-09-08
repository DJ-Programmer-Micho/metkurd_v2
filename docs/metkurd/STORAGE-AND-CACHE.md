# Storage, cache and history

## Admin dashboard cache boundary — 2026-09-07

Customer population is a single uncached aggregate within the request-computed
Admin overview. It stays authoritative after model changes and same-database bulk
imports. Other dashboard analytics keep their five-minute cache. Versioned keys
include an opaque application/environment/effective-database identity, without
exposing names or credentials. Switching the environment file alone does not
replace Laravel's cached configuration: refresh configuration as part of the
confirmed local import/activation sequence. See ADMIN-AUDIT.md for the diagnosed
configuration mismatch, exact metric semantics and verification limits.

## Admin P2 file metadata — 2026-09-06

The read-only Operations trace lists CustomerFile and ApiResultFile metadata,
retention/expiry, quota inclusion and record availability. It does not probe
objects, generate signed links, display object keys or retrieve customer content.
Object presence is labeled not checked; metadata is not proof of physical access.
No cache, cleanup or destructive-storage policy is changed by this read surface.

## API V2 storage contract — 2026-09-06

Native input/output writes carrying an owned API V2 MlJob now inherit its API
retention metadata centrally in CustomerOutputStorage. Default temporary files
expire CUSTOMER_API_TEMP_FILE_TTL_DAYS days after submission (default 7), are
excluded from permanent quota, and use the existing expiry cleanup command.
Explicit permanent files count toward quota. Existing saved Vector references
keep their original retention; new API uploads inherit the API job policy.
No arbitrary storage key or remote input URL is accepted. Result metadata uses
ApiResultFile identifiers and private bearer-authenticated download endpoints;
paths, storage credentials and worker output URLs stay internal. Expired files
are unavailable even before physical cleanup. See [API-V2.md](API-V2.md).


## Previous core-review scope — 2026-09-06

V2 Storage and results for all seven V2 services are core release requirements.
Shared object/path readers can remain for migration and existing customer data;
the existence of V1 files is not a requirement to modernize V1 workspaces.
Future API clients should reuse V2 ownership/persistence services. Old API changes
and broad billing changes are deferred; quota and deletion safety remain intact.


Source baseline: 2026-09-05. `CustomerOutputStorage` and callers define actual
behavior; neither a GPU response nor a cache entry is the permanent customer file.

## Temporary data versus customer results

| Kind | Current behavior |
|---|---|
| Livewire temporary uploads | Configurable disk (`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK`, then `LIVEWIRE_TEMP_DISK`); max 100 MB and 15 minute upload time defaults; cleanup enabled for temporary uploads. These are staging data, not history. |
| Uploaded job sources | Stored under application render paths, registered as CustomerFile and normally count toward quota. Audio/documents often remain for customer preview and are removed by service-specific deletion. Being an input does **not** automatically make a file temporary. |
| Clone reference audio | Reusable customer-owned reference CustomerFile, independent of individual render deletion. Shared across clone tool versions for that customer. |
| Web results | Private S3-compatible objects plus MlJob output / CustomerFile metadata; normal permanent retention or null historical retention, not a provider result URL. |
| Public API temporary results | `api_storage_mode`, `api_expires_at`, ApiResultFile/CustomerFile retention metadata; excluded from normal quota where configured by the API path and cleaned by API expiry command. Keep separate from permanent web files. |
| ZIP downloads | Bulk storage archive uses a per-request local temp directory cleaned after streaming. STEM has a separate local ZIP cache. These are derivative downloads, not canonical results. |

## Paths, metadata and ownership

Standard sources/results use `renders/{customer-folder}/{tool}/{job-id}/...`.
`CustomerFolder::make` uses numeric customer ID plus profile/name or username
slug. V2 STEM uses `stem-s2` / `stem-s4`. Read historical disk/path metadata;
do not reconstruct an old filename from a renamed customer profile or product.

`CustomerFile` contains customer, purpose/tool, disk/path, bytes/MIME/checksum,
status, retention/expiry, deletion metadata, source links, quota flag and JSON meta.
`MlJob.input/output` carries service-specific paths/text/artifact maps.
`CustomerUsage.storage_used_bytes` is updated under row lock when files are
recorded/deleted. The shared quota check delegates to `CustomerBillingStateService`.
Quota checking and object writes are not one distributed transaction.

Controllers query/verify customer ownership and service identity before serving
results; file controllers also check active status. Library queries exclude
temporary records. An input URL allowlist is not an ownership check: obtain the
owned record first, then mint its URL. Storage service methods accepting raw
customer/path arguments rely on trusted authorized callers.

## Signed URLs and browser caches

- `CustomerOutputStorage` uses private writes and S3 temporary URLs. The generic
  temporaryUrl default is 60 minutes; customer-file links are 20 minutes.
  Clone/Leo/Caption/OCR submission sources use 120 minutes; STEM builder uses
  an eight-hour signed source URL and signed output upload targets.
- Customer controllers support stored-object redirects and some same-origin
  proxy streams. Many render/reference responses use private max-age=600 with
  stale-while-revalidate=60. STEM has private 1200-second redirects and an
  immutable one-year stream response path. Inspect the specific controller;
  do not describe all downloads as uncached or publicly cacheable.
- Expiring signed links and browser caches do not control permanent object
  retention. Already delivered/cached bytes cannot be recalled by deleting a row.
- Disk, ACL, CORS and production lifecycle rules must be checked in deployment;
  the repository does not establish them. Do not put signed URLs in docs.

## Server-side caching

`config/cache.php` selects the store (database default, Redis supported). Cache
does not replace MlJob/CustomerFile. Current V2 read caches are:

- `OmniSpeakerCatalog`: plan/locale/version metadata, 600-second default.
- `CttsWorkspaceCache`: customer reference metadata, 600 seconds; customer/tool/
  page/version history, 20 seconds; active jobs bypass history cache.
- `LeoWorkspaceCache`, `CaptionWorkspaceCache`: customer/page/version history,
  20 seconds; active jobs bypass. Mapping localized URLs/labels occurs in views.
- `AppShellData` caches account summaries; actions dispatch header refresh and
  relevant callers clear it. Inspect its callers when changing billing/storage.
- `StemRenderController` caches a local ZIP path via Laravel cache; verify local
  file availability and behavior across nodes before relying on a shared cache.

Reference add/delete and submission/sync paths invalidate relevant caches.
`DeletedResultReconciliation` invalidates CTTS, Leo and Caption history after
file deletion. Partial deletion removes output path references while retaining
inline history and remaining artifacts. Deleting the last registered render marks
the job deleted without removing its audit row. Source deletion clears matching
input paths without deleting surviving results. Whole QASR/Caption deletion also
invalidates both history caches.

## Deletion and bulk operations

`STORAGE_ALLOW_DESTRUCTIVE_OPERATIONS` defaults false. `StorageFileDeletionService`
guards destructive operations, locks a file row, deletes remote content, marks
metadata/API links deleted, decrements quota once and treats already-missing
objects as a cleanup outcome. A remote deletion exception preserves metadata.
`deleteOwned` filters active owned IDs and reports deleted/missing/failed/skipped;
bulk deletion is per file, not all-or-nothing.

`CustomerStorageBulkDownloadService` rejects missing/foreign IDs, defaults to
25 files/100 MB, streams objects into a private temp directory, and returns a
ZIP which `V2StorageFileController` cleans after response streaming.

Whole-job deletion uses service synchronizers/storage helpers; states can include
deleting/deleted/delete_failed. XTTS preserves reusable references. QASR deletes
transcript/SRT/source; Translation deletes source/target; OCR/STEM remove their
artifact sets and input. File-library deletion reconciles registered render history across services;
STEM payloads omit individually removed tracks. Existing inline Caption SRT can
still be downloaded from local job state after its separate object is removed.

Shared WAV/text/upload helpers require put() === true before metadata or quota
changes. A customer row serializes registration; the same disk/path updates its
existing file and only accounts the size delta. False returns and exceptions
roll back metadata. A deleted file cannot be registered again through a retry.
Whole-job deletion subtracts only the active, countable metadata size once;
already-deleted and unregistered legacy paths never subtract guessed bytes.
OCR/STEM deletion claims deleting and records delete_failed on partial failure.
Remote writes/deletes are not transactional with SQL: a retry can find an already
written/missing object and must reconcile its metadata safely.

Source: `Services/Storage/*`, `Support/{CustomerFolder,CustomerStorageLibrary,StorageBrowser,AppShellData}.php`,
`Http/Controllers/App/V2StorageFileController.php`, service render controllers,
`config/{filesystems,cache,livewire,customer_api}.php`, `routes/console.php`.

## V2 library behavior

Folder links use readable source/title/date metadata, not a UUID as the main
label. Back to Storage and breadcrumbs preserve q/product/type/sort and omit
folder at root. Selection contains only files, and previews/download/deletion
remain ownership checked. Bulk actions keep the existing 25-file/100-MB archive
limits and destructive guard. Source-audio lookup reuses request-local metadata
instead of querying once per visible row. Folder date sorting uses timestamps;
size units are calculated correctly. Root grouping still scans owned metadata;
very large libraries merit measured database pagination work, not a provider
query or a public cache.

OCR V2 now advertises and validates 100 MB, matching the default shared Livewire
limit. An environment override must remain aligned; production PHP/web server
limits have not been inspected.

Worker-object registration uses the same customer lock as application writes for
quota checks and metadata accounting. File deletion and history reconciliation
commit together; result purposes include render, transcription, caption and
target_text. Source-file deletion only clears source links. The last registered
result deletion marks history deleted while retaining the MlJob financial record.

## V2 final review — 2026-09-06

STEM keeps keyed player roots across unchanged Livewire morphs; a changed result
or track set replaces the root, and removal/navigation destroys its media.
Leo/Caption history excludes deleted/deleting jobs; deletion-failure labels and
workspace retry buttons are available in Caption/STEM. Storage overview reuses
request-local active files, and the V2 resource panel links to V2 Storage.
Private delivery/cache policy and bulk/destructive guards are unchanged.
