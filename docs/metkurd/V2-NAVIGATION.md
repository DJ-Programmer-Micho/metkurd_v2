# V2 navigation lifecycle

## Global Process Queue — 2026-09-26

The V2 topbar uses a customer/locale-keyed queue component and a single
MetKurdV2Navigation controller. It reads a bounded local MlJob projection at an
adaptive cadence, preserves per-customer visual acknowledgements across shell
remounts, and serializes outgoing/incoming reads. Workspace submission dispatches
`metkurd:job-submitted`; opening a result uses the existing workspace with an
authorized `queue_job` selection. See [Process Queue](PROCESS-QUEUE.md) for read
boundaries, limits and verification. GPU reconciliation ownership is unchanged.

## STEM result mixer — 2026-09-21

`v2-stem-player.js` owns each keyed, `wire:ignore` result. The existing STEM
controller mounts it once per DOM node and destroys it only when that result
is removed or navigation disposes the workspace. Its listeners, animation frame,
buffer sources, gains, decoded references and AudioContext are released together.
The shared navigation registry and FilePond/source-upload controller are unchanged.

Source investigation found Mute/Solo already used client listeners, not Livewire
actions. Reconciliation already preserved connected result nodes. The problematic
paths were repeated 50 ms drift-correction seeks, abrupt volume changes, and the
cross-origin error fallback that destroyed/recreated players. The original also
played together with the stems, doubling the mix. These are source findings;
the reported production stack/audio was not independently reproduced.

WaveSurfer still fetches/draws each track once. Its full-rate decoded buffer is
reused on one AudioContext: every source starts at the same scheduled time and
offset. Mute and multiple Solo controls only change gain, with a 5 ms exponential
time constant; muted preferences survive Solo. Audio continues advancing for
inaudible tracks. Play/Pause/Stop and explicit seek operate on the transport;
normal drawing never corrects audible playback with seeks. Seeking the shared
slider commits on release. WaveSurfer's silent media follows the display cursor.
The original is available for explicit comparison, excluded from normal Play All.

No mixer interaction generates a Livewire request, a new signed URL or another
load. All displayed tracks load initially (two/four stems plus the original when
present). Loading/error states gate transport instead of rebuilding players.
The browser decodes at its AudioContext output sample rate, preserving playback
bandwidth; waveform normalization affects the drawing only. Full-rate PCM costs
approximately seconds × sample rate × channels × 4 bytes per track, in addition
to compressed data and waveform resources. Long-result memory/listening acceptance
is still required; no upload limits or worker output settings were changed.

Automated STEM2/STEM4 tests cover rapid Mute/Solo, identical scheduling, explicit
seek, Stop/Play, morph preservation, navigation/remount, readiness and errors.
Both browser-control runtimes failed initialization in this session, so audible
playback, real Network-tab counts and the existing completed result remain manual
acceptance checks. `reportAllChanges` was not found in repository or installed JS;
the `startTime` error cannot be attributed without its actual script URL/stack.
The user subsequently reported smooth playback and no current error. This is
user-reported browser acceptance; exact Network-tab counts and long-result memory
usage were not independently measured. Final automated checks: 27 PHP tests / 232
assertions, all 52 frontend tests, build and scoped PHP lint/Pint passed.

## Multi-Speaker draft identity and reference selection — 2026-09-21

Segment fields now bind to `segments.<uuid>.<field>` with a separate locked
`segmentOrder` list. Reordering changes only that list; the submission boundary
reconstructs the existing ordered array. Numeric field bindings previously
survived keyed Livewire morphs with their old position, allowing edits to target
another row. Final/ordinary pause controls have separate keys. Native HTML drag
drops before the target ID and waits for the reorder request before another drag.

One shared Apollo reference panel targets the active Zeta segment. Theta reuses
Vector's paginated history/preview panel and owned preview routes. Preview plays
existing audio through MetKurdSpeakerPreview. A completed Theta temporary upload
immediately invokes the existing save method, invalidates reference reads, resets
reference pagination and advances a selector revision so options refresh in the
same response. FilePond still uses the existing scoped upload/clear/disposal path.
The transcript editor is hidden; the unchanged submission contract permits empty
`ref_text` for the worker's automatic behavior.

The two shared Omni preview/avatar routes accept existing Apollo access or Zeta
access. This lets a Zeta-only customer use the reused panel without granting
generation access to Apollo; customers with neither permission remain blocked.

Automated controller and Livewire tests cover repeated reorder, add/delete,
navigation remount, upload refresh, shared references, ordered submission and
EN/AR/KU rendering. Interactive drag, preview playback and visual RTL acceptance
remain pending: both computer-use runtimes failed to initialize in this session.

## Zeta / Theta — 2026-09-20

