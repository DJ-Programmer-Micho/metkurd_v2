# Legacy retirement inventory — Admin P4A / P4B

Audit/removal date: 2026-09-07. **D1–D4: Removed in P4B.** P4A was inventory only;
P4B removed only the four approved source files. Routes, identities, migrations,
prices, balances and historical rows remain unchanged. P4B used isolated tests
only and did not access the application database. P4A database observations below
are historical audit evidence, not additional P4B database checks.
P0–P3 authorization, operation identity, dependency guards, bounded reads,
localization and confirmation behavior remain invariants.

## Evidence and classification rules

- **A — Current V2:** a native web/API V2 product, identity or implementation.
- **B — Shared and required:** currently used infrastructure, retained account UI,
  inherited implementation, or data binding needed by current consumers.
- **C — Legacy compatibility:** still routed V1/mobile behavior, historical data,
  or a candidate whose retirement proof is incomplete. C does not mean unused.
- **D — Proven obsolete source candidate:** no required current repository caller
  or persisted-domain dependency found, with the specific proof below. D is a
  bounded source conclusion, not permission to delete or a claim about uninspected
  deployed extensions. No persisted identity or public asset qualifies for D here.

Source searches covered application code, routes, configuration, providers,
components, tests, migrations/seeders, build entries and relevant asset references.
Current source overrides older plans. Product identities from the native catalogs
were compared with the effective loopback database, not inferred from seeders alone.
No customer content, object paths, keys, tokens or provider payloads were exported.
Landing CMS implementation and its translation catalogs remain outside this audit.

The actual local server is **MariaDB 10.4.28**, with no Laravel config cache at the
time of inspection. Direct PDO used a consistent-snapshot **READ ONLY** transaction
and rolled it back; queries returned schema metadata, catalog identities and
aggregates only. This verifies local dependency observations, not native Oracle
MySQL or application/mutation acceptance. The imported target is not asserted to
be a byte-for-byte copy of the designated final SQL snapshot or the live service.

An isolated SQLite `:memory:` / array-cache/session Laravel bootstrap registered
**218 routes with zero duplicate names**, including 26 API V1 and 19 mobile routes.
These are the complete current command's entries, including framework routes;
the earlier 216-route phase result was not used as a reason to alter routing.
Runtime component-path resolution was checked separately below. No HTTP job,
provider polling, storage operation, migration or billing action was invoked.

### Primary source map

| Evidence | Source |
|---|---|
| Native products and bindings | [metkurd_v2.php](../../config/metkurd_v2.php), [MetKurdV2ToolCatalog](../../app/Support/MetKurdV2ToolCatalog.php), [ApiCatalog](../../app/Services/CustomerApi/V2/ApiCatalog.php) |
| Web, result, account and Admin routing | [web.php](../../routes/web.php) |
| Public API and mobile routing | [api.php](../../routes/api.php) |
| V1 submissions/scopes | [CustomerApiJobSubmissionService](../../app/Services/CustomerApi/CustomerApiJobSubmissionService.php), [CustomerApiAccessService](../../app/Services/CustomerApi/CustomerApiAccessService.php) |
| Mobile products/submissions | [MobileAppCatalog](../../app/Services/Mobile/MobileAppCatalog.php), [MobileJobSubmissionService](../../app/Services/Mobile/MobileJobSubmissionService.php) |
| Native V2 entry points | [ApiSubmission](../../app/Services/CustomerApi/V2/ApiSubmission.php), [native jobs](../../app/Services/MetKurd/Jobs), [V2 workspaces](../../resources/views/app/v2/pages) |
| Shared result generation | [AppRenderPayloads](../../app/Support/AppRenderPayloads.php), [result controllers](../../app/Http/Controllers/App/Services) |
| Final P0 dependency policy | [AdminCatalogDeletion](../../app/Services/Admin/AdminCatalogDeletion.php), [models](../../app/Models), [payment models](../../app/Domain/Payments/Models), [migrations](../../database/migrations) |
| Admin projection/read consumers | [AdminV2Catalog](../../app/Services/Admin/AdminV2Catalog.php), [AdminOperations](../../app/Services/Admin/AdminOperations.php), [Admin support](../../app/Support/Admin) |
| Component resolution/build | [livewire.php](../../config/livewire.php), [AppServiceProvider](../../app/Providers/AppServiceProvider.php), [vite.config.js](../../vite.config.js) |

## Definitive classification table

`V1/<area>` below means the corresponding workspace under
`resources/views/app/pages/<area>/`; the exact filename/route inventory follows.
For all action rows, pricing/entitlements and Admin analytics consume the persisted
identity. Purchases/subscriptions attach through the plan, not a fabricated direct
ToolAction purchase FK. The database table below supplies individual history counts.
An A identity may also have V1 callers and historical records; A never permits
renaming that identity. B infrastructure must stay even if a C UI is retired later.

