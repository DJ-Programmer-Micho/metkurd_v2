# Maintenance / HTTP 503

Implemented and checked locally on 2026-09-26. No production maintenance or
deployment was performed; billing and cutover mutation logic are unchanged.

## Response contract

Laravel 12's `DownCommand` writes the maintenance driver payload and copies its
native maintenance script to `storage/framework/maintenance.php`. With
`--render="errors.503"`, the HTML is compiled **once**, during the command.
The public entry point runs that generated script before Composer. It checks
the file marker, exclusions, secret bypass/cookie, redirects, status and headers.
Without a rendered template it continues into Laravel's maintenance middleware.

The standalone `resources/views/errors/503.blade.php` embeds local brand artwork,
CSS and `resources/lang/maintenance.json`; it uses no shared dynamic layout.
Two tiny inline scripts select the visitor's locale/design and reload the exact
URL on Retry. Public paths use spacious Landing styling; `app`, `app-v2` and
the current Admin prefix `adm` use a compact dark panel. Matching is by exact
path segment, following an optional `en`/`ar`/`ku` prefix. EN is LTR, AR/KU RTL;
unknown locales and no-JavaScript visitors get a readable English fallback.
With JavaScript disabled, the empty relative Retry link retains the current URL.

There is no authentication, DB, cache, billing, queue, storage, provider access,
Livewire, Alpine, Bootstrap JS, external font, Vite lookup, auto-refresh or polling
in the served document. The logo is an embedded copy of
`public/app/logo/white_logo_xml/144.png`. Only local source files are read when
rendering/pre-rendering; serving the pre-rendered page does not need those reads.
The deployment must allow the page's inline scripts/styles and data image under
any edge CSP; no application CSP is weakened by this implementation.

## JSON and framework safety

`MaintenanceResponse` has no framework dependencies and is required before
Composer by `public/index.php`. It buffers **only** Laravel's early maintenance
script. On a native 503 exit it supplies the JSON body for `/api/*`, JSON Accept
headers (including `+json`), or JSON-style XMLHttpRequest requests. Otherwise
the original HTML passes through. Retry-After and status stay intact. Responses
are private/no-store and noindex; neither HTML nor JSON contains exception details.

```json
{"error":{"code":"service_unavailable","message":"MetKurd is temporarily unavailable for maintenance."}}
```

The buffer is removed if the native script returns for bypass/normal boot;
redirects and non-503 statuses remain Laravel's decision. The implementation
does not duplicate secret validation, introduce exceptions or edit vendor code.
For normal `down` and other HTTP 503 exceptions, `bootstrap/app.php` renders the
same view/envelope **before** authentication-dependent area error handlers.
Other error status handling and API authentication remain unchanged.

## Operator procedure

Use the file maintenance driver, on every serving instance, before taking
dependencies offline. Confirm the active driver/config and preserve the files
across deployments. Do not change maintenance drivers while down.

```sh
php artisan down --retry=60 --render="errors.503"
# After the approved work and readiness checks:
php artisan up
```

These exact flags are verified against the installed framework and isolated
command tests. Do not add `--redirect`, `--refresh` or a non-503 status to this
recommended procedure. Operator-only `--secret` works as Laravel implements it.
The cache driver does not provide the same DB/Redis-independent early response;
alternate entry points such as an Octane server have not been accepted here.
See [the production runbook](V1-TO-V2-PRODUCTION-RUNBOOK.md) for the full shutdown
and restoration sequence. Maintenance mode does not stop workers or callbacks
at a deployment proxy; existing operator safeguards still apply.

## Local verification

Tests use temporary storage and isolated HTTP servers, never the application's
maintenance marker or customer database. They execute real Laravel down/up,
check normal 503 rendering with DB/Redis/auth resolution deliberately failing,
and serve the generated script without even loading Composer. Thus MySQL, Redis,
workers, scheduler and external services are absent from that HTTP test process.
This is not a claim that production infrastructure was taken offline.

Coverage includes HTML/JSON 503, Retry-After, API v1/v2, JSON Accept and XHR,
native bypass issuance/delegation, valid/invalid/expired cookies, native redirects
and explicit exclusions, recovery after up, locale/design selection and exact-URL
Retry. Browser checks cover Landing and App, Arabic/Kurdish RTL, mobile sizing
and Retry. No production command is authorized by this document.

Results: all five focused maintenance cases and 67 frontend cases passed; syntax,
focused Pint, Vite build and diff checks passed. The combined maintenance/API V2/
Admin P0 run recorded 160 passing cases and one OCR fixture-file failure. That
OCR case passed separately with its own `LARAVEL_STORAGE_PATH` (31 assertions),
without changing OCR code. Shared fake-disk interference is a possible cause,
not a confirmed diagnosis. Browser checks used an isolated pre-rendered HTTP
server; Retry retained path/query and resumed its normal response after real
Laravel `up` removed that server's maintenance files. The normal application
maintenance state and production services were not modified.
