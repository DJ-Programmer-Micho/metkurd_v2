# Service implementation map

## API V2 service entry points — 2026-09-06

POST speech -> OmniSubmissionService (Apollo 1.5/2.0); voice-clone ->
CloneOmniSubmissionService (Vector 1.5/2.0); transcriptions -> LeoSubmissionService;
captions -> CaptionSubmissionService; ocr -> OcrV2SubmissionService; stem mode 2/4
-> StemV2SubmissionService. All are under /api/v2 with one jobs endpoint. No V1
submission service or new GPU payload builder is used. Public mode/model values
map through ApiCatalog, then the existing native catalog; internal action IDs
cannot be supplied by API clients. InputBoundary is shared with web for text,
voice entitlement, upload validation/probing, owned references and OCR preparation.
Caption options remain the native fixed SRT/segments defaults. Details: [API-V2.md](API-V2.md).


## Previous core-review scope — 2026-09-06

The current production scope contains exactly Apollo, Vector, Leo, Caption, OCR,
STEM 2 and STEM 4, plus their shared Storage dependencies. Apollo 1.0v, Translation,
Neo and other V1-only services are legacy inventory, not V2 release targets.
Apollo/Vector 1.5 and 2.0 are genuine variants already present in the V2 catalog;
do not confuse their reused action codes with Apollo 1.0v.


Verified from source on 2026-09-05. Paths below are repository relative.
`Jobs/` means `app/Services/MetKurd/Jobs/`; V2 tool views live in
`resources/views/app/v2/pages/tools/`. Every processing service uses `MlJob` for
web job tracking and customer-scoped history. Every listed output is owned by
MetKurd through database metadata and object storage, not provider retention.

| Service | Main V2 Livewire / Blade | Submission and adapter | Database action; provider contract |
|---|---|---|---|
| Text-to-Speech | `⚡app-tool.blade.php`, `components/xomni-tts/speaker-picker.blade.php` | `Jobs/OmniSubmissionService.php` -> `MetKurd/V2/RunPodV2Adapter::omni` | `xomni.generate` / `xomni-v2.generate`; `omni_v2`, `model_1` / `model_2`, `mode=builtin_ref` |
| Clone Text-to-Speech | `⚡app-tool.blade.php`, `components/ctts/reference-upload.blade.php`, `reference-history.blade.php` | `Jobs/CloneOmniSubmissionService.php` -> adapter `omni` | `clone_xomni.generate` / `vector-v2.generate`; `omni_v2`, model 1/2, `mode=audio_url` |
| Speech-to-Text / Leo | `⚡app-leo.blade.php` | `Jobs/LeoSubmissionService.php` -> adapter `qasr` | `leo.transcribe`; `qasr_v2`, `type=asr`, `model_variant=fine_tuned` |
| Caption | `⚡app-caption.blade.php` | `Jobs/CaptionSubmissionService.php` -> adapter `qasr` | `caption.standard`; `qasr_v2`, `type=caption` |
| OCR | `⚡app-ocr.blade.php` | `Jobs/OcrV2SubmissionService.php` -> adapter `kocr` / `RunPodProvider::runWithPolicy` | `ocr.standard`; `kocr_v2`, `options.task=layout_text` |
| STEM 2 | `⚡app-stem.blade.php`, route mode 2 | `Jobs/StemV2SubmissionService.php` -> `STEM/StemJobSyncService::buildRunpodInput` -> `RunPodProvider::run` | **`stem.sep2`**; endpoint `stem`, `stems=2` |
| STEM 4 | same workspace, route mode 4 | same submission/builder/transport | **`stem.sep4`**; endpoint `stem`, `stems=4` |

The catalog now agrees with the implemented billing identities: stem.sep2 and
stem.sep4. Presentation slugs do not rename database actions.

## Speech generation and clone results

`app/Services/XTTS/XttsJobSyncService.php` polls, normalizes base64 audio, persists
it through `CustomerOutputStorage`, then sets `MlJob` to done. It prefers the
job's explicit endpoint key exclusively; a missing V2 setting remains retryable and never falls through to legacy metadata. V2 input requests WAV
and base64; generated filenames are normalized for the appropriate tool family.
`XomniRenderController`, `XomniV2RenderController`, `CloneXomniRenderController`
and `VectorV2RenderController` expose the corresponding existing render routes.
`CttsReferenceStreamController` authorizes reusable reference previews.

`OmniSpeakerCatalog` caches built-in reference metadata by plan, locale and
version (default 600 seconds). `CttsWorkspaceCache` caches customer references
(600 seconds) and per-tool history (20 seconds, bypassed during active work).
Apollo history is queried directly by the workspace. Shared V2
`components/shared/recent-renders.blade.php` presents job history and players.

Clone submission validates upload MIME/20 MB limit, or looks up an active owned
reference from clone_tts/clone_xomni/vector-v2. Legacy non-S3 references are copied
to private S3 and their record updated. Signed input URLs last 120 minutes;
`ref_max_sec` is capped at 20 by the adapter. Reusable reference files survive
individual render deletion (`reference_is_reusable`). V1 XTTS/F5TTS and clone
workspaces still exist under `resources/views/app/pages/` and use the same sync
family; changing that synchronizer affects them too.

## Leo and Caption

Both views inspect audio with `Media/AudioProbeService`, validate MIME/100 MB,
calculate billable minutes and call their submission services. Input language
codes are ckb/ar/en; **UI locale ku is not the worker language code ckb**.
The signed input audio URL lasts 120 minutes. Intelligent is encoded as integer
0/1. Caption preserves output_format=srt, return_srt/return_segments=true,
max_words_per_caption=8, max_caption_seconds=6, min_caption_seconds=1.