| Item | Type | Current caller | Historical dependency | V2 dependency | Class | Recommendation |
|---|---|---|---|---|---|---|
| `xomni` / `xomni.generate` | Tool/action | Apollo 1.5 web/API V2; V1 Xomni and API Apollo 1.5 | Jobs, files, ledgers, prices, entitlements | Native Apollo 1.5 | A | Keep identity; retain legacy worker/result interpretation |
| `xomni-v2` / `xomni-v2.generate` | Tool/action | Apollo 2.0 web/API V2 | Schema/policy references; absent in local catalog | Native Apollo 2.0 | A | Keep; verify existing registration migration before rollout |
| `clone_xomni` / `clone_xomni.generate` | Tool/action | Vector 1.5 web/API V2; V1 clone-Xomni/API Vector 1.5 | Jobs, reusable references, ledgers, plan access | Native Vector 1.5 | A | Keep identity and references |
| `vector-v2` / `vector-v2.generate` | Tool/action | Vector 2.0 web/API V2 | Absent in local catalog; owned reference compatibility | Native Vector 2.0 | A | Keep; verify registration before rollout |
| `leo` / `leo.transcribe` | Tool/action | Leo workspace/API transcriptions | Absent in local catalog; separate from QASR history | Native Leo | A | Keep; never relabel QASR jobs as Leo |
| `caption` / `caption.standard` | Tool/action | Native Caption; V1 Caption and API V1 | Jobs/files; mobile ASR history catalog also includes Caption | Native Caption | A | Keep shared identity |
| `ocr` / `ocr.standard` | Tool/action | Native OCR; V1 OCR, API V1 and mobile | Source/result files, jobs, ledgers, plan access | Native OCR | A | Keep shared identity and historical artifact formats |
| `stem` / `stem.sep2` | Tool/action | Native mode 2; V1/API/mobile STEM | Jobs, files and pricing/access rows | Native STEM 2 | A | Keep exact action; `2-stem` is a URL, not an identity |
| `stem` / `stem.sep4` | Tool/action | Native mode 4; V1/API/mobile STEM | Jobs/files/ledgers; shared `stem` file namespace | Native STEM 4 | A | Keep exact action; do not rename to `stem.4` |
| `tts` / `tts.standard` | Tool/action | V1 XTTS, API Apollo 1.0/XTTS alias, mobile TTS | Large retained job/file/ledger history | No native V2 submission | C | Retain until V1/mobile and history have an approved replacement |
| `ftts` / `ftts.standard` | Tool/action | V1 F5TTS, API Delta/F5TTS alias, mobile TTS | Jobs/files/ledger history | No native V2 submission | C | Retain compatibility |
| `clone_tts` | Tool / file binding | V1 clone XTTS/mobile; V2 reference catalog | Reusable reference files from older clones | V2 accepts owned references with this code | B | Keep reference binding; UI is independently classified below |
| `clone_tts.standard` | Action | V1 clone XTTS, API Vector 1.0, mobile CTTS | Jobs, files, ledger/plan references | Not a native V2 submission action | C | Retain action/history; do not confuse with reusable-file support |
| `asr` / `asr.standard` | Tool/action | V1 WASR, API WASR, mobile WASR | Jobs, `asr` and `wasr` files, ledgers | No native Leo submission | C | Preserve ASR/WASR mappings |
| `qasr` / `qasr.standard` | Tool/action | V1 QASR, API QASR, mobile QASR | Jobs/files/ledgers | Worker/controller family shared, identity is not Leo | C | Keep identity; split legacy UI from shared implementation |
| `tran` / `tran.standard` | Tool/action | V1 Translation, API translate, mobile TRAN | Source/target files, jobs and ledger history | No native V2 service | C | Retain compatibility; no modernization in P4A |
| `youtube_audio` / `youtube_audio.mp3` | Tool/action | Routed V1 downloader and format catalog | 1 pricing / 4 entitlement rows locally | None | C | Inactive and zero jobs do not prove obsolete |
| `youtube_audio` / `youtube_audio.wav` | Tool/action | Same routed downloader | 1 pricing / 4 entitlement rows | None | C | Retain pending explicit product retirement |
| `youtube_video` / `youtube_video.p480` | Tool/action | Routed V1 downloader and quality catalog | 1 pricing / 4 entitlement rows | None | C | Retain |
| `youtube_video` / `youtube_video.p720` | Tool/action | Same | 1 pricing / 4 entitlement rows | None | C | Retain |
| `youtube_video` / `youtube_video.p1080` | Tool/action | Same | 1 pricing / 4 entitlement rows | None | C | Retain |
| `youtube_video` / `youtube_video.p4k` | Tool/action | Same | 1 pricing / 4 entitlement rows | None | C | Retain |
| `wasr` job/file alias | Historical code, not a local Tool row | Mobile catalog, ASR sync, storage/result readers | 39 files use `wasr`; 39 jobs have WASR kind | Shared storage must interpret it | C | Keep alias; do not create/rename a Tool as cleanup |
| Native V2 workspaces, Storage, API portal, catalog, adapter, InputBoundary and native submissions | UI/core | `/app-v2`, `/api/v2` | Native jobs/files/API identities | Direct | A | Keep |
| V1 XTTS, F5TTS, clone XTTS, Xomni, clone Xomni UIs | UI | Their registered `/app/*` routes and V1 navigation | Service-specific history and bookmarks | No V2 processing-page include | C | Can retire UI later without deleting the shared services |
| V1 WASR, QASR, Caption, OCR, STEM, Translation UIs | UI | Registered V1 routes | Customer results/history | No V2 processing-page include | C | Retire UI only after routes/bookmarks/history have replacements |
| V1 YouTube UI and Youtube processing/storage services | UI/backend | Registered downloader; queued `ProcessYoutubeDownloadJob` | Catalog policy; possible other-environment jobs/files | No native V2 dependency | C | Product/traffic and queued-job proof required first |
| V1 Home / My Storage / old API portal | UI | Registered V1 routes and old shell | Bookmarks, files, API account compatibility | V2 has dedicated counterparts; old account shell still links back | C | Remove UI only after old-shell links and compatibility are addressed |
| V1 Profile / Billing Register for customer | UI | V2 topbar and resource links use `app.profile` / `app.billing` | Account identity and purchase history | Directly reached from V2 | B | Keep until account UI replacement is separately authorized |
| Subscription-plan / storage-plan / add-on / FIB checkout UIs | UI/payment flow | Shared customer header and purchase flow | Plans, orders, subscriptions, pending payments | Reachable from retained V2 account journeys | B | Keep checkout and historical payment routes |
| App layout/header/nav/auth components | UI/framework | Retained account pages, V1 routes; unqualified Admin nav tags | Auth/session and existing navigation contract | Account/login boundary; Admin also reuses App nav components | B | Split presentation later; do not remove with V1 tool UIs |
| `MlJob`, Tool/ToolAction, CustomerFile, CustomerUsage, CustomerFolder, AppToolCatalog | Domain/storage | Native jobs, storage/history, API, mobile, Admin | IDs, paths, job kinds, usage and ownership | Core shared dependency | B | Keep as shared |
| Pricing/entitlement/plan/voice models and runtime resolvers | Policy/domain | Native web/API quotes/access and Admin previews | Usage rules, overrides, grants, subscription lineage | Core shared dependency | B | Keep; no blanket legacy pricing deletion |
| CreditService, wallets/ledgers, billing state, normalized subscriptions, payment domain | Financial infrastructure | Web/API/native jobs, checkout and Admin P0 | Balances, purchase/reconciliation/audit evidence | Core shared dependency | B | Keep; historical reconciliation is a separate task |
| XttsJobSyncService / QasrJobSyncService / OcrJobSyncService / StemJobSyncService | Shared synchronization | Native jobs, reconciliation and legacy clients | Multiple input/output formats and worker versions | Direct | B | Keep; legacy names are not obsolescence evidence |
| AsrJobSyncService / TranJobSyncService | Legacy processing plus shared dependency wiring | V1/API/mobile/reconciler; constructor dependencies of shared API sync | Active/retained legacy job behavior | Transitively constructed by V2 API local settlement service, not V2 processing algorithms | B | Keep now; split wiring/legacy branches only in a separate design |
| XttsRenderController / CloneXttsRenderController | Shared parent controllers | Apollo/Vector subclasses, legacy routes | Historical audio and authorization | Inherited implementation is directly used by V2 | B | Can split common base later; cannot remove parent with old routes |
| QasrRenderController / CaptionRenderController | Shared parent controllers | Leo/Caption V2 subclasses and V1 routes | Text/audio/SRT/JSON compatibility | Direct inheritance | B | Keep shared implementation |
| OcrRenderController / StemRenderController / CttsReferenceStreamController | Result/reference controllers | V2 and V1 result/reference routes | Sources, artifacts, reusable references and historical modes | Direct | B | Add missing V2 aliases first where specified below |
| CustomerOutputStorage, CustomerStorageLibrary, StorageBrowser, deletion/reconciliation/archive helpers | Storage | V2 Storage and native jobs; legacy consumers | Private paths, source/meta links, quota, API expiry | Direct | B | Keep; no object deletion to simplify catalog |
| RunPodProvider, JobPollCoordinator, execution locks, ReconcileMlJob and scheduled commands | Infrastructure | Native/V1/API/mobile jobs | Durable in-flight/refund/settlement evidence | Direct | B | Keep shared; do not drop legacy branches while clients remain |
| Customer API auth/key/access/usage/reservation/job-sync/file-link services and records | API infrastructure | `/api/v1`, `/api/v2`, both portals, scheduler and Admin | Keys, requests, reservations, result IDs and financial links | Direct/transitive | B | Keep, including legacy scope alias support |
| CustomerApiJobSubmissionService and V1 controllers | Legacy submission | 26 retained API V1 routes | API/MlJob/result histories and idempotency | Not used for native V2 submission | C | Retain programmatic compatibility |
| Mobile controllers/catalog/submission/status/upload/voice/file services | Mobile | 19 registered mobile endpoints | Sanctum tokens, app job/file groups, shared wallet/results | Shared infrastructure underneath, separate mobile submission | C | Retain; no mobile API retirement is authorized |
| Admin P0/P1/P2/P3 services, traits, view pages, active layouts/nav, AdminAuthController | Admin | Current Admin routes/components and final mutations | Durable operation/audit and catalog/financial references | Current management boundary | B | Keep; logout controller is active |
| TranslationArea / AreaJsonTranslations / localization middleware | Shared localization | Direct and Livewire requests, EN/AR/KU catalogs | Current aliases and mixed shared components | Admin/App V2 depend on current area detection | B | Keep; old assumptions were already replaced in P1 |
| `resources/js/admin.js`, `resources/css/admin.css` | Admin assets | Vite entry and Admin layout | P3 confirmation/retry/navigation contract | Admin invariant | B | Keep single bridge |
| `resources/js/app.js`, `metkurd-waveform.js`, shared App assets and V2 inline handlers | Shared assets | Vite/layouts, waveform players, FilePond, Bootstrap, SweetAlert | Current upload/playback/navigation lifecycle | Direct | B | Keep runtime/bundle references |
| V1-only inline handlers and styles inside routed processing workspaces | UI assets | Their C-class V1 UIs | Existing interactions/results | No V2 processing UI include | C | Can retire with approved UI retirement, not before |
| Template demo initializers and unproven legacy CSS/public assets | Public assets | No literal scoped reference for 118 top-level page scripts in each App/Admin tree; broader proof incomplete | Public URLs/deployment/cache consumers not measured | Bundled/shared dependencies must be separated | C | Asset-specific P4B evidence required; no D asset claim |
| Old AdminController | Controller | None; no registered handler or source/test reference beyond definition | No model/serialized-job use; target views absent | None | D | D1: **Removed in P4B**; active Admin controller/Livewire contracts preserved |
| CustomerApiTtsService | Earlier duplicate service | None; V1 controllers use CustomerApiJobSubmissionService | No model/morph/queued-class dependency; reads shared records only if called | None | D | D2: **Removed in P4B**; active API submission service preserved |
| Admin `⚡nav-feature-link.blade.php` copy | Shadowed component | No explicit Admin namespace caller; bare tag resolves App copy | No persisted-domain dependency | App copy remains required | D | D3: **Removed in P4B**; App copy and location order preserved |
| Admin `⚡nav-multi-feature-link.blade.php` copy | Shadowed component | Same runtime-path proof | No persisted-domain dependency | App copy remains required | D | D4: **Removed in P4B**; App copy and nested navigation preserved |

