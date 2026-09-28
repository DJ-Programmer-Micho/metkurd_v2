# Final Admin consolidation and production acceptance

Date: 2026-09-28. This inventory supersedes the navigation inventory in Phase 1;
the dated Phase 1–7 reports remain historical implementation/verification evidence.
Source readiness is not permission to deploy or run migrations.

## Consolidation decisions

- Retain all routes and source pages. There are no duplicate sidebar destinations.
  Customer directory (profile/status), Register (guarded actions/agreements) and
  Customer Detail (owned evidence) have distinct responsibilities.
- Move the existing processing-jobs navigation item into Developer and use its
  existing translated “Processing jobs” label. API/MCP/reservations remain adjacent.
  Technical/System retains audit, ledger and reference configuration.
- Operations browser titles now identify the selected section. Customer Detail
  retains its customer title plus section. Breadcrumbs follow the same route/section
  navigation descriptors and the existing Livewire lifecycle.
- Eight remaining page-local modal bridges (directory, add-ons, storage plans,
  coupons, methods, currencies, contact and landing tools) now use `admin.js`.
  Their original event names, IDs and guarded actions are unchanged. One shared
  owner handles disposal/focus/navigation; dashboard chart lifecycle is distinct
  and remains intact. The separate guest sign-in layout is not an Admin modal owner.
- Completed processing has one English label. Revoked/deletion-failed states are
  danger, deleted is neutral, settled/released are successful financial terminal
  states; pending processing remains warning. Color does not replace status text.
- Coupons are explicitly Legacy in heading/title and explain that V2 checkout
  does not accept coupon codes. Existing coupon data/controls remain untouched.
  Configuration headings/titles reuse the navigation labels for credit add-ons,
  storage/service plans, methods and currencies. Currency selectors and status/
  visibility switches gain accessible names; configuration action rows wrap.
- Long dialogs whose header is outside the form now scroll their fieldset as one
  unit. This fixes an observed clipped mobile footer without changing disabled
  fieldsets, form actions or authority. Other modal structures keep body scrolling.
- Customer Detail's retained recent-job component now recognizes explicit MCP
  connection metadata; missing API key is never MCP evidence. MCP remains API wallet.
- Audit lists project operation status in their existing query, avoiding a full
  AdminOperation load per row. Voice plan availability resolves each plan once
  per displayed page, using the same authoritative speaker catalog and without
  persistent caching. Existing runtime eligibility/invalidation is preserved.

## Final route/page inventory

Paths below are relative to `/{locale}/adm` unless stated otherwise. EN/AR/KU
remain supported. “Supporting detail/reference” includes configuration controls;
classification does not grant or remove mutation authority.

| Route name | Path | Classification | Purpose/navigation |
|---|---|---|---|
| `admin.home` | `/home` | Primary operational page | Dashboard |
| `admin.customers.list` | `/customers/list` | Primary operational page | Customer directory/profile/status |
| `admin.customers.register` | `/customers/register` | Primary operational page | Guarded customer actions and agreements |
| `admin.customers.detail` | `/customers/detail/{customer}` | Supporting detail/reference | Primary owned-customer evidence workspace; contextual links |
| `admin.customers.ranking` | `/customers/ranking` | Supporting detail/reference | Customer analytics |
| `admin.customers.usage` | `/customers/usage` | Supporting detail/reference | Usage analytics |
| `admin.customers.suspended` | `/customers/suspended` | Supporting detail/reference | Suspended-account view |
| `admin.customers.phone-countries` | `/customers/phone-countries` | Supporting detail/reference | Phone-country configuration |
| `admin.services.tools` | `/services/tools` | Primary operational page | Services and actions; current/legacy distinction |
| `admin.services.voices` | `/services/voices` | Primary operational page | Voice catalog/access controls |
| `admin.services.entitlements` | `/services/entitlements` | Primary operational page | Plan/channel access and enforced limits |
| `admin.services.pricing` | `/services/pricing` | Primary operational page | Existing service prices |
| `admin.services.rules` | `/services/rules` | Redirect/compatibility | Existing localized redirect to Voices; no sidebar duplicate |
| `admin.payments.plans` | `/packs/plans` | Primary operational page | Service plans |
| `admin.payments.addons` | `/packs/addons` | Supporting detail/reference | Credit add-on configuration |
| `admin.payments.storage` | `/packs/storage` | Supporting detail/reference | Storage plan configuration |
| `admin.payments.coupons` | `/packs/coupons` | Legacy/history | Retained coupon configuration/history; explicitly Legacy |
| `admin.payments.methods` | `/packs/methods` | Supporting detail/reference | Payment method configuration |
| `admin.payments.currencies` | `/packs/currencies` | Supporting detail/reference | Currency configuration |
| `admin.landing.tools` | `/landing/tools` | Primary operational page | Public product/editorial controls; effective catalog visibility |
| `admin.landing.translations` | `/landing/translations` | Supporting detail/reference | Public copy overrides, not the retired Translation product |
| `admin.landing.contact` | `/landing/contact` | Supporting detail/reference | Public contact/social configuration |
| `admin.landing.meta` | `/landing/meta-settings` | Supporting detail/reference | Public metadata/assets, not application secrets |
| `admin.operations` | `/operations/jobs` | Primary operational page | Shared operational sections listed below |
| `admin.signin` | `/adm/signin` (unlocalized) | Supporting detail/reference | Admin authentication/Turnstile |
| `admin.logout` | POST `/adm/logout` (unlocalized) | Supporting detail/reference | CSRF-protected session action; not a page |

