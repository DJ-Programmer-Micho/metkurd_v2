# Localization

## Responsive FIB payment controls — 2026-09-13

Payment V2 EN/AR/KU catalogs now include QR/app instructions, readable code, copy
feedback and countdown wording. Code/time retain LTR isolation in RTL layouts.
See [PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md) for responsive fixture checks
and provider-testing limits.

## V2 payment status — 2026-09-10

`resources/lang/{en,ar,ku}/payment_v2.php` provides checkout state explanations,
review/live blocker text, timeline, summary, safe continuation and return actions.
The V2 shell retains locale/direction; dynamic purchase names use automatic
direction and amounts/dates remain isolated. Native raw provider errors are omitted.
The shared policy also supplies read-only V2 billing-history state labels.
See [PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md) for browser verification limits.

## V2 purchase pages — 2026-09-10

Subscription, storage and add-on presentation uses matching
`resources/lang/{en,ar,ku}/purchase_v2.php` catalogs and existing account/App copy.
Mode/interval, reviews, credit effects, cancellation, unavailable states and storage
limitations are translated. V2 styling uses logical alignment and automatic
direction for catalog names; amounts/technical inputs retain isolated direction.
EN desktop, AR mobile/RTL dropdown and KU mobile add-ons were checked in the
signed-in local browser. Provider checkout remains shared. See
[PURCHASE-V2.md](PURCHASE-V2.md) for scope and remaining domain limitations.

## V2 customer account pages — 2026-09-10

Profile and Billing use matching `resources/lang/{en,ar,ku}/account_v2.php`
catalogs alongside existing App JSON account/validation copy. Shared OTP and phone
country labels retain their existing translations. EN is LTR, AR/KU RTL; email,
phone, dates and identifiers are isolated LTR, names use automatic direction.
The V2 billing paginator supplies translated previous/next/page-count labels
without changing V1 pagination. Desktop EN and mobile AR/KU pages were checked
in the signed-in local browser; mutation and delivery coverage remains isolated.

## Admin P2 operational copy — 2026-09-06

New Operational/customer detail labels use matching EN/AR/KU admin_p2.php catalogs.
They retain the P1 Admin-area loader and EN/LTR, AR/KU/RTL document direction.
Customer names, filenames and reasons use automatic direction; technical identities
remain LTR. Operational lifecycle values have translated labels when known, with
persisted technical codes retained where appropriate. Full Admin localization and
visual RTL redesign remain P3; Landing catalogs are unchanged.

## Admin P1 area correction — 2026-09-06

TranslationArea recognizes Admin route names and the configured `aurl` prefix
(currently `adm`), shared by middleware and AreaJsonTranslations. Cached area JSON
is replaced so prior Landing messages cannot leak into Admin; common JSON and PHP
catalogs remain. Livewire persistent middleware applies the verified original
route's locale/area, without trusting referrers. Direct and signed-snapshot updates
are tested for EN/AR/KU, with Landing separation and a configurable-prefix test.
Admin document direction is EN/LTR and AR/KU/RTL. New identifiers/JSON and edited
technical fields stay LTR. New `resources/lang/{en,ar,ku}/admin_p1.php` catalogs
cover the diagnostics and configuration guidance. Landing content/catalogs were
not edited. P3 full translation/RTL UX and real browser acceptance remain open.

## Workspace status follow-up — 2026-09-06

OCR distinguishes `Current job` and `Previous result` through app EN/AR/KU JSON
catalogs. The status card renders the source document with `dir="auto"`, separates
its state/stage onto distinct lines, and shows a spinner only for active work.
STEM's active-job card similarly names the current source with automatic direction.
Locale response tests passed; interactive visual RTL acceptance remains pending.

## API V2 developer portal — 2026-09-06

The /{locale}/app-v2/api portal uses the V2 layout and Storage visual conventions.
Its headings, navigation, service explanations, validation, one-time key UI and
SweetAlert revoke confirmation use resources/lang/app/{en,ar,ku}.json. Technical
blocks, headers, routes, keys and JSON remain LTR; customer key names use dir=auto.
Desktop documentation has navigation/content/code columns, collapsing to a select
and stacked panels on mobile. ApiDocumentation generates cURL/PHP/Python/Node.js
examples using YOUR_API_KEY only. The newly created secret is delivered once in
a Livewire event, held in Alpine memory, and cleared on dismissal/navigation; it
is never a public Livewire property or substituted into documentation examples.
Machine API errors are stable English JSON codes/messages without locale prefixes.