The table covers the union of **18 local actions / 13 local tools** and **three
additional current V2 actions/tools in source**: 21 actions / 16 tools. `wasr` is
an additional persisted file/job alias, not a seventeenth locally discovered Tool.
No other non-fixture production ToolAction identity was found in catalog/registration
definitions. Tests deliberately introduce disposable identities; those are not
production product candidates. Neo/Apollo 1.0 naming does not establish an extra
V2 product: Apollo 1.0 maps to `tts.standard`; no native V2 Neo action is defined.

## Complete V1 workspace inventory

All files below are under `resources/views/app/pages/`. There are 21 workspaces
(22 PHP/Blade files because Profile is a two-file Livewire component). All have
registered routes. **No V1 processing workspace Blade file is included by the
current V2 processing workspaces.** Sharing a synchronizer does not make the V1 UI
required. The user's UI-specific categories map as follows: direct retained V2
account journey = UI-A/global-B; legacy UI with shared backend = UI-C/global-C
with the backend separately B; no workspace qualifies for UI-D.

| Exact relative file | Route name; localized path | UI dependency and retention |
|---|---|---|
| `xtts/⚡app-xtts.blade.php` | `app.xtts`; `/app/xtts` | Legacy UI; XTTS shared sync/render base remains B |
| `f5tts/⚡app-f5tts.blade.php` | `app.f5tts`; `/app/f5tts` | Legacy UI; F5/API/mobile consumers remain |
| `clone-xtts/⚡app-clone-xtts.blade.php` | `app.clone-xtts`; `/app/clone-xtts` | Legacy UI; reusable references and render base remain |
| `omni/⚡app-xomni.blade.php` | `app.xomni`; `/app/xomni` | Legacy UI, current A identity; V2 has its own workspace |
| `omni/⚡app-clone-xomni.blade.php` | `app.clone-xomni`; `/app/clone-xomni` | Legacy UI, current A identity; V2 has its own workspace |
| `wasr/⚡app-wasr.blade.php` | `app.wasr`; `/app/wasr` | Legacy UI; ASR/WASR compatibility |
| `qasr/⚡app-qasr.blade.php` | `app.qasr`; `/app/qasr` | Legacy UI, distinct from Leo |
| `qasr/⚡app-caption.blade.php` | `app.caption`; `/app/caption` | Legacy UI; native V2 Caption is separate |
| `ocr/⚡app-ocr.blade.php` | `app.ocr`; `/app/ocr` | Legacy UI; OCR backend/result readers are shared |
| `stem/⚡app-stem.blade.php` | `app.stem`; `/app/stem` | Legacy UI; native V2 modes have separate workspace route |
| `tran/⚡app-tran.blade.php` | `app.tran`; `/app/tran` | Legacy UI/service still exposed to V1/API/mobile |
| `youtube/⚡app-youtube-downloader.blade.php` | `app.youtube`; `/app/youtube` | Legacy UI; inactive local tools do not unregister route |
| `home/⚡app-home.blade.php` | `app.home`; `/app/home` | V1 navigation and old account-shell return links |
| `my-storage/⚡app-storage.blade.php` | `app.storage`; `/app/my-storage` | V1 storage UI; V2 has dedicated storage, shared files remain |
| `api/⚡app-api-access.blade.php` | `app.api-access`; `/app/api` | V1 key/account portal; V2 has dedicated portal |
| `profile/⚡app-profile/app-profile.php` and `app-profile.blade.php` | `app.profile`; `/app/profile` | V2 topbar directly enters this account UI |
| `billing/⚡app-billing.blade.php` | `app.billing`; `/app/my-billing` | V2 topbar/resource panel directly enters this account UI |
| `subscription-plan/⚡subscription-plan.blade.php` | `subscription-plan`; `/app/subscription-plans` | Shared account header / purchase journey |
| `storage-plan/⚡storage-plan.blade.php` | `storage-plan`; `/app/storage-plans` | Shared account header / purchase journey |
| `addon-credits/⚡addon-credits.blade.php` | `addon-credits`; `/app/addon-credits` | Shared account header / purchase journey |
| `payments/⚡fib-payment.blade.php` | `payments.fib.show`; `/app/payments/fib/{payment}` | Existing checkout, pending and historical payment links |