There are 24 authenticated route names: 23 page paths sharing 22 Livewire page
components, plus the Rules redirect. Sign-in and logout bring the total to 26.
The agreement Blade file is an included Register partial, not a missing route or
duplicate screen. Header/footer/navigation/layout/component files are not pages.

### Existing Operations sections

These query sections also appear in owned Customer Detail. They do not introduce
new routes or independent lifecycle implementations.

| `section` | Classification | Scope/authority |
|---|---|---|
| `jobs` | Primary operational page | MlJob processing evidence; Developer navigation |
| `api` | Primary operational page | API/MCP activity and typed origin traces |
| `mcp` | Primary operational page | Safe MCP connection metadata |
| `keys` | Supporting detail/reference | API key metadata only |
| `reservations` | Primary operational page | Persisted API credit reservations |
| `files` | Primary operational page | Owned file/result metadata |
| `payments` | Primary operational page | Current billing evidence; explicit Legacy selector |
| `subscriptions` | Primary operational page | Effective service subscription evidence |
| `storage_subscriptions` | Primary operational page | Storage subscription evidence |
| `orders` | Primary operational page | Credit orders; explicit Legacy selector |
| `review` | Primary operational page | Existing processing/reservation/payment queues; guarded reconciliation handoff |
| `ledger` | Supporting detail/reference | Continuous credit history; customer/job/payment scope |
| `audit` | Supporting detail/reference | Immutable historical Admin evidence |
| `entitlements` | Supporting detail/reference | Customer's plan evidence; no duplicate global editor navigation |

`financialEra=legacy` on applicable financial sections is **Legacy/history** within
the same route. Current reads keep BillingReportingBoundary. Retained jobs, files,
ledger/audit and expired/legacy plan records are evidence, not new current products.
Translation, Delta, NEO, Apollo 1.0 and Vector 1.0 remain excluded from current V2
public families. No rows were deleted or rewritten to achieve these classifications.

## Cross-links, privacy and query review

The existing tests cover customer→actions/agreement, service→pricing/entitlement,
payment→subscription/order/ledger/audit and API/MCP→job/reservation/result traces.
Typed developer IDs validate customer ownership, including empty results. Billing
links carry the reporting era. Rules redirects preserve locale. No dead named
destination or duplicate route was found; no route/source deletion is warranted.

Normal operational views keep allowlisted metadata. Private text, OCR/transcription
content, voice references, OAuth/key material, provider response bodies, paths and
signed result URLs are excluded. Existing explicit financial evidence gates remain
separate from ordinary support reads. Public Landing asset editors intentionally
display public asset URLs; these are not private customer result routes.

Developer rows remain paginated at 25 with batched related projections. Customer
overview retains bounded latest-five job and latest-three audit reads; older P2
per-job evidence queries remain bounded there rather than being expanded into
unbounded lists. Financial evidence pages retain ten-row event/allocation pagination.
Voice matrix lookup and audit operation hydration duplication are corrected above.
No speculative indexes or persistent cross-customer caches were added. Runtime
resolution is still authoritative; SQLite tests cannot establish MySQL query plans.

## Verification results