## Previous core-review scope — 2026-09-06

V2 customer workspaces and Storage are the current localization/UI target.
Shared locale/catalog/asset infrastructure is acceptable. V1-only service copy
and UI modernization are deferred. Preserve EN/LTR, AR/RTL, KU/RTL and safe
customer-facing provider wording; do not preserve obsolete V1 notification
behavior merely because V2 once reused its view.


MetKurd UI locales are **en (English, LTR), ar (Arabic, RTL), ku (Kurdish Sorani,
RTL)**. Worker language `ckb` and UI locale `ku` have distinct contracts.

## Translation sources and resolution

The main source is `resources/lang/*`. The area system has nine JSON catalogs:
`{app,admin,landing}/{en,ar,ku}.json`. Hardening also added
`{en,ar,ku}/validation.php` for the rules and field labels used by the service
workspaces and shared uploads; AppServiceProvider adds this framework loader path.
Always enumerate the tree again when changing customer text; inspect all relevant
English/Arabic/Kurdish PHP and JSON files, including newly added catalogs.

`LocalizationMainMiddleware` resolves route locale, then session `applocale`, then
app default, and adds the area's JSON path to Laravel Lang. Both `/app` and
`/app-v2` use app catalogs; the configured Admin prefix uses admin; other routes use landing.
`AppServiceProvider` registers locale middleware for Livewire updates and as
persistent middleware. Verify locale/area on update requests as well as page GETs.

`AreaJsonTranslations` separately reads resource JSON with an in-process cache,
supports flattened groups, and can be passed explicit area/locale. Landing
translation management has its own support classes. Keep area detection aligned
with middleware when adding routes. Missing JSON keys can render their English
source text, so equal catalog key counts alone do not establish coverage.

The audit aligned this helper's `/app-v2` detection with the middleware. V2 now
loads bundled SweetAlert assets and `app/v2/partials/notifications.blade.php` for
alert events; popup direction comes from the document and settings refresh on
navigation. V1 continues using its Toastr bridge and ignores events on V2 pages.
`CustomerFacingError` translates known catalog errors at V2 render boundaries and
uses a localized generic fallback for unknown diagnostics. Do not persist a
locale-specific cache of these display strings across customers/locales.

## Authoring conventions

Use existing `__()` / `@lang` conventions and preserve existing keys where callers
depend on them. Add matching EN/AR/KU values with identical replacement tokens
(e.g. `:count`, `:message`), punctuation intent and escaping. Do not introduce
hardcoded English into Blade/PHP/JavaScript to bypass the translation system.

| Text surface | Convention |
|---|---|
| Titles, descriptions, instructions | Translate the complete phrase; inherit UI direction and use logical alignment. Catalog config descriptions passed through `__()` also need entries. |
| Labels, buttons, result labels | Translate captions and accessible labels, including icon-only controls, upload state, pagination and download actions. Product/format identifiers may intentionally remain unchanged. |
| Alerts and errors | Translate message and dialog buttons in the active area/locale. Do not inject raw provider exceptions into a customer alert. Preserve placeholders, but do not treat arbitrary provider text as a safe translated placeholder. |
| History and empty states | Translate headings, status labels, dates where supported, pagination, confirmations and no-results instructions. User filenames/text are data, not keys to machine-translate. |
| Tooltips and modals | Translate title/body/close/confirm/cancel and aria text. Inherit document direction; do not duplicate RTL modal implementations. |
| Generated/result text | Preserve actual output language. Use `dir="auto"` when content can differ from UI language, or an established explicit source/target language helper when language is known. |
| Mixed-direction content | Use `dir="auto"`/`bdi` for user text and filenames; isolate IDs, times, URLs and numeric technical tokens with LTR where necessary. Avoid forcing an entire translated sentence LTR just because it includes a number. |

