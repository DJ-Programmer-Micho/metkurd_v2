# Admin redesign — Phase 1, 2026-09-27

## Scope and preserved boundaries

This is the Admin control-center shell and dashboard presentation phase. No route,
capability, financial mutation, pricing, entitlement, audit identity, feature flag,
deployment or database schema changed. Detailed business forms retain their fields,
validation, modals and action methods. The previous agreement migration remains a
separate operator step; this work does not execute it.

The sidebar formerly nested repeated headings, used microphone icons for unrelated
destinations, prioritized package configuration over operational work, and hid most
Operations sections behind one entry. The header contained substantial unused
template markup. A customer template script also cloned/rebuilt navigation and
owned sidebar behavior outside the Admin Livewire lifecycle.

## Actual route/page inventory and information architecture

All authenticated routes remain under `/{locale}/adm`. There are 24 authenticated
route names: 23 rendered page paths (22 components because Customer Detail reuses
Operations) and one redirect. Sign-in and POST logout are two additional auth routes.

| Group | Existing destinations |
| --- | --- |
| Overview | `admin.home` → `/home` |
| Customers | `admin.customers.list`, `.register`, `.ranking`, `.usage`, `.suspended` → `/customers/{list,register,ranking,usage,suspended}` |
| Services | `admin.services.tools`, `.voices`, `.entitlements`, `.pricing` → `/services/{tools,voices,entitlements,pricing}`; `admin.payments.plans` → `/packs/plans`; `admin.landing.tools` → `/landing/tools` |
| Billing | Operations `section=payments`, `subscriptions`, `orders`, `review`; `admin.payments.addons` → `/packs/addons` |
| Developer | Operations `section=api`, `keys`, `reservations` |
| Storage | Operations `section=files`, `storage_subscriptions`; `admin.payments.storage` → `/packs/storage` |
| Technical / System | Operations `section=jobs`, `audit`, `ledger`; `admin.landing.translations`, `.contact`, `.meta` → `/landing/{translations,contact,meta-settings}`; `admin.customers.phone-countries` → `/customers/phone-countries`; `admin.payments.coupons`, `.methods`, `.currencies` → `/packs/{coupons,methods,currencies}` |
| Contextual | `admin.customers.detail` → `/customers/detail/{customer}`, sharing Operations and its owned-customer context; customer-scoped `section=entitlements` remains accessible there |
| Compatibility | `admin.services.rules` → redirect `/services/rules` to existing `/services/voices` |
| Authentication | `admin.signin` → `/adm/signin`; `admin.logout` → POST `/adm/logout` |

Operations uses its existing `/operations/jobs` route with query sections. No API,
MCP, agreement or reconciliation page was invented. Customer Register explicitly
includes agreements in its navigation label and dashboard shortcut. MCP eligibility
and scopes remain in Customer Detail; its dashboard explanation appears only when
the existing MCP gate is enabled. There is no dedicated Admin MCP connection page.

`AdminNavigation` is a presentation-only route descriptor, gated by the existing
request-local `admin.read` hint. All linked pages support existing read access;
mutation visibility and final authorization remain with their existing capabilities.
No Admin ID bypass was added. System navigation is collapsed unless it contains the
active page. Exact route and Operations section matching prevent several destinations
from appearing active simultaneously. Locale prefixes survive every generated link.

## Shell, shared components and lifecycle

The header shows MetKurd Admin, page context, language selection, safe local avatar
fallback and authenticated identity. Logout submits the existing CSRF-protected POST
form. No Admin profile editor exists, so no customer-profile link is substituted.
Breadcrumbs retain the current group/page. Dynamic identity text uses automatic
direction; technical codes and amounts remain isolated.

Reusable Blade components: page header, stat card, filter panel, status badge,
action menu, information panel and empty state. The existing job table, operational
detail components, validation summary, Bootstrap modal/dialog and pagination systems
are reused. Shared CSS standardizes card borders, spacing, table headings/actions,
pagination and modal surfaces; analytics tables have bounded scroll with sticky
headings. Existing tables and business form contents are preserved.