`ASR/QasrJobSyncService` handles both. It extracts text, SRT and segments (including
fallback transcript extraction), saves `audio.txt` for ASR or `transcript.txt`
and optional `captions.srt` for Caption, and keeps text/segments in `MlJob.output`.
It does not create a separate JSON object for these current outputs. Leo uses
tool/job kind `leo`; historical qasr/wasr remain distinct. Caption uses caption.
`LeoRenderController` extends `QasrRenderController`; `CaptionV2RenderController`
extends the Caption controller. Their V2 routes provide text/audio and Caption SRT.

`LeoWorkspaceCache` / `CaptionWorkspaceCache` cache inactive history pages for
20 seconds with customer/version keys; active jobs bypass cache. Submission and
sync completion/failure invalidate the corresponding cache. Terminal sync
failures are corrected through the shared failed-app-job policy with debit
verification and durable refund retries. API wallets keep their own settlement.
See [lifecycle](GPU-JOB-LIFECYCLE.md).

## OCR

The view validates documents (100 MB), verifies PDF pages with OcrDocumentProbe,
and selects bounded, deduplicated page ranges and exports. Submission verifies
the count again before charging ocr.standard; browser page counts are preview
metadata only. pdfinfo must be available (OCR_PDFINFO_BINARY override); unreadable,
unverified or over-3888-page PDFs fail before billing. Image inputs retain the
existing one-page contract. Sources upload through shared storage. The adapter requests compact HTML internally and optional DOCX, with
executionTimeout=900000 and ttl=1200000 milliseconds. These are request policy
values, not proof of the deployed worker's behavior. Large layout JSON is
deliberately avoided in the request contract.

`OCR/OcrJobSyncService` handles legacy uploaded keys and V2 inline text/HTML/layout
responses. `OcrV2ArtifactService` persists worker artifacts and builds selected
exports from the result (DOCX, Markdown, HTML, ZIP). `MlJob.output` has nested
text/json/artifacts metadata; `CustomerFile` registers stored objects. Text is
required for completion. `OcrRenderController` provides existing text/JSON/input
routes and V2 artifact downloads. History is queried in the OCR workspace; no
dedicated V2 OCR history cache class exists. Rendering uses the shared job-status
presenter. The V2 workspace includes document previews and a text result area.
The OCR status card identifies its document and owns the active poll. A new
upload clears a terminal result from the editor; active scans stay in focus.
Submission and synchronization invalidate the request's computed job/result
values immediately, so a previous result cannot label a newly submitted scan.

## STEM 2 and STEM 4

Both use `htdemucs_ft` by default with MP3/192k outputs. Pricing context includes
`stem_output`, number of outputs, separation mode, seconds and minutes. Source
audio and final outputs use `renders/{customer-folder}/stem-s2/{job}` or `stem-s4`.
The payload builder supplies signed GET input and application-generated signed
PUT targets for original WAV, result JSON and each stem. This is a direct worker
upload contract; it does not pass through `RunPodV2Adapter`.

`StemJobSyncService` checks expected storage paths and requires both tracks
(vocals/instrumental) or four tracks (vocals/drums/bass/other) before marking done,
registering files/quota through `CustomerOutputStorage`. `StemRenderController`
serves owned tracks, payload and a ZIP; its ZIP cache is local, see storage docs.
The shared STEM view separates histories by mode; it includes upload/preview,
job presentation, download and deletion controls. No dedicated V2 history cache.

## Legacy inventory: Translation (outside V2 scope)

V1 `⚡app-tran.blade.php` owns validation, credit calculation, submission and
Livewire polling. Source text is persisted as `source.txt` before GPU submission.
`Translation/TranJobSyncService` persists `target.txt`, output text/language/counts
and provider output metadata. `TranRenderController` downloads source/target.
The component filters history by customer/tool and has deletion/local elimination
controls; elimination explicitly does not refund credits and is not proof of
provider cancellation. No dedicated V2 translation UI/history cache exists.

## Storage and History

V2 `resources/views/app/v2/pages/storage/⚡app-storage.blade.php` uses
`CustomerStorageLibrary`/`StorageBrowser`, `CustomerFile`, `CustomerUsage`, shared
storage/deletion and bulk archive services. `V2StorageFileController` handles
authorized single/bulk downloads. V1 My Storage remains available.

History is a read view of customer-scoped `MlJob` queries, not another job model.
Each workspace selects the appropriate tool/job kind/version/mode; shared
`AppRenderPayloads` supplies service result presentation, including STEM.
The V2 home page lists the service catalog. Stored-file navigation uses `CustomerFile` source/meta
links and job input/output. Deletion of a file and deletion of a whole job are
different operations; do not assume all services reconcile them identically.
See [STORAGE-AND-CACHE.md](STORAGE-AND-CACHE.md) for retention and safety rules.

## Hardening common to these contracts

Payload/action/model identities above remain unchanged (STEM config now also
names stem.sep2/stem.sep4). Every listed synchronizer is callable by the scheduled
reconciler and shares database polling coordination with the browser. All require
terminal success and safe persistence before done. OCR returned keys are limited
to its owned namespace, including text/JSON/exports. OCR/STEM/Leo/Caption and V1
Translation now commit job and debit atomically with idempotent submission keys.
History and downloads continue to use private application-owned results; file
deletion reconciles paths/caches without erasing financial history.

## V2 review follow-up — 2026-09-06

The generic speech workspace accepts only Apollo/Vector. Native Leo, Caption, OCR
and STEM routes cannot fall through to the removed V1 workspace placeholder.
V2 Storage is the resource-panel destination. Stable ToolAction bindings remain.
The final audit documents shared/temporary dependencies and the validation boundary
to share when a future API is implemented; no API refactor was performed.
