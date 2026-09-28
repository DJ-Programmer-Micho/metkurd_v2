# Admin Phase 6 — production acceptance evidence

Date: 2026-09-28. **Partial local acceptance; not production approval.**

## Scope and environment

The in-app browser now works. Checks used the signed-in local Admin session at
`http://127.0.0.1:8000`, with desktop 1440×900, tablet 768×1024 and mobile 390×844.
The existing localhost HTTPS listener was unavailable. The user identified AWS RDS
MySQL as production but supplied neither its exact version nor an approved native
MySQL non-production instance. The guarded loopback-only engine check still reports
**10.4.28-MariaDB**, which does not satisfy native MySQL acceptance.

No production connection, deployment, migration, provider request, financial
mutation, permission change or feature-flag change was made. Existing working-tree
changes from earlier phases were preserved.

## Proven defects fixed

1. Bootstrap dialogs without an explicit accessible name now reference their visible
   translated title. The shared Admin bridge repairs its generated title association
   after Livewire replaces modal children while `wire:ignore.self` retains the parent.
   Explicit existing accessible names remain unchanged. Real Arabic validation
   confirmed the title association survives a response and the summary receives focus.
2. A long mobile Tool modal clipped its footer when Advanced was expanded. The form's
   fieldset needed its own viewport maximum to constrain its anonymous content box.
   The shared CSS now bounds that box using the existing Bootstrap modal margin and
   content borders. At 390×844, the body becomes scrollable (733px content in 646px)
   and the footer ends at 835px, inside the viewport. Keyboard traversal scrolled
   the body and exposed the Advanced field while actions remained visible.
   Arabic and Kurdish expanded mobile forms were also checked: their footer ended
   at 835px and the translated title remained correctly associated.

These are presentation/accessibility changes only. Financial and capability authority,
routes, Admin information architecture and disabled fieldset semantics are unchanged.

## Browser results and limits

All thirteen representative pages loaded through real navigation in English on
desktop. This is a page smoke check, not every interaction on every page in all sizes.

| Page | Local browser evidence | Remaining operator coverage |
|---|---|---|
| Dashboard | Rendered; review count opens filtered Payment Review; Back/Forward retains period/currency | Other filters and complete responsive matrix |
| Customer Register | Rendered bounded directory; real customer-detail link | Full filter/pagination and modal matrix |
| Customer Detail | Rendered overview and scoped evidence links | Full owned financial chain with representative allocation history |
| Services / Tools | EN/AR/KU screenshots at all three widths; no document horizontal overflow; mobile modal, validation and table keyboard checks | Sticky headings and complete drawer focus-cycle sign-off |
| Entitlements | Desktop render and visual review | All locales/sizes and editor keyboard cycle |
| Pricing | Desktop render and visual review | Later grouped pages and all locales/sizes |
| Plans | Desktop render and visual review | Long editor in each locale/size |
| Voices | Desktop render and visual review | Filters/later pages and all locales/sizes |
| Payments | Current list; real selected evidence click | Representative successful payment/allocation and all locales/sizes |
| Subscriptions | Rendered, bounded to 25 visible rows | Owned subscription-to-allocation chain and later pages |
| Orders | Rendered understandable empty state | Nonempty representative orders and all locales/sizes |
| Payment Review | Dashboard opens the correct filtered empty queue | Nonempty review cases; do not trigger reconciliation for testing |
| Audit / evidence | Audit rendered; selected payment → ledger → audit preserved customer/payment/era context | Later event/allocation pages and complete cross-customer browser negative case |

Real Back/Forward preserved the Dashboard review context. Payment → ledger → Back →
audit retained selected customer, payment and financial era. The selected failed
payment had two persisted events and no allocations; it cannot prove the complete
customer → payment → subscription → allocation → ledger → audit chain. An attempted
foreign-customer evidence URL was blocked by the browser client before a verifiable
application response, so **server denial is not claimed from that browser attempt**.
The isolated authorization regression suite passed.

Arabic and Kurdish Tools samples render RTL; English renders LTR. Header, breadcrumbs,
buttons, cards and mixed product names were visually reviewed at all three widths.
Status badges contain readable text. Locale switching used the real header menu.
This does not establish complete localization/layout acceptance of financial pages.

Keyboard observations: modal focus remains inside during sampled Tab navigation;
required-field failure focuses the summary; Escape and Cancel return focus to Add
Tool. The Arabic narrow table responds to ArrowLeft with a 40px horizontal scroll
while retaining region focus. Mobile drawer makes background content inert, releases
it on Escape and returns focus to the menu toggle. Tab enters drawer links and close
control. Initial focus and a complete forward/reverse wrap cycle still need explicit
operator sign-off; do not extrapolate these samples to every modal or assistive tool.