Shared [App header](../../resources/views/app/partials/header-one.blade.php) and
[App navigation](../../resources/views/app/partials/navbar-one.blade.php) still
lead to V1 pages when reached through these account flows. The V2 topbar itself
uses V2 Storage/API links. Retiring processing UIs does not authorize deleting
the entire `app/pages`, `app/layouts` or `app/partials` tree.

## Result and alias retirement map

All web routes in this table have the existing locale/auth/active/verified and
tool-access boundary. New aliases must preserve customer ownership, tool/job-kind
isolation, active-file/expiry behavior and response semantics. An alias is not
permission to widen service access. Old names must remain until caller and
historical bookmark/external-link acceptance is established.

| Existing route family | Actual current consumer | Existing V2 alias | Retirement condition |
|---|---|---|---|
| `app.renders.xomni.{stream,download}` and `app.renders.xomni-v2.*` | V2 `app-tool::recentRenders`, V1 Xomni for 1.5 | None | Add V2 aliases and update V2 generation first; preserve old audio access |
| `app.renders.clone_xomni.*`, `app.renders.vector-v2.*` | V2 Vector workspace; V1 clone-Xomni for 1.5 | None | Add V2 aliases first; keep inherited CloneXttsRenderController |
| `app.xomni.speaker.{preview,avatar}` | OmniSpeakerCatalog used by native Apollo/Vector plus V1 | None | Add V2 speaker aliases and update catalog-generated URLs first |
| `app.ctts-references.stream` | V2 owned reference picker; clone files across `clone_tts`, `clone_xomni`, `vector-v2` | None | Add shared/V2 alias before any route retirement; preserve older reference files |
| `app.renders.ocr.{input,text,text.view,json,json.view}` | V2 OCR via AppRenderPayloads, V1 OCR | Only exports: `app.v2.ocr.artifact` for DOCX/Markdown/HTML/ZIP | Existing export route does not replace source/TXT/JSON routes; add corresponding aliases |
| `app.renders.ocr.payload` | Registered legacy result endpoint; not used by the current V2 payload builder | No equivalent payload alias | C, not D: historical/public route use not measured; preserve until traffic/history proof |
| `app.renders.stem.{stream,download,zip}` | V1 workspace and AppRenderPayloads; current V2 workspace generates its own V2 URLs | `app.v2.stem.{stream,download,zip}` | No new core aliases needed; migrate remaining old-name consumers and retain historical links |
| `app.renders.stem.payload` | Registered legacy payload endpoint | No payload alias | Retain C until historical/API/client usage is established |
| `app.renders.qasr.{txt,json,input-audio}` | V1 QASR; shared parent code supports Leo | `app.v2.leo.{txt,audio}` for Leo only | Leo aliases are not substitutes for QASR historical identities; retain QASR reader |
| `app.renders.caption.{txt,srt,json,input-audio}` | V1 Caption | `app.v2.caption.{txt,srt,audio}` | JSON remains a legacy route; keep historical access until deliberate replacement |
| `app.renders.xtts.*`, `f5tts.*`, `clone_xtts.*`; old XTTS/F5 voice assets | V1 workspaces; native V2 uses shared controller bases | None | C endpoints. A fallback `clone_xtts` route string exists in V2 code, but current V2 history is filtered to its selected native tool; that string alone is not proof of a current legacy render request |
| `app.renders.wasr.*`, `app.renders.tran.{source,target}`, `app.renders.youtube.download` | V1 results and historical bookmarks | None | C, retained with those identities; shared storage must still understand their files |
| API V1 jobs/files and mobile files | Their programmatic clients; V1 file serializer generates V1 download URLs | API V2 has separate jobs/files endpoints | Do not replace/redirect V1 downloads blindly: bearer scopes, job versions and payload contracts differ |
| `admin.services.rules` | Registered redirect to voices; route/bookmark compatibility | Destination `admin.services.voices` already exists | C compatibility alias; not a broken/duplicate controller or D route |