V2 layout sets html lang/dir from locale and uses existing application assets.
Leo/Caption/OCR results already use auto direction; Translation has a language
direction helper. Apollo/Vector editors now use dir="auto" so generated/user
content can differ from the UI language. Storage filenames, breadcrumbs and
result content also use auto direction; numeric bytes are isolated.

## Provider terminology and SweetAlert audit

Search case-insensitively for RunPod throughout **customer-visible values and
callers**, not only key names. Existing app keys containing provider/config names
can remain compatibility keys when translated values say GPU/processing server.
Internal RUNPOD_* env names, endpoint keys, provider metadata and admin JSON
configuration examples must retain their technical meaning.

Inspect the app layout notification bridge, V2 layout integration, direct STEM
SweetAlert upload notification, service alert dispatchers and JS upload/copy
handlers. EN/AR/KU message/button availability and popup direction must be
checked together; translated text alone does not guarantee the alert is wired.

The initial inventory found 1486 keys per app catalog, 766 per admin catalog,
985 English/Arabic landing keys and 986 Kurdish landing keys. No customer-facing
app/landing **values** contained the provider name, but untranslated service
exceptions and missing V2 keys can still leak it through fallback. Four Kurdish
configuration-error values contained corrupt `PH_0__` artifacts. These counts
describe the pre-audit snapshot, not a required target count.

See [PRODUCTION-AUDIT.md](PRODUCTION-AUDIT.md) for audit changes, coverage and
remaining verification. Translation inventory checks do not substitute for a
native-speaker review or actual browser inspection of dynamic dialogs.

## Confirmation and exception boundary (2026-09-05)

V2 action confirmations use data-v2-confirm and the shared SweetAlert helper;
storage bulk confirmation uses that helper as well. The listener is installed
once across navigation, uses localized Confirm/Cancel, and sets popup direction
from the document. Livewire request failures use the same notification bridge;
inline validation remains inline. Required/file/MIME/size/type messages and
service field names now have EN/AR/KU framework translations, verified through
the actual validator. This is coverage of these workspace rules, not a claim
that every unrelated framework validation rule has been translated. Customer-facing unhandled exceptions are
sanitized even with app.debug enabled, and raw errors are sanitized before
assignment to public V2 component error state. Internal provider identifiers
and admin catalog examples remain intact.

EN/AR/KU rendered response checks passed during hardening. Actual desktop/mobile
browser verification was blocked by the browser tool (ERR_BLOCKED_BY_CLIENT on
both local hostnames), so visual dialogs and responsive behavior remain an
explicit acceptance check rather than a claimed browser pass.

## V2 final review — 2026-09-06

Deletion states have EN/AR/KU catalog labels. STEM persistent script reads messages
from the current page after navigation; upload feedback uses the shared SweetAlert
bridge and generic translated errors. OCR correction has an explicit accessible
label. Actual mobile/RTL/browser locale-switch acceptance remains outstanding.

## Admin P3 — 2026-09-07

Scoped Admin layouts/navigation, Services, Customers, Operations, Billing and shared
components use `resources/lang/admin/{en,ar,ku}.json` plus the existing P0/P1/P2 and
new `admin_p3.php` catalogs. Landing CMS catalogs were not changed. Admin-only JSON
overrides also localize shared pagination text and previous/next labels. Dashboard
chart labels are serialized with each page's chart data and read again after navigation;
the persistent chart script does not capture the first page's translated labels.

The Admin Vite entry reads dialog messages from the current shell's `data-admin-ui`
settings and document direction. It is the single high-impact confirmation bridge;
Toastr remains for escaped lightweight notifications and validation remains inline.
The shared template uses EN/LTR and AR/KU/RTL with scoped Bootstrap direction fixes,
LTR technical fields and automatic direction for customer content. Native/provider
identities are retained; UI copy must not reinterpret a provider completion as locally
persisted output or a reservation as final spend.

P3 tests render 19 routes per locale, check catalog replacement tokens, form labels,
pagination, chart label data and mixed-direction output. Node tests cover current-locale
dialog settings and navigation deduplication. This does not establish native-speaker
quality or interactive desktop/mobile RTL acceptance; both remain explicit follow-ups.
