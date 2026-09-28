# Admin acceptance and accessibility — Phase 5

Source and isolated acceptance review on 2026-09-28. This phase does not change
financial policy, workflows, feature flags or historical/application data. No
deployment or application migrations were performed. Native MySQL and interactive
browser acceptance remain open; this document is not production approval.

## Findings and fixes

- Customer Detail's latest-payment summary used `first(columns)` on an Operations
  builder that already selected `payments.*`. Eloquent retained the broader
  selection and customer eager load. The summary now explicitly replaces the
  selection with its ten intended fields and removes eager loads. The regression
  test checks the exact projection and excludes benign private-payload sentinels.
  This was excess hydration/projection; the existing visible template did not
  display the sentinel payload in the initial test.
- Programmatically opened Bootstrap modals now remember their opener and restore
  focus on close. Navigation invalidates the old opener; a removed opener falls
  back to the main region. Bootstrap continues to own ordinary modal trapping and
  Escape behavior. The existing single Admin bridge owns these listeners.
- SweetAlert permission/error dialogs temporarily suspend the underlying Bootstrap
  trap. The trap resumes after dismissal; request completion cannot steal focus
  while the alert remains open. Validation summaries receive focus after Bootstrap
  reactivation, which otherwise steals focus back to the modal container.
- The mobile sidebar has a named modal-dialog role while open. Header, main content
  and skip link become inert until close; prior inert state is restored. Existing
  Tab cycling, Escape dismissal and trigger focus return remain in place.
- Scrollable tables receive keyboard access and a caption-based or translated
  region name unless already explicitly labelled. The existing visible-focus style
  also covers tabindex regions. Plans' currency selector and activation switches
  now have translated accessible names. No new accessibility dependency was added.

EN/AR/KU copy uses existing catalogs and retains RTL. The visual system, responsive
breakpoints, modal geometry, financial buttons and native action methods are unchanged.

## Acceptance evidence and limits

| Area | Automated/source evidence | Interactive status |
| --- | --- | --- |
| Dashboard, Customer Register/Detail, Tools, Entitlements, Pricing, Plans, Voices, Payments, Subscriptions, Orders, Review, Audit | Representative HTTP renders in EN/AR/KU; direction, label targets, button/control names, no database writes or provider calls | Not visually accepted |
| Tables, sidebar, long modals | Focus lifecycle tests; existing bounded scrolling, logical RTL positioning and reduced motion reviewed | Desktop/tablet/mobile, zoom, sticky headers and dropdown placement still need a browser |
| Modal validation and permission dialogs | Focus ordering, dismissal/return and stale navigation covered in Node fixtures | Actual Bootstrap/SweetAlert interaction and screen reader behavior still need acceptance |
| Customer/payment/subscription/allocation/ledger/audit traces | Existing billing/P2 tests cover owned traces, foreign-customer rejection, exact queue counts, current/legacy boundaries and independent evidence pagination | Real clicks and browser Back/Forward still need acceptance |
| Locale changes | Real HTTP locale-switch redirect preserves selected customer/payment and evidence page parameters | Browser history restoration remains unverified |
| Stale permissions | Open existing review modal, revoke finance or reconcile, receive 403; payments, wallets, ledgers, orders, operations and audits unchanged; no HTTP sent | Live browser message tested through frontend fixtures, not visually |
| Empty/error states | No agreement/payment/audit, disabled API/MCP, empty financial lists/evidence and missing record rejection; existing reason/validation/restricted-evidence tests | Visual readability remains unverified |

Browser automation failed before connecting: local kernel-asset initialization
reported a missing path (OS error 3). No screenshot or visual/mobile acceptance is
claimed. Static checks and simulated DOM tests do not replace real browser testing.

## Database and query review

The user approved the local XAMPP database for inspection. A guarded local-only
read of `VERSION()` and `@@version_comment` identified **10.4.28-MariaDB**, not
native MySQL. No EXPLAIN, production connection, application mutation or migration
was performed. phpMyAdmin is the database administration interface; the engine
identity determines whether this fulfills native MySQL acceptance.

The following findings are from source and migration definitions, not measured
native query plans or verified deployed indexes:

| Read | Existing safeguards | Performance concern to measure on native MySQL |
| --- | --- | --- |
| Customer Register | Eager-loaded customer relations and bounded existing pagination; joined-date comparison uses a direct date range | `%term%` searches across relations, correlated aggregates and created-at/username sorting; repeated effective-plan evaluation |
| Customer Detail | Five recent jobs, three audits, count queries; latest payment now selects only intended summary columns | Overview/workspace and access resolvers repeat some state/eligibility reads |
| Services/entitlements and plans | Catalog actions, plans and entitlement provenance loaded in batches | Runtime access/limit previews run per plan/action/channel; underlying resolvers may repeat queries; retain runtime authority |
| Voices | Existing catalog resolver and owned preview configuration | Plan enumeration and catalog access resolution per visible voice can multiply work |
| Grouped pricing | Compact rows streamed in chunks of 250; only visible groups hydrate relations | All matching compact rows are still indexed/grouped/sorted in PHP; memory/work grows with rule-group count |
| Payment review | Bounded 25-row list; batched customer names; no event history per list row | JSON/OR review predicates, CASE classification and priority sorting may require scans/filesorts; compare default, filtered and legacy plans |
| Subscription effective state | Visible classifications batch customer IDs and reuse `effectiveAt` | BillingSubscriptionAuthority separately examines eligible local grants and non-recurring paid coverage before the outer query, potentially across the dataset; repeated scope calls multiply this existing cost |
| Selected payment/allocation evidence | Ten items per independent page, selected safe fields, fresh finance/reconcile gate | Verify count and descending-ID plans at realistic histories; no full payload/event hydration |

Existing migration definitions include customer username/email/status indexes,
profile customer uniqueness/country indexes, payment `(customer_id,status,created_at)`
and `(purchase_type,payment_mode,status)` indexes, subscription
`(customer_id,status,cycle_ends_on)`, payment-event `payment_id`, and allocation
foreign keys plus unique subscription/payment cycle keys. Their presence alone
does not establish that a filtered/sorted query uses them. No missing index was
proven, and **no index migration was prepared**. In particular, a new index cannot
remove PHP grouping or repeated eligibility scans by itself.

For native acceptance, use an approved local/staging MySQL version matching the
deployment and sanitized representative data. Capture SELECT statements and safe
fixture bindings for the reads above, then review ordinary EXPLAIN plans, rows,
chosen keys and sorting. Compare empty/default/search/customer/plan/current/legacy
filters and both first/later evidence pages. Record actual indexes and query counts;
do not run reconciliation, settlement or provider calls to obtain this evidence.
Any optimization of shared eligibility authority requires separate lifecycle coverage.

Operations retain 25-row lists and ten-row selected evidence pages. Existing
Register and grouped-pricing page sizes are not standardized or changed here.

## Verification

The Phase 5 acceptance suite, earlier shell/customer/service/billing suites and
capability/reason/audit regressions run with SQLite `:memory:`, array cache/session,
fake storage and mocked HTTP. No test uses the application database.

- Broad 16-file run: 259 passed, six failed, 3,348 assertions (435.67 seconds).
  Three failures were new empty-state fixtures: Admin-created customers correctly
  already had creation audits. Those cases now create an unprovisioned synthetic
  customer without model events instead of assuming real audit evidence is absent.
- Final Phase 5 suite: **10 passed, 226 assertions** (44.82 seconds). Counting
  repeated cases once gives **262 passing focused PHP cases** across the runs.
- The remaining **three existing V1 navigation cases** expect HTTP 200 from retained
  Profile/Billing pages and a V1 home link in every locale. The inherited configuration
  redirects retired V1 pages. A diagnostic rerun enabled V1 only in its isolated test
  process: pages then rendered, but the assertions still expected `app.home`, whereas
  the existing CustomerAppDestination correctly prefers `app.v2.home` when V2 is
  enabled. These are legacy expectation/configuration mismatches, not new Admin
  failures. No V1 tests, routing, application flags or cutover behavior were rewritten.
  They remain reported failures, not silently counted as passing acceptance.

The 39 Admin frontend tests and Vite build passed. All 96 protected files under
the payment domain, Billing services and Admin services match their pre-Phase-5
hashes. Only the narrow Customer Detail projection changed outside that protected
set. Five-file focused Pint, PHP syntax and final whitespace checks passed.

## Phase 5 files

- `app/Support/Admin/AdminCustomerWorkspace.php`
- `resources/js/admin.js`, `resources/css/admin.css`
- `resources/views/admin/layouts/app.blade.php`
- `resources/views/admin/partials/header-one.blade.php`
- `resources/views/admin/pages/payments/⚡adm-payments-plans.blade.php`
- `resources/lang/{en,ar,ku}/admin_shell.php`
- `tests/Feature/Admin/AdminAcceptanceTest.php`
- `tests/Frontend/admin-ui.test.mjs`
- This report, `ADMIN-AUDIT.md` and `CHANGELOG.md`

Other pre-existing working-tree changes belong to earlier work and were preserved.

## Recommended Phase 6

Close the acceptance gaps before another design phase: restore browser tooling or
perform operator-assisted EN/AR/KU keyboard/visual acceptance at desktop, tablet and
mobile widths; exercise history navigation, long modals, dropdowns and revoked
permissions. Then obtain native MySQL plans and representative read-load evidence.
Prioritize any measured projection/resolver cost without changing billing authority.
Production activation remains a separate operator decision.