See [AppRenderPayloads](../../app/Support/AppRenderPayloads.php),
[OmniSpeakerCatalog](../../app/Services/MetKurd/Omni/OmniSpeakerCatalog.php),
[CttsWorkspaceCache](../../app/Services/MetKurd/V2/CttsWorkspaceCache.php), and
[native tool view](../../resources/views/app/v2/pages/tools/⚡app-tool.blade.php).
The V2 clone history query selects the current tool ID, while reusable references
intentionally span older clone codes; those are different dependency semantics.

## Local database inventory and deletion blockers

Counts are all retained rows, including inactive/deleted/failed history. File
counts are grouped by **tool code**, not action; the 73 STEM files cannot be
assigned to a mode from `tool_code` alone and must not be summed twice. Ledger
counts here use its explicit `tool_action` column; null does not mean unrelated.

| Action | MlJobs | Tool-code files | Pricing rules | Plan entitlements | Explicit ledger action refs |
|---|---:|---:|---:|---:|---:|
| `xomni.generate` | 570 | 517 | 4 | 4 | 402 |
| `xomni-v2.generate` | missing catalog | — | — | — | — |
| `clone_xomni.generate` | 212 | 420 | 4 | 4 | 108 |
| `vector-v2.generate` | missing catalog | — | — | — | — |
| `leo.transcribe` | missing catalog | — | — | — | — |
| `caption.standard` | 18 | 50 | 4 | 4 | 2 |
| `ocr.standard` | 32 | 90 | 4 | 4 | 11 |
| `stem.sep2` | 2 | 73 shared | 4 | 4 | 0 |
| `stem.sep4` | 19 | 73 shared | 4 | 4 | 5 |
| `tts.standard` | 542 | 385 | 4 | 4 | 214 |
| `ftts.standard` | 513 | 426 | 4 | 4 | 134 |
| `clone_tts.standard` | 158 | 303 | 4 | 4 | 44 |
| `asr.standard` | 39 | 34 `asr` + 39 `wasr` | 4 | 4 | 14 |
| `qasr.standard` | 22 | 39 | 4 | 4 | 3 |
| `tran.standard` | 60 | 88 | 4 | 4 | 24 |
| `youtube_audio.mp3` | 0 | 0 | 1 | 4 | 0 |
| `youtube_audio.wav` | 0 | 0 | 1 | 4 | 0 |
| `youtube_video.p480` | 0 | 0 | 1 | 4 | 0 |
| `youtube_video.p720` | 0 | 0 | 1 | 4 | 0 |
| `youtube_video.p1080` | 0 | 0 | 1 | 4 | 0 |
| `youtube_video.p4k` | 0 | 0 | 1 | 4 | 0 |

The three missing A registrations are supplied by existing source migrations
`2026_08_15_000000_register_xomni_v2_tool`,
`2026_08_15_000100_register_vector_v2_tool`, and
`2026_08_16_000100_register_leo_v2_tool`. P4A did not apply them or seed data.
Follow [PRODUCTION-DB-IMPORT.md](PRODUCTION-DB-IMPORT.md) to review the complete
pending sequence; do not run development seeders as a retirement/import shortcut.
The local migration inventory includes Admin P0 safety; September poll/API V2
migrations were not recorded in the inspected target. This is environment state,
not a claim that source P0–P3 is missing or permission to change that environment.

