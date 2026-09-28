# Admin customer workflows — Phase 2

Implemented locally on 2026-09-27. Presentation and workflow refinement only;
no deployment, migrations, feature-flag changes or application-data writes.

## Register and customer workspace

Customer Register retains its existing search, status, country, join-date and
effective-plan filters and pagination. Its eight columns are Customer, Status,
Current Effective Plan, App Credits, API Credits, Storage, Joined and Actions.
Customer names open the existing Customer Detail route. The focused Register view
is an action workspace, with a link back to Detail rather than a duplicate profile.

Customer Detail and the unfiltered customer-scoped Operations view use the shared
Phase 1 components. They show identity and verification, effective plan/source/expiry,
separate App/API balances, storage and concurrency, Developer Access, processing,
current billing evidence and recent Admin audit. Technical evidence is expandable.
The existing bounded record browser remains available under a disclosure or opens
for an explicit section/filter. There is no Register table inside Customer Detail.

`AdminCustomerWorkspace` is a request-local, read-only presentation projection. It
uses existing customer billing state, API/MCP and concurrency authorities and the
sanitized `AdminOperations` queries/rows. Recent jobs are limited to five and audit
events to three; detailed records retain their existing pagination. Full job input
and output are not hydrated by this projection. All queries remain customer-scoped;
the locked route customer takes precedence over a changed filter.

## Access, billing and sources

Friendly translated source labels distinguish FIB subscriptions, manual grants,
internal access and external agreements. The authoritative effective plan is not
recomputed by the view. Agreement expiry uses the existing inclusive display date.
Wallet components remain separate, and displayed credits do not grant plan access.

API availability combines the existing access resolver with the REST feature gate.
MCP uses its own existing eligibility and feature gate; disabling the REST API does
not imply that MCP is disabled. The card explains unavailable states and displays
scope summaries. Consent, per-action permissions, credits and processing limits
remain separate checks. No token, secret or guessed connection count is shown.

Billing starts with current subscription, agreement and latest current-period
payment links. Payment lookup respects `BillingReportingBoundary`, including its
retired-ID watermarks. Legacy/pre-V2 payment evidence has a separate link. Existing
deeper evidence permissions and sanitized audit rendering remain in force. Reads
perform no GPU polling, provider calls, wallet settlement or billing repair.

## Actions and agreements

Focused actions are grouped as Access, Commercial Agreement, Payments,
Credits/Storage and Security/Access. Descriptions distinguish complimentary grants,
verified FIB reconciliation and commercial agreements. Existing methods, form
bindings, capabilities, required reasons and durable intent identities are retained.
Credit synchronization is in the focused customer actions rather than the directory
table. Activation/suspension links use the existing customer-list controls.

The payment evidence selector opens the existing reconciliation or review modal
after loading the selected payment, through the shared `admin:modal-show` handler.
Modal validation summaries, inline field errors and the shared safe operation-error
bridge remain available. No new navigation or global JavaScript handlers were added.

Agreement creation labels the plan as the Base Permission Plan and explains that
duration, allowances and concurrency overrides affect this customer only. Its
reactive review shows the plan, dates, App/API allowance, concurrency, reference and
optional amount before the existing confirmation. Blank allowances inherit the plan;
explicit zero remains zero. Blank concurrency displays the existing configured
default of five. The optional amount remains agreement information, not a receipt.
Agreement history and adjustment controls are disclosed on demand. Existing schema
readiness checks remain; the previously prepared migration is not applied here.

## Verification and remaining acceptance

Focused isolated PHP verification covers filters, customer isolation, balances,
effective plans, API/MCP gates, bounded reads, reporting boundaries, modal selection,
capabilities, reasons and existing grant/agreement/payment workflows. Frontend tests
cover translated-key parity, review expressions including explicit zero, and shared
Admin lifecycle behavior. All tests use isolated SQLite, array cache/session and
mocked external services, not the application database.

Final results: **204 distinct PHP cases passed across the focused runs**:
AdminUsability, AdminP2Operations, AdminCustomerWorkspace, ComplimentaryPlanGrant,
ServicePlanAgreement, AdminBillingControls, AdminCustomerPaymentsReview,
AdminP0Safety and AdminP3Ui. Initial failures in the new payment-summary fixture
were corrected to use valid payment enum values, the required reference and a
fresh database snapshot; its final isolated rerun passed all nine assertions.
**28 frontend tests passed** (Admin lifecycle, localization and customer workspace),
the Vite production build passed, and focused PHP syntax, nine-file Pint and
whitespace checks passed. This is targeted Admin coverage, not a full repository run.

EN/AR/KU copy and server rendering are covered. Cards stack responsively, tables
retain horizontal scrolling, and new styles use logical borders and mixed-text
direction markers. The Phase 1 shell and shared JavaScript are unchanged. Browser
automation could not initialize because its kernel assets were unavailable; actual
desktop/tablet/mobile layout, modal interaction and RTL navigation still need manual
browser acceptance. Native MySQL and deployment acceptance were not performed.

Phase 3 should start with that interactive acceptance, then refine the service
catalog, entitlement and pricing workflows as a separately scoped presentation
phase. Keep business authority and financial policy changes separate.