Tests use SQLite `:memory:`, array cache/session, mocked HTTP/mail and fake storage.
The application database is not the test database. Local logs/screenshots belong in
ignored `storage/app/private/admin-consolidation/`, not source-controlled docs.

- Combined `tests/Feature/Admin` run: **414 passed, 12 initially failed**, 4,925
  assertions. Eight failures exposed a Voices Blade compilation error introduced
  during this consolidation; it was corrected by using a block `@php` directive.
  One coupon assertion still expected its old title and now checks the Legacy
  heading/help. All nine corrected failures passed in the affected suite reruns.
- Final affected suites: **74 passed / 1,810 assertions** across
  `AdminConsolidationTest`, `AdminDeveloperWorkspaceTest`, `AdminServiceWorkspaceTest`,
  `AdminP3UiTest`, `AdminRouteIntegrityTest` and `CouponAdminTest`. After the last
  heading alignment, consolidation reran: **7 passed / 181 assertions**.
- **Three existing failures remain** in `AdminNavigationCompatibilityTest`:
  the retained customer V1 Profile/Billing shell test expects 200 but receives 302
  in EN/AR/KU. These concern customer application routes, not Admin destinations;
  their redirect/authentication policy was not changed to make this audit green.
  The complete Admin directory is therefore not reported as an entirely green run.
- All **41 Admin frontend tests passed**, including shared modal ownership,
  navigation/focus cleanup and EN/AR/KU translation key/token parity. Vite build,
  focused Pint (10 files) and whitespace checks passed.
- Real local browser samples: Arabic Developer/API title and breadcrumbs; navigation
  into add-ons; EN and KU tablet at 768×1024; AR mobile at 390×844. Language and
  direction matched, with no document horizontal overflow. English tablet modal
  had one dialog and one backdrop. Cancel removed both, without a save.
- The AR mobile footer initially extended below the clipped fieldset. After the
  scrolling correction, keyboard focus reached the final action and the entire
  footer was visible (bottom about 835px within an 844px viewport). Its accessible
  title, currency selector and status names were verified in the browser.
- Browser samples used the built asset bundle because the local Vite development
  server intermittently failed asset requests. The existing `public/hot` marker was
  restored afterward; viewport override was reset. No environment settings changed.
- Empty-state, ownership, secret-exclusion, cross-link, fresh-authority and bounded
  query assertions pass in the focused suites. Populated real API/MCP browser
  chains and the complete operator breakpoint/keyboard matrix remain below.

**Source conclusion: ADMIN SOURCE READY — PRODUCTION ACCEPTANCE REQUIRED.**
No routes, Gates, AdminOperationRunner, pricing/entitlement/agreement or financial
policy, API/MCP authority, job lifecycle or historical records were changed. No
migrations, deployment, production connection or live financial mutations occurred.

## Final production acceptance checklist

- [ ] **Native MySQL/RDS query plans:** identify production version; use an approved
  non-production native MySQL instance with representative data. EXPLAIN filtered
  dashboard, directory, billing, Developer JSON/subqueries, audit and voice queries;
  record latency/row counts. XAMPP MariaDB does not satisfy this requirement.
- [ ] **Populated real API/MCP browser chain:** authorized test customer/key/connection
  → ApiJob → MlJob → reservation → authenticated persisted result → audit. Include
  failure/retained reservation/expiry and cross-customer negatives without blind retry.
- [ ] **Disposable-Admin permission revocation:** identify an approved disposable local
  Admin, open a guarded control, revoke its relevant deeper capability, verify stale
  submission is rejected without writes, restore through audited authority. Do not
  change the current signed-in Admin merely to complete this check.
- [ ] **Final production environment/configuration:** operator verifies release artifact,
  backups/restore, existing schema readiness, Admin accounts/capabilities, URLs/proxies,
  Turnstile, OAuth keys, shared Redis/session/cache, queues/scheduler, storage/retention,
  logging/redaction and intended feature gates. No secret values belong in this report.
- [ ] Operator signs off representative EN/AR/KU desktop/tablet/mobile flows, keyboard
  drawer/modal focus, validation/error recovery, later pagination, customer context
  and browser Back/Forward. Source/browser samples are not an exhaustive live matrix.
- [ ] Confirm existing pricing/entitlements, agreements, billing boundary and financial
  authority with business owners; this consolidation changes none of those policies.

No deployment, migration, production connection, permission change, financial action,
provider execution or destructive cleanup is authorized by this checklist.