| Relationship | Local evidence | Why deletion is blocked / what must survive |
|---|---|---|
| `MlJob.tool_id`, `tool_action_id`, `job_kind`, input/output metadata | 2,187 jobs: 1,644 done, 370 failed, 173 deleted | Retained job identity, private results and financial references; deleted status is not no history |
| CustomerFile tool/source/meta/path and API links | 2,464 files: 2,236 active, 228 deleted | `source_type/source_id` and `meta.job_id` are interpreted by CustomerStorageLibrary/DeletedResultReconciliation; no Tool FK is required for a real dependency |
| CreditLedger normalized and old reference fields | 6,499 rows; 961 explicit action/tool refs; 5,538 null action refs; all 6,499 have related_type | Zero `ml_job_id` fields does not mean zero job history: 61 `related_id` values match retained job IDs. This match is a conservative cross-reference count, not proof of every row's semantic type. Preserve related/source/reference/meta interpretation |
| CreditOrder plan/product/payment/intent links | 34 orders; 29 plan refs, 28 payment refs, 1 intent ref, 0 product FKs | Missing product FK does not erase old `meta.product_code` evidence; do not backfill or delete |
| Payment polymorphic purchasable + snapshots/meta/provider references | 151 payments; 63 awaiting customer action; 28 paid; other states retained | Pending/unfulfilled and terminal purchases still block product/plan retirement; all 28 paid rows here are fulfilled, which does not resolve earlier billing anomalies |
| PaymentIntent purpose ID/code, request/meta, transactions/webhooks | 1 intent | Pending/unfulfilled intentions and immutable purchase evidence survive catalog retirement |
| Normalized service subscriptions | 1,078 current-or-historical rows; all have service_plan_id; 29 also have previous_service_plan_id | Use **unfiltered** ServicePlan `subscriptions()` and `previousSubscriptions()`; never `customers.service_plan_id` |
| Storage subscriptions and monthly grants | 1,047 storage subscriptions; 2,755 credit monthly grants | StoragePlan/ServicePlan/subscription lineage is required independently of current status |
| PricingRule / PlanEntitlement / customer overrides / UsageEvent | 54 prices, 72 entitlements; customer prices/entitlements and usage_events each 0 | Current configuration alone blocks action deletion; zero usage_events does not prove no ledger/job/quote history |
| Voice / PlanVoiceAccess / CustomerVoice / voice codes in job input | 160 voices, 640 plan access rows, 0 customer voice rows | Voices, plan access and input speaker/voice_code are distinct checks; an access row alone does not prove an active customer subscription |
| API keys/jobs/results/usage/reservations | Each inspected table currently 0 | Retained endpoints and source contracts require them; another environment/client may have history. No V1/API table qualifies for retirement |
| AdminOperation / AdminAuditEvent | Both 0 locally | P0 requested plan/product IDs, target type/ID and before/requested/after snapshots are logical references, even without product FKs |

P0 final guards already check Tool actions/jobs/files/API jobs; ToolAction jobs,
pricing/entitlements/overrides/usage/API action strings/files; ServicePlan current
and previous subscription relationships, pricing, entitlements, voice access,
monthly grants, orders, polymorphic Payments, PaymentIntents and AdminOperation
requests. PlanVoiceAccess checks its parent's unfiltered subscription/history
relationships. Those protections must remain unchanged.

**P0's interactive deletion guard is a minimum policy boundary, not a complete
retirement proof.** A P4B inventory must also inspect the ledger/string/JSON,
historical audit, queued-class, source-file and external-client references described
here. Some schema FKs cascade or null references rather than restrict deletion;
a successful SQL DELETE would not demonstrate safe historical retirement. No
ledger/reference repair, billing reconciliation or retention cutoff was inferred.

## API V1 and mobile dependency contract

The 26 V1 routes include account/usage, six voice-catalog routes, product submissions
and legacy aliases, ASR/QASR/Caption/OCR/Translation/STEM, and jobs/private downloads.
[TtsController](../../app/Http/Controllers/Api/Customer/V1/TtsController.php) delegates
to **CustomerApiJobSubmissionService**, not the D2 CustomerApiTtsService class.
The API V1 submission service still owns its older worker/input/billing flow;
native API V2 uses ApiSubmission plus native SubmissionContext/services instead.

Keep canonical V1 scopes `tts:apollo-1-0v`, `tts:apollo-1-5v`, `tts:delta-1-0v`,
`tts:vector-1-0`, `tts:vector-1-5`, `asr:wasr`, `asr:qasr`, `caption:qasr`,
`ocr:generate`, `translation:generate`, `stem:generate`, `usage:read`, `jobs:read`,
`files:download`, their five TTS aliases, and wildcard handling. V2 family scope
configuration/expansion is separate; P1 explicit/derived scope ownership must not
be rewritten to make old scopes disappear.

Shared CustomerApiJobSyncService settles/releases reservations from local MlJob
state for V2, and can additionally refresh legacy jobs for V1. Its constructor
still receives legacy ASR/Translation synchronizers. CustomerApiFileLinkService
has shared result linking but a V1 download serializer; V2's controller builds
its own V2 URLs. Do not remove the whole class because one method is V1-specific.

The 19 mobile routes include authentication/social/phone verification, account/apps,
voice preview/avatar, jobs and files/upload/download. Sanctum tokens are distinct
from customer API bearer keys. Mobile groups are `tts`, `ctts`, `asr`, `stem`,
`ocr`, `tran`: submissions include XTTS/F5TTS, clone XTTS, WASR/QASR and the
retained OCR/STEM/Translation flows. Caption is included in mobile ASR history
grouping; this does not mean a separate mobile Caption submission endpoint exists.
Keep MobileAppCatalog, MobileJobSubmissionService, MobileJobStatusSyncService,
MobileJobOutputReferenceService, upload/voice/catalog/token/usage services and
the private mobile file controller. No modernization is part of P4A.

## D candidates and test impact

The source proof excludes mere autoload-map entries: Composer PSR-4 makes a class
loadable but does not invoke it. No provider binding, route, scheduled/queued
class reference, component or test invocation was found for D1/D2. Their class
names do not appear in migration/morph definitions. Before removal, D3/D4 were
resolvable through explicit Admin names, but those names had no source caller.

