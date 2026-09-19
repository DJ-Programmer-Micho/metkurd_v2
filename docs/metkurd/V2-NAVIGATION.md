# V2 navigation lifecycle

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