The native `multi-speaker` Livewire page serves explicit Zeta/Theta routes before
the generic tool route. Existing catalog links still resolve those URLs.
`v2-multi-speaker.js` registers drag sorting through MetKurdV2Pages and ctx.listen;
it sends one ordered ID list to the current Livewire owner, with server-side
permutation validation. Move-up/down buttons provide keyboard/touch alternatives.
`v2-upload.js` registers `theta-upload` through the same filePond readiness,
temporary token, current-component lookup, cancellation and disposePond lifecycle
as Vector. Saved reference state clears FilePond without another upload/revert.
No new document navigation handler or Bootstrap reload is added. Reference pool
previews and final results use the existing waveform controller. Both pages use
App EN/AR/KU messages, automatic text direction and existing TTS/CTTS colors.
Automated navigation/controller coverage passes; interactive browser acceptance
with deployed catalog records remains a release check.

Source and local browser verification: 2026-09-16. This is not production acceptance.

## Ownership

`resources/js/v2-navigation.js` installs one registry, one navigation lifecycle and
one pair of Livewire morph hooks. Initial load and `livewire:navigated` reconcile
the mounted page. `livewire:navigating` disposes the old page before Livewire's
history snapshot. Morphs update existing controllers or dispose replaced roots.

Register `{key, selector, prepare?, boot(ctx)}` through
`(window.MetKurdV2Pages ||= []).push(...)`. Registration is idempotent by key.
`prepare` may await dependencies; it cannot boot a page that has already left.
`boot` may return `{update, destroy}`. Use `ctx.root` for DOM lookup,
`ctx.component()` for the current owner and `ctx.alive()` after asynchronous work.
`ctx.listen`, `ctx.on` and `ctx.cleanup` remove DOM/Livewire listeners and resources
on disposal. Do not retain a component from a previous navigation.

## Uploads and media

`v2-assets.js` caches script-load promises and registers requested FilePond plugins
once per FilePond instance. Type and size validation are required by audio uploads;
image-preview loading is supported only when requested, not loaded for audio tools.
The existing local libraries and existing CDN sources remain in use. Failed loads
surface as setup errors, without arbitrary readiness delays.

`v2-upload.js` shares Vector/Leo/Caption temporary-upload handling. STEM keeps its
specialized preview controller but uses the same dependency and disposal helpers.
Pass Livewire's temporary upload token to FilePond and use the owning component's
`cancelUpload` path. Ignore late callbacks after disposal. FilePond replaces its
input with a same-ID wrapper: morph reconciliation must recognize that wrapper.
The global FilePond destroy operation restores/unregisters the original input
synchronously; instance destruction then releases resources. Instance destruction
alone emits deferred events and is too late for Livewire's history snapshot.

Waveform controllers release media, object URLs and listeners. OCR cancels PDF
render/load tasks, revokes URLs, unsubscribes clear/range events and checks a
revision plus live ownership before applying asynchronous preview work. Cleanup
does not write into removed preview elements.

## Shell and progress

Bootstrap, Waves and SweetAlert scripts load once through `data-navigate-once`.
`v2-shell.js` owns dropdown instances and resolves current locale form elements.
Bootstrap continues to own dropdown interaction; no second Alpine toggle is added.

The registry sets Livewire's existing `--livewire-progress-bar-color` on
`livewire:navigate` using the destination URL, including history navigation. It
does not reinitialize Livewire or change its configured fallback.

| Destination group | Color |
| --- | --- |
| text-to-speech | `#93c5fd` |
| clone-text-to-speech | `#fd9393` |
| speech-to-text | `#86efac` |
| STEM | `#fdba74` |
| OCR | `#7dd3fc` |
| storage, profile, my-billing, payments, purchase pages, API account | `#93c5fd` |
| Unknown/non-service destination | `#2299dd` |

## Verification

- 44 frontend tests pass, including repeated boot/dispose, delayed libraries,
  stale callbacks, upload cancellation, FilePond morph/history restoration and
  destination colors in EN/AR/KU.
- 29 isolated PHP workspace tests pass (220 assertions): Clone, Leo, Caption,
  STEM and OCR status. Focused PHP Pint and Vite build pass.
- Browser sequence without refresh: dashboard, Vector 1, Vector 2, Leo, Caption,
  STEM 2, STEM 4, OCR, account/language menus and return to Vector; back/forward
  and direct full loads of all seven workspaces also checked. One FilePond per
  audio workspace and no lifecycle console errors were observed.
- Actual synthetic temporary uploads were checked in Vector and Leo, including
  removal, Vector history restoration and Leo waveform readiness. A synthetic
  one-page OCR PDF rendered and uploaded. No AI generation/scan was submitted.
- Profile/language menus were checked on desktop and a 390px mobile viewport
  in EN and AR/KU RTL. Viewport and language were restored afterward.
- In-flight upload cancellation and delayed stale OCR completion have automated
  coverage; no browser network throttling was used to claim cancellation timing.

Existing profile phone-library warnings about `preferredCountries` are unrelated
to this lifecycle change and remain outside this task.