| ID / removed path | Proof before removal | Existing test impact / category | P4B outcome |
|---|---|---|---|
| D1 `app/Http/Controllers/Admin/Pages/AdminController.php` | Only definition hit; zero registered routes; obsolete target views absent. `admin.home` uses LivewirePageController; logout uses AdminAuthController | Current Admin route/dashboard/P0/P1/P2/P3 tests remain valid | **Removed in P4B**; 155 Admin tests passed after this deletion |
| D2 `app/Services/CustomerApi/CustomerApiTtsService.php` | Only definition hit; TtsController injects CustomerApiJobSubmissionService; V2 uses ApiSubmission | Retained V1/V2 and shared wallet coverage; no obsolete test deleted | **Removed in P4B**; 55 passed / 5 baseline failures, plus 4 passing provisioned Apollo/alias runtime cases; details below |
| D3 `resources/views/admin/partials/components/⚡nav-feature-link.blade.php` | Bare tag resolves App; no explicit Admin namespace caller | AdminP3UiTest, AdminRouteIntegrityTest, V2DashboardArchitectureTest and AdminNavigationCompatibilityTest | **Removed in P4B**; installed Finder still selects App; EN/AR/KU Admin/account and feature-lock checks passed |
| D4 `resources/views/admin/partials/components/⚡nav-multi-feature-link.blade.php` | Same installed Finder proof and absence of explicit caller | Same retained coverage, repeated after D4 separately | **Removed in P4B**; nested Services/Customers/Payments/Landing links, App/account and EN/AR/KU checks passed |

Runtime paths were resolved through the installed Livewire Finder after an
isolated Laravel bootstrap, not guessed from filename similarity. App precedes
Admin in `component_locations`; both explicit namespaces still exist. Preserve
that behavior in P4B. Removing an active App nav file could silently expose the
older Admin fallback instead and is not authorized by this classification.

No test is identified for deletion. For eventual C UI retirement, tests of V1
processing-page presentation should be replaced by V2-equivalent UI coverage **only
after** UI retirement is approved; service, ownership, storage, ledger and API
compatibility tests remain. Relevant suites include V2CoreReviewTest,
CloneV2WorkspaceTest, LeoV2WorkspaceTest, CaptionV2WorkspaceTest,
StemV2WorkspaceTest, OcrV2WorkspaceStatusTest, V2StorageSafetyAndLibraryTest,
StorageNavigationAndHistoryTest, MyStorageUiAndWasrOutputTest, PublicCustomerApiTest,
MobileApiTest and the P0/P1/P2/P3 Admin suites. ReconcileMlJob/terminal persistence
coverage must survive any later synchronizer split.

## Frontend and translation findings

- Admin layout loads its Bootstrap/template dependencies, Vite `admin.js`/CSS,
  SweetAlert and Toastr. P3's once-installed bridge replaces scoped native
  confirmation islands; there is no second old inline Admin confirmation bridge
  left to delete from the active layout. Vendor SweetAlert demonstration scripts
  are not proof of a second installed bridge.
- App `app.js` imports `metkurd-waveform.js`; V2/native pages use FilePond,
  WaveSurfer, Bootstrap and SweetAlert as well as shared App CSS. V2 includes the
  shared notifications component. The old `public/app` directory name does not
  establish V1-only ownership.
- Both `public/admin/js/pages` and `public/app/js/pages` contain **118 top-level
  template initializer files**. No literal references to those full paths were
  found in the scoped source scan (Landing CMS excluded). Examples include
  `dashboard-analytics.init.js`, `sweetalerts.init.js`, and `profile.init.js`.
  Nested dependencies such as the loaded lord-icon script are separate. Public
  URL access, dynamically assembled loader paths, deployment integrations and
  CSS selectors were not exhaustively proven absent; no bulk asset/CSS D claim
  is made. Audit each proposed file before a future removal batch.
- `public/{app,admin}/js/{app,layout,plugins}.js` and Bootstrap/theme CSS require
  per-layout evidence. The active template app/layout scripts cannot be removed
  with demo initializers. `plugins.js` is commented in the Admin layout, but that
  alone is insufficient proof for deleting a public asset from both areas.
- D1 has no translation-owned UI. D2 is unused code, not ownership of an API
  translation group. D3/D4 use `Subscribe to unlock this feature`, which also
  remains in the App multi-feature component. Keep the key. `Methods` and similar
  short labels have multiple active callers. No Admin/app translation key is
  proven D in this pass; dynamic labels, PHP catalogs and shared components
  prevent blanket key removal. Landing CMS catalogs were not edited.
- P1's TranslationArea/AreaJsonTranslations behavior and P3 current-page dialog
  messages, chart labels and RTL styles remain active. Earlier area-detection
  assumptions described in historical audit sections are not extra live files
  awaiting deletion.

## P4A sequence proposal — steps 1–4 completed in P4B

The original order below is retained as audit history. D3 and D4 were deleted and
verified separately. Steps 5–8 remain future work and were not authorized by P4B.

1. Start with a fresh reviewed source baseline and explicit P4B scope. Recheck D
   callers, deployed bindings and pending workers; retain recoverable source.
2. Remove D1 alone, validate current Admin routes/auth/dashboard. Do not remove
   AdminAuthController or rewrite namespaced Livewire routes.
3. Remove D2 alone, validate retained API V1 aliases, V2 submissions/status and
   shared reservation/file behavior. Do not remove API V1 or its active service.
4. Remove D3/D4 Admin-only copies, preserving App files and component registration
   order. Verify nested Admin/account navigation and EN/AR/KU behavior. No
   translation deletion is bundled with this step.
5. Treat public demo assets as a **separate evidence batch**: enumerate exact
   files, loader/build/layout references and deployed access, then classify. Do
   not combine uncertain assets/CSS with the four proven source candidates.
6. Add the missing V2 result/speaker/reference/OCR aliases in a separately tested
   compatibility change, switch only native V2 URL producers, and retain the old
   endpoints. STEM/Leo/Caption already have the listed native aliases; their
   historical identities/JSON routes still need deliberate treatment.
7. Only after an approved customer migration/traffic and historical-access plan,
   retire selected C processing UIs. Replace V2-linked Profile/Billing/checkout
   journeys first if the intended scope is to remove the whole old App shell.
   Keep API V1/mobile, shared controllers/synchronizers, records and aliases.
8. Any persisted catalog or historical-data retirement is a separate operation
   with a fresh complete dependency report. Pending purchases, retained jobs/
   files, subscriptions/previous plans, ledger/audit strings and JSON block a
   zero-current-users shortcut. P0 guards remain the final minimum protection.
   No age-out date, historical repair or billing-policy decision is set here.

