# Admin operational billing and review — Phase 4

Implemented in source on 2026-09-27. This phase refines existing Operations and
the handoff to existing Customer Register payment modals. It does not redesign
Customers, Services, Pricing, Plans or the shell. No deployment or application
migrations were performed. Financial policy and historical data are unchanged.

## Payments and review queues

Payments now show customer, amount/currency, purchase type, local/provider
reference, creation/update dates, recorded status, review category, reporting era
and evidence/action links. Technical evidence stays collapsed and uses the existing
sanitized projection; provider payloads are not added to component state.

The new AdminBillingWorkspace presentation classifier exposes Needs review,
Processing / awaiting evidence, Failed / closed unpaid, Resolved / applied and
Unclassified. Cancellation pending takes precedence over application/closure.
Recorded review closure is distinct from financial success. Current payment lists
prioritize review and processing before other records. These are display groups,
not new domain statuses or authority to retry, fulfill or refund.

Case filters distinguish recorded evidence conflicts, pending local application,
checkout/verification, cancellation reconciliation and other payment/subscription
review. A conflict label indicates an existing mismatch reason; its free-form
provider message is not exposed by this new list. Unknown stays unknown.

The existing payment_review queue predicate and dashboard count remain intact.
Current-period defaults use BillingReportingBoundary. The explicit Legacy / Pre-V2
selector also works in payment review; it never exposes the new correction handoff.

## Subscriptions and agreements

Service/storage subscriptions show plan, source, recorded status, dates, auto-renew,
renewal/cancellation state, payment linkage and effective-access classification.
Current versus non-current access filters reuse effectiveAt and the latest eligible
subscription per customer, matching the existing runtime resolver. An active
historical row cannot label itself current merely because its status says active.
Not-current includes scheduled, superseded, expired and otherwise ineligible rows;
it is not itself a new lifecycle status or proof of a pre-V2 timestamp.

Linked external agreement evidence shows its reference, state and inclusive expiry.
It is explicitly access-term evidence, not a receipt. Agreement lifecycle, totals,
allowances and allocation logic are untouched. Existing Register agreement controls
remain the only editing workflow.

## Orders and financial provenance

Orders show recorded type, amount in its recorded base IQD or USD unit, status,
credit quantity, revenue-exclusion marker and owned payment links. No conversion,
revenue recalculation or inferred fulfillment is added. Credit quantity is not a
current wallet balance; allocation and ledger links provide application evidence.
Legacy rows retain a distinct badge, border and historical-evidence explanation.
The reporting boundary retains both timestamp and ID-watermark protection, including
retained future-dated rows. Missing/detached payment links are explicitly identified.

## Evidence and guarded actions

Existing Operations URLs link customer, payment, subscriptions, orders, ledger and
audit. Selected payments expose independently paginated PaymentEvent and allocation
summaries; selected service subscriptions also expose allocations, including those
with no online Payment. Allocation entries link to the exact owned subscription.
Events select only operational identifiers/status/times, excluding payload/meta.
Finance or reconcile capability is freshly required for these detailed histories.

Payment audit traces include both direct Payment audits and AdminOperation audits
whose requested payment ID and customer match. This fixes the missing-operation
trace without changing audit writes or financial operation identity.

The new current-payment handoff opens the existing customer-scoped Register with
a selected-payment summary. Buttons open the original review/reconciliation modals;
there is no mutation on navigation or modal opening. Existing reasons, confirmation,
fresh capabilities, provider verification, locks, AdminOperationRunner and immutable
intent replay remain unchanged. The handoff checks current era and customer ownership.
Historical evidence remains available without advertising a new correction action.

Cancellation retry remains the existing guarded operator/scheduler workflow; this
phase exposes its pending state and retained retry evidence, not a new web retry.
No new refund, verification, repair or force-fulfillment endpoint was introduced.

## Dashboard, performance and localization

The existing dashboard payment-review count still links to exactly section=review,
queue=payment_review with the current reporting default. No new health metric or
financial total was introduced.

Lists retain 25-row pagination. Customer names, payment links and plan relations
are batched; storage subscription names use a batch query because that model has
no customer relationship. Agreement summaries are bounded by the visible subscriptions.
Events and allocations load only for a selected record, ten per page independently.
Normal browsing never queries a provider or runs reconciliation. No full event
history is hydrated in list rows.

The existing effectiveAt authority internally examines historical local/online
eligibility evidence. This phase reuses it once per subscription type per visible
batch instead of once per row, but does not claim to remove that existing global
authority cost. Representative native-MySQL query-plan/load acceptance is separate;
changing that authority was explicitly outside this task.

New copy is in resources/lang/{en,ar,ku}/admin_billing.php. Dynamic text uses existing
direction handling and technical values use bdi. Billing tables retain horizontal
scrolling, keyboard-focusable regions, wrapped status/actions and logical RTL borders.
No new JavaScript lifecycle or per-page event listeners were added.

## Verification and limits

222 distinct focused PHP tests passed across the read, safety and final customer
regression runs (repeated workspace cases counted once). Coverage includes the new
14-case billing suite, existing P2 reads, dashboard, payment review, P0 financial
safety, billing controls, P3 confirmations, agreements, recurring lifecycle and
Phase 2 customer workspace. The final 24-case billing/customer run passed with
306 assertions. Initial fixture mistakes were corrected; no unresolved test failure
remains in these focused runs.

All 34 Admin frontend checks passed. Final Vite build, eight-file focused Pint and
whitespace checks passed. Protected payment-domain, billing-service and financial
mutation files match the pre-phase hashes; only the intended read projection was
excluded from that protected-file comparison.

Tests use isolated SQLite :memory:, array cache/session and mocked HTTP. No test
uses the application database. Native MySQL and interactive browser acceptance
remain separate. Browser automation initialization failed with a local missing-path
error; static responsive/RTL checks are not a claim of visual acceptance.

## Recommended Phase 5

Perform operational acceptance and accessibility checks on representative billing
data: EN/AR/KU desktop/mobile, keyboard navigation, deep links, paginated evidence,
stale-permission dialogs and native-MySQL query plans. Keep financial policy and
legacy deletion outside that phase unless separately authorized. No production
activation is implied by source/test completion.