Two intentionally invalid Create Tool submissions left required name/code blank.
They were rejected; read-only before/after counts remained **19 tools / 12 audit
events**. No valid creation was submitted. The local debug toolbar initially covered
mobile controls; it was collapsed through its UI before the final modal checks.
An earlier sign-in-time console error referenced legacy `/app/js/app.js`; it was not
reproduced as an authenticated Admin navigation defect and remains a follow-up check.

## Operator checklist

For each row above record **Pass / Fail / N/A**, locale, viewport, route/query context
and a sanitized screenshot. N/A needs a reason (for example, no modal on that page).
Do not treat a loaded page or a static source assertion as interactive acceptance.

1. Repeat EN, AR and KU at the three widths. Inspect header, breadcrumbs, cards,
   filters, action buttons, financial tables and mixed LTR IDs. Use real navigation
   and locale controls, then Back/Forward; selected customer/era/filter must survive.
2. Scroll the sidebar independently; open/close the mobile drawer. Tab and Shift+Tab
   must stay inside it, background must be unavailable, Escape must restore focus.
3. Open applicable long modals, expand advanced sections and reach the last field
   and footer using keyboard only. Check labels, visible focus, wrap, Escape and
   opener restoration. Cancel SweetAlert and verify no mutation. Submit only an
   explicitly approved safe invalid form to verify errors and summary focus.
4. Focus each horizontal table and use arrow keys. Check sticky headings while
   scrolling rows, readable non-color status labels, and dropdown placement at edges.
5. Use an approved non-production customer with linked payment, subscription,
   allocation and ledger evidence. Click the full chain through Audit; check first
   and later evidence pages. Test another customer's selection and require safe denial
   without content leakage. Never create or settle financial records just to fill gaps.
6. Live stale-permission check is **pending the disposable local Admin ID** promised
   by the user. Do not use the main signed-in Admin. With an authorized test operator,
   open an action modal, revoke its required capability in another session, attempt
   the action, require 403/safe translated denial and unchanged target/audit state.
   Restore only the approved original test permissions and record the outcome.

## Native MySQL handoff — blocked

Required inputs are the exact production RDS engine version (information only) and
an approved local/staging native MySQL connection with representative sanitized data.
**Do not connect to RDS production or use XAMPP results as native acceptance.**

Capture actual read queries on that approved target for:

| Read | Required variants |
|---|---|
| Customer Register | Default, search, customer, plan; first/later page |
| Customer Detail | Current plan and evidence for an owned representative customer |
| Tools / entitlements | Default and selected plan/action; repeated resolver count |
| Voices | Default and selected plan/search; first/later page |
| Grouped pricing | Default, plan/action/search; first/later grouped page |
| Payment Review | Current/legacy, customer and review-state filters |
| Effective subscriptions | Current/legacy, customer/plan/effective-state; first/later page |
| Payment/allocation evidence | Selected owned payment/subscription; first/later event and allocation pages |

Use ordinary read-only EXPLAIN for SELECT statements. Record query fingerprint,
redacted fixture context, selected key, access type, estimated rows, Extra/filesort/
temporary-table signals, request query count and repeated resolver queries. Ordinary
EXPLAIN estimates are **not measured rows examined**. Obtain actual rows-examined and
timing only from approved existing instrumentation; otherwise mark those unavailable.
Do not turn on global instrumentation, execute maintenance or use provider calls.

No native EXPLAIN was run and no native bottleneck was established. Local development
toolbar counts were observed but are not a controlled benchmark or production sizing
evidence. No performance refactor or index migration is justified/prepared from this
pass. In particular BillingSubscriptionAuthority remains untouched.

## Regression and files

Focused PHP coverage uses SQLite `:memory:`, array cache/session and existing fake/
mock fixtures. The final run passed **143 tests / 2,093 assertions** covering
AdminAcceptance, AdminShell, AdminCustomerWorkspace, AdminServiceWorkspace,
AdminServicePricingTableGrouping, AdminBillingWorkspace, AdminP0Safety and AdminP3Ui.
This rerun includes the long-modal CSS fix and completed in 454.32 seconds; the earlier
run also passed the same 143 tests in 182.22 seconds.
Admin frontend coverage passes **40 tests**, including generated modal-title lifecycle
and modal-scroll constraints. Vite build passes. The three known V1 navigation
expectation mismatches remain documented in Phase 5, untouched and not counted as
Phase 6 successes. No PHP business source changed.

Phase 6 changes: `resources/js/admin.js`, `resources/css/admin.css`,
`tests/Frontend/admin-ui.test.mjs`, this report and `ADMIN-AUDIT.md`.
Private local logs/screenshot live in ignored `storage/app/private/admin-phase6/`;
they are local acceptance artifacts, not deployment or production data evidence.

Remaining release acceptance: full operator page/locale/viewport/keyboard matrix,
complete owned financial/evidence pagination chain and browser negative case,
live disposable-account stale-permission denial, native MySQL version/plan/load
evidence, and final deployment-environment sign-off. No deployment was performed.