## P4A verification and open boundaries (historical)

Completed: source/caller inventory, local read-only MariaDB schema/aggregate
queries, isolated route registration and installed-Livewire path resolution.
All document links resolve locally, aggregate action/file totals reconcile to
2,187 / 2,464, and hashes of all 1,937 baseline non-document repository files
remain unchanged. The shipped changes are this inventory and ADMIN-AUDIT.md;
AGENTS.md did not need a new durable engineering rule.
The initial aggregate probe used a MySQL-specific JSON operator unsupported by
this MariaDB version; it rolled back, and the final query used the actual
`job_kind` column. No SQL mutation or migration was attempted.

This is a documentation-only application change. P4A does not re-label the P3
test report as a new full regression pass; no application test deletion or build
was necessary. Existing P3 broader customer API visibility failures remain
recorded in ADMIN-AUDIT.md and were not repaired here. The audit helper scripts
and aggregate-only output are local ignored diagnostics, not shipped application
features. No production request logs, external-client inventory, object-presence
inspection, complete deployed queue history, native MySQL mutation acceptance,
or browser/production retirement acceptance was obtained. Those boundaries stop
unproven items from entering D. Historical billing reconciliation and business
pricing decisions remain separate from both P4A and P4B.

## P4B verification — 2026-09-07

The four deletions were performed in order, with verification completed before
each next deletion. No caller was rewritten. All database-backed tests used
SQLite `:memory:`, array cache/session, a separate config/route cache path, and
fake/mocked external services. No P4B application database query, migration,
seeder run, cleanup, payment change or historical repair was performed. Test
fixture seeding occurred only in the isolated in-memory schema.

| Step | Executed coverage | Result |
|---|---|---|
| D1 | AdminRouteIntegrityTest, AdminHomeDashboardTest, AdminP0SafetyTest, AdminP1CorrectnessTest, AdminP2OperationsTest, AdminP3UiTest | **155 passed, 1,929 assertions**; auth/logout and Admin runtime renders retained |
| D2 | PublicCustomerApiTest, ApiV2Test, SeparateCreditWalletArchitectureTest | **55 passed / 5 failed, 386 assertions**; all 30 API V2 cases passed |
| D2 baseline control | The five failing cases with the exact removed service restored temporarily | **Same 5 failed, 12 assertions**; service then removed again; failures are independent of removal |
| D2 provisioned compatibility | Four new PublicCustomerApiTest cases with explicit Omni catalog and API grants in SQLite | **4 passed, 64 assertions**; Apollo 1.0/1.5 and XTTS/XOMNI aliases list voices, submit through the active service, replay once and charge only the API wallet |
| D3 | AdminNavigationCompatibilityTest, AdminP3UiTest, AdminRouteIntegrityTest, V2DashboardArchitectureTest | **58 distinct tests passed after a targeted test correction/rerun**; initial run 55 passed / 3 failed on the new test's incorrect App-shell `dir` assumption; corrected navigation suite 10 passed, 187 assertions |
| D4 | Same four navigation/route/UI suites, repeated after D4 deletion | **58 passed, 1,242 assertions** |

The five unchanged baseline failures are:

- PublicCustomerApiTest `lists product-route voices for paid scoped keys`:
  Apollo 1.5 voice listing returns 403 with the legacy default catalog fixture.
- PublicCustomerApiTest `submits renamed tts product routes to the expected
  internal jobs` and `submits vector clone routes to the expected internal jobs`:
  missing Omni action fixtures produce `tool_action_id = 0` pricing foreign-key
  failures before submission. The original tests and default seeder remain intact.
- SeparateCreditWalletArchitectureTest `shows app credits in the shell and api
  credits on the api access page`: legacy API component balance is null instead
  of the expected 2,222.
- SeparateCreditWalletArchitectureTest `renders the V2 resource meter from the
  canonical shell data without clipping over-limit balances`: expected API
  Credits row absent. These two display failures were already recorded in P3.

Installed Livewire Finder resolution is asserted at runtime, after both separate
navigation deletions, for these surviving paths:

- `partials.components.nav-feature-link` →
  `resources/views/app/partials/components/⚡nav-feature-link.blade.php`.
- `partials.components.nav-multi-feature-link` →
  `resources/views/app/partials/components/⚡nav-multi-feature-link.blade.php`.

The new navigation tests inspect rendered nested Services, Customers, Payments
and Landing CMS anchors; actual Profile/Billing HTTP renders; and hidden
unavailable links versus accessible unrestricted links in EN/AR/KU. Admin
direction remains covered by P3 and navigation tests. The retained App shell
uses its existing locale markup; no App direction redesign was performed.
These are installed-Livewire/HTTP render checks, not interactive browser acceptance.

Isolated route registration is unchanged from the P4A snapshot: **218 routes,
zero duplicate names, 26 API V1 and 19 mobile routes**, with no method, URI,
action or middleware differences. PHP syntax and focused Pint passed for the
two changed test files; `git diff --check` passed. Bundled assets/references did
not change, so no frontend build was needed. AGENTS.md has no new engineering
rule and remains unchanged from the P4B baseline. No native MySQL or production
acceptance is claimed.

Only D1–D4, this inventory, ADMIN-AUDIT.md, CHANGELOG.md and the two supporting
test files changed from the fresh P4B source baseline. Existing P0–P3 changes
were preserved; no test was deleted. V1 routes/workspaces/API, mobile, all shared
synchronizers/controllers, current App navigation copies/location order,
Tool/ToolAction identities, translations, assets, billing/account/checkout and
historical records remain unchanged by P4B.

Remaining work stays open and was not started: V2 Apollo/Vector result aliases;
speaker/reference/OCR source/TXT/JSON aliases; asset-specific inventory; eventual
V1 processing UI retirement; Profile/Billing/account replacement before old-shell
retirement; a separate historical database dependency review; API V1/mobile
retirement (not approved); historical financial reconciliation; business pricing.