Success/active uses green, pending/review yellow, failure/disabled red, information
blue and inactive/unrecorded gray. Badges include text rather than relying on color.
No lifecycle state or eligibility is changed by this presentation classification.

The existing single `admin.js` bridge now also owns sidebar state. The Admin layout
does not load the template menu-cloning script or its customer layout-preference
script. Bootstrap continues to own dropdowns and modals. No new framework is used.
Existing confirmations, reasons, immutable action arguments, permission errors and
Livewire disposal are preserved. Navigation/morph updates refresh selected links and
header/breadcrumb context; no per-page sidebar handler is installed.

Below 992px the sidebar becomes an overlay drawer with focus trapping, Escape/close,
focus return, inert hidden navigation and independent vertical scroll. Page navigation
closes the drawer. Desktop collapse is separate. Logical layout offsets mirror the
shell in AR/KU; EN remains LTR. Reduced motion is honored. Existing nested-form modal
scrolling fixes remain. Small-screen data tables retain horizontal scrolling.

## Dashboard data sources

Customer totals and active/suspended account counts retain the fresh directory
projection. Existing current-plan distribution, paid subscribers, credit consumption,
trends, geographic/tool activity, currency conversion and five-minute analytics cache
are retained. Revenue continues through `BillingReportingBoundary`, revenue exclusions
and the existing canonical-currency calculations; no reporting authority changed.

The dashboard adds queued jobs, jobs needing attention and payment-review counts by
calling the existing read-only `AdminOperations` query builder. This preserves its
job evidence classification and current billing boundary. Counts use the existing
five-minute cache and are labelled as stored evidence, not live infrastructure health.
The selected reporting window remains for historic volume/revenue; queue counts and
current account snapshots are not historical-window counts.

Quick links expose the existing customer agreement controls, API activity and storage
records. No guessed scheduler/queue health, global API eligibility count, live storage
warning, external agreement revenue or synthetic MCP connection metric was introduced.
These require dedicated trustworthy projections before future dashboard expansion.

## Verification and Phase 2

Focused tests cover route inventory, query-aware active navigation, all three locales,
auth/CSRF, MCP explanation gating, local queue counts, existing dashboard totals and
currency, read-only capability behavior and P0 mutation invariants. Frontend tests
cover repeated lifecycle initialization, mobile/desktop transitions, keyboard focus,
query updates, safe errors and action identity/reasons. Tests use isolated SQLite,
array session/cache and mocked external services, not the application database.

Final focused verification: **151 distinct PHP cases passed across the runs**
(AdminHomeDashboard, AdminShell, AdminP3Ui, AdminRouteIntegrity, AdminUsability,
AdminP0Safety and the four Admin-specific NavigationCompatibility cases). The final
96-case action/shell run passed 750 assertions; the separate four-case navigation run
passed 124 assertions. **24 frontend tests passed**, Vite production build passed,
nine-file focused Pint check passed, and whitespace checks passed. Earlier rendering
and renamed-label failures were corrected. The three retained V1 customer-navigation
cases exposed unrelated cutover assumptions and are not included in these Admin totals.

Browser automation could not initialize because its kernel assets were unavailable.
Responsive/RTL screenshots, long-page scroll, dropdown placement and real interactive
Livewire navigation still require browser acceptance. Native MySQL/deployment checks
were not performed. An older V1 customer shell navigation test has cutover-era redirect
and home-destination assumptions; it is outside the changed Admin shell.

Phase 2 should focus on Customer Detail and Customer Register workflows: progressively
disclose financial evidence and technical metadata, refine action grouping and review
forms, and validate the workflows interactively in EN/AR/KU. Keep business authority
and immutable action identities separate from that presentation work.
