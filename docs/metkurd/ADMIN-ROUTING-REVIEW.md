# Admin routing and boot integrity review — 2026-09-06

## Result

**Route definitions: PASS. Authentication redirect: FIXED. Application database:
PENDING MIGRATION.** No accidental route removal, renaming, nesting change or
Admin/customer middleware crossover was found in `routes/web.php`.

One real, pre-existing middleware defect was reproduced: a guest requesting
`/en/adm/home` was redirected to `/app/signin`. `Authenticate::redirectTo()` always
selected customer sign-in. Its minimal repair selects `admin.signin` for named
Admin routes, preserving customer redirects and JSON/API unauthenticated behavior.
This is not a restoration or rewrite of the route file, and did not start P1.

## Working-tree classification

| Class | Change | Assessment |
| --- | --- | --- |
| A — required P0 | `EnsureAdminIsActive` added between `auth:admin` and localization on the existing localized Admin group | Correct shared active/read boundary, including the already-grouped Admin Landing routes. No mutation capability is applied to route access. |
| A — required P0 | Provider registration of capability Gates, the audit observer and persistent active-admin middleware | Registration itself does not query the new P0 tables; execution checks permissions. |
| A — required P0 | Admin sign-in requires active status; generic Admin exception boundary in `bootstrap/app.php` | Correct security behavior, subject to migration prerequisites. |
| B — valid existing work | App V2, API portal, service/download/storage routes, stable Livewire endpoints and feature gates | Retained exactly; not reverted. |
| C — unnecessary functional scope but harmless | PHP import sorting in `routes/web.php` during focused Pint | Formatting only; no route behavior changed. |
| D — actual defect, predating P0 | Shared authentication middleware sends Admin guests to customer sign-in | Fixed only in `app/Http/Middleware/Authenticate.php`; regression tests cover Admin/customer redirects, and the existing JSON/API branch is unchanged. |

The diff against Git HEAD contains only import ordering and the active-admin
middleware addition in `routes/web.php`. That file was not changed by this review.
The existing `/up` declaration also overlaps Laravel's configured health endpoint;
it predates P0, resolves to one registered endpoint, and was not rewritten.

## Route contract and inventory

Fresh, uncached Laravel inspection registered **216 routes**, with no duplicate
non-null route names. Categories below deliberately distinguish localized legacy
App routes from its unlocalized authentication endpoints.

| Surface | Count / contract | Middleware |
| --- | --- | --- |
| Admin sign-in | GET/HEAD `/adm/signin`, `admin.signin` | `web`, `guest:admin`; no active-admin gate |
| Admin logout | POST `/adm/logout`, `admin.logout` | `web`, `auth:admin`; no active-admin gate, so an inactive signed-in Admin can log out |
| Localized Admin | 22 GET/HEAD routes, including Services rules redirect and four existing Admin Landing entries | `web`, `auth:admin`, `EnsureAdminIsActive`, `LocalizationMainMiddleware` |
| All named Admin routes | 24 | Existing `admin.*` names retained |
| App V2 | 20 route definitions, including dynamic service/tool routes | Customer guard, active/verified checks, localization and `app.v2.enabled`; tool-specific access checks where defined |
| Localized legacy App | 69 | Existing customer middleware retained; no Admin gate added |
| Public Landing | 16 `landing.*` routes, plus two existing `law.*` routes | Existing public/localization stacks; no Admin middleware |

The localized Admin prefix is `/{locale}/adm`. Existing auth exceptions are
intentionally unlocalized: `/adm/signin` and `/adm/logout`. No localized sign-in
alias was invented. The routes are:

| GET/HEAD path below `/{locale}/adm` | Name |
| --- | --- |
| `/home` | `admin.home` |
| `/services/tools` | `admin.services.tools` |
| `/services/rules` | `admin.services.rules` (redirect to voices) |
| `/services/voices` | `admin.services.voices` |
| `/services/pricing` | `admin.services.pricing` |
| `/services/entitlements` | `admin.services.entitlements` |
| `/customers/list` | `admin.customers.list` |
| `/customers/ranking` | `admin.customers.ranking` |
| `/customers/register` | `admin.customers.register` |
| `/customers/phone-countries` | `admin.customers.phone-countries` |
| `/customers/usage` | `admin.customers.usage` |
| `/customers/suspended` | `admin.customers.suspended` |
| `/packs/plans` | `admin.payments.plans` |
| `/packs/addons` | `admin.payments.addons` |
| `/packs/storage` | `admin.payments.storage` |
| `/packs/coupons` | `admin.payments.coupons` |
| `/packs/methods` | `admin.payments.methods` |
| `/packs/currencies` | `admin.payments.currencies` |
| `/landing/translations` | `admin.landing.translations` |
| `/landing/tools` | `admin.landing.tools` |
| `/landing/contact` | `admin.landing.contact` |
| `/landing/meta-settings` | `admin.landing.meta` |

Specific V2 OCR, STEM 2/4, Storage and API portal paths resolve before generic
service/tool patterns. Public Landing paths resolve independently. The web V2
gate does not become an Admin gate; the API V2 machine gate remains separate.

## Middleware and pending migration

`EnsureAdminIsActive` is registered by its class name, so no alias is required.
It authorizes `admin.read`; mutation capabilities remain in final handlers.
Livewire's persistent middleware list includes the active-admin middleware and
the custom authentication middleware. A real HTTP Livewire update using a signed
snapshot was denied after its Admin became inactive. The update endpoint retains
`web`, localization and Livewire's required-header middleware.

The P0 migration is not applied to the application database. The designated V1
dump has neither `users.status` nor `users.admin_capabilities`, and has no
`admin_operations`/`admin_audit_events` tables. This means:

- Application boot and route listing succeed without these tables.
- GET `/adm/signin` remains renderable.
- An authenticated account without the new active-status field fails closed on
  protected Admin pages. A credential attempt uses `status = 1` and will fail at
  the database until that column exists.
- Mutation capability provisioning and operation/audit writes require the P0
  migration. Active accounts then default to read/support, not mutation access.

Classify those conditions as **PENDING MIGRATION**, not **ROUTES DAMAGED**.
See the dump-specific [import and migration plan](PRODUCTION-DB-IMPORT.md).
No migration or seeder was executed against the application database in this review.

## Known runtime/localization distinctions

- **P1 remains open:** `LocalizationMainMiddleware::detectArea()` recognizes
  `/{locale}/super-admin`, while actual pages use `/{locale}/adm`. It selects the
  wrong area catalog, but does not prevent route registration. No localization
  redesign was made. Admin/customer locale parameters remain as previously defined.
- The Admin header expects a related `profiles` row. A synthetic user without
  one failed rendering with `Attempt to read property first_name on null`; the
  designated dump contains one Admin and its profile. Route tests supply that
  existing account contract. No header/UI change was made.
- The existing customer-usage query uses MySQL `CONCAT`. SQLite lacks this in the
  available PHP build. The route test emulates that one function in memory;
  production SQL and the customer query were not changed.
- `bootstrap/cache/config.php` exists; no route-cache file was present. Inspection
  used isolated config/route-cache paths and did not clear or rewrite the real
  cache. Cached environment settings can remain stale independently of routing.

## Verification and files

- `php -l routes/web.php`: passed.
- Fresh isolated application boot and `artisan route:list --json`: passed with
  SQLite `:memory:` before any application schema was installed.
- `AdminRouteIntegrityTest`: 31 cases passed, 479 assertions. Includes all 17
  scoped Admin read pages, guest redirects, inactive logout, missing-P0-schema
  behavior, middleware separation, duplicate-name detection, specific path
  matching, and an actual Livewire update after status revocation.
- Final combined route/P0 safety run: **79 passed, 670 assertions**. Focused Pint
  and PHP syntax checks passed. Final fresh route inspection retained all 216
  definitions with zero name duplicates or changes to route/middleware contracts.
  Git diff whitespace checks passed; existing line-ending warnings remain.
- All database fixtures were isolated; HTTP/mail/storage were faked. This is not
  browser visual acceptance or a real MySQL import rehearsal.

Runtime repair: `app/Http/Middleware/Authenticate.php` only. Added route regression
tests and this review/import documentation. Existing P0/V2 working-tree changes
were preserved. Billing findings in the dump are documented separately and are
not fixed or reclassified by this routing review. P1 remains unstarted.
