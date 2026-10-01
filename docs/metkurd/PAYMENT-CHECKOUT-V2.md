# V2 checkout lifetime and payment status

## Bounded checkout persistence — 2026-10-01

New V2 checkout rows use the [minimal Payment contract](PAYMENT-PERSISTENCE-V2.md).
The existing owned checkout UI retains app links, readable code and a transient QR
image from a private non-database cache. Cache loss leaves the existing continuation
options; it never triggers a new provider checkout. Legacy rows remain readable.
Review reasons are bounded local codes; commercial snapshots are immutable. Provider
verification, fulfillment and cancellation authority remain unchanged.
Unchanged provider polls update only bounded current state/check time; they do not
append PaymentEvents. Duplicate callbacks are bounded wake-ups, and identical
failures share a count/first/last-seen aggregate. Meaningful transitions, collections
and fulfillment retain durable events; see the linked event-frequency contract.

## Customer abandonment of unavailable checkout — 2026-09-14

V2 Payment now offers **Cancel this checkout** through the existing V2 SweetAlert
confirmation for a narrow local-only draft. `AbandonedCheckoutEligibility` extracts
the existing Admin display/final predicate unchanged; `InvalidateAdminReviewPayment`
still uses that predicate, fresh finance/reconcile authorization, reason and P0 audit.
`PaymentCheckoutState` and all final Create* guards remain unchanged.

Customer eligibility additionally requires a pending/awaiting FIB Payment whose
review is computed from missing/unparseable checkout information, an explicit unpaid
DRAFT/UNPAID/CREATED state, and no issued provider ID, code, QR or continuation links.
An explicit unresolved review marker, unexplained status/mismatch reason, sync failure,
unknown response state, refund/reversal/collection/transaction evidence, paid-through
coverage or unexplained event rejects customer closure. The shared predicate also
rejects paid/applied/fulfilled states, paid/coverage timestamps, retained subscription,
CreditOrder/allocation dependencies and consumed coupons. This deliberately does
not treat a remote DRAFT with a missing deadline as proof it can no longer collect.
Issued remote objects and financial ambiguity remain on the provider/Admin path;
no new provider cancellation behavior is inferred.

`AbandonCustomerCheckout` re-authenticates the active owner, locks customer then
Payment, and rechecks all evidence. The owned Payment ID is the durable abandonment
identity. Success sets the existing local status/internal status to `canceled`, records
`canceled_at`, customer resolution metadata and a keyed `customer_checkout_abandoned`
PaymentEvent. Repeat calls are read-only replays. Prior events and all provider fields
remain unchanged. Unused legacy coupon reservations release through the existing
service; no Payment deletion, wallet/ledger mutation, revenue/refund entry or FIB
request occurs. This is a customer audit event, never an impersonated Admin operation.

The page displays **Payment requires attention** only when this action is eligible,
and **Checkout canceled** after customer closure, with a V2 Subscription/Storage/Add-on
return link. Other reviews retain support/Admin handling. Purchase data is request-local;
closure notifications refresh already-open review components through Livewire, including
other tabs via a non-sensitive storage notification. Focus/visibility/back-cache return
also rechecks server state, preserving the selected item. Notifications never authorize
checkout or alter state; another independent blocker still prevents purchase.

Coupon entry and coupon explanations are removed from all three V2 purchase/review
surfaces. Method selection is followed by catalog subtotal, actual customer fees and
total. V2 submits no coupon; legacy coupon services, tables and snapshots remain intact.
EN/AR/KU copy and the existing RTL confirmation bridge are retained.

Read-only local inspection: the operator-reported customer-1 historical payment has
DRAFT, no paid/fulfilled/coverage timestamps and an unreadable deadline, but also a
persisted NOT_FOUND/HTTP-404 review asking whether a paid transaction belongs to a
different reference, with reconciliation paused. It is **not customer-eligible**.
It remains eligible for an explicit Admin review/closure under the existing policy.
No historical Payment was closed, no provider was contacted, and feature gates were
not changed. Missing provider evidence is not proof that money moved or did not move.


Verification: 316 distinct regression cases passed across the broad run and
focused reruns, covering Account/checkout, billing Phase 1/2, recurring allocations,
monthly refill, storage, coupons and Admin P0. One existing V1 Arabic-page test still
expects English redirect copy (FibPaymentFlowTest:2470). Final checkout/Admin review
rerun: 71 regression cases plus one isolated visual-export fixture passed (1,089
assertions); the separate purchase-page run passed 20 cases (227 assertions). These
counts overlap. The retained-order fixture now supplies its required order_type;
the older Admin fixture retains DRAFT as the observed state and NOT_FOUND as error
evidence. Prior V2 coupon-forwarding assertions now verify the requested null coupon.
The final evidence-focused checkout run passed 71 cases (1,087 assertions), including
unmapped remote IDs and unclassified historical authorization events. All 35 frontend
tests, Vite build and focused syntax/Pint passed.

Browser inspection used rendered isolated fixtures: English confirmation, Arabic
390px layout and confirmation, and Kurdish 390px confirmation. The existing bridge
uses translated buttons, RTL direction and initially focuses Cancel. No live customer
closure was executed; the customer session is signed out. No native MySQL/RDS or
real provider acceptance is claimed.

## Paused historical review status — 2026-09-13

Read-only follow-up confirmed the reported local Payment remains unclosed, has no
stored FIB environment marker, and carries a historical permanent HTTP 404 sync
failure with reconciliation paused (June evidence, not a new provider probe).
The current runtime is staging with separate explicitly configured payment and
subscription profiles; both configured hostnames resolve. This does not establish
the original record's provider environment or prove the latest failed request's
cause. No profile/credential change or provider request was made.

The cutover/cleanup documents never claimed that this history was reset: inventory
was blocked, the full destructive cutover command remains unimplemented, and the
existing conservative cleanup cannot discard these records. Changing FIB_ENV does
not migrate provider references or make unknown historical rows safe to ignore.

The V2 customer page now offers **Refresh review status** for review Payments with
`meta.latest_sync_failure_pause_reconciliation`. This action performs an owned local
read, clears stale display errors and waits for the Admin review decision, without
retrying FIB using the current environment's credentials. Other eligible status
checks retain their existing behavior. The shared checkout blocker is unchanged.
After the existing authorized Admin closure, the same refresh displays expired and
the V2 new-purchase destination. No application Payment, wallet, ledger or history
was modified; no environment was inferred, stamped or changed.

Verification: 111 checkout/Admin P0 cases passed (1,229 assertions), including
EN/AR/KU paused-review refresh without HTTP, unchanged Payment attributes, retained
blocking and recognition of subsequent Admin closure. Focused syntax/Pint and
50-key translation parity passed. The browser session was signed out; live
authenticated acceptance was not claimed. No frontend assets changed in this follow-up.


## Responsive FIB checkout controls — 2026-09-13

The actionable V2 payment page now presents QR first on larger viewports and safe
provider app links first at the existing 700px mobile breakpoint. Personal,
business and corporate distinctions remain visible; no app/deep link is guessed.
Mobile QR is expandable when a safe app link exists, and remains visible when
none exists. A readable code can stand alone when QR is absent. If every payment
instruction is absent, the existing safe unavailable message replaces empty controls.

PaymentPage's existing safe projection adds only the stored readable code and
server-derived countdown timestamps. Existing URL/raster QR validation is unchanged.
The escaped code is LTR, selectable and copyable with translated temporary status
feedback; clipboard failure gives manual-copy guidance. Summary precedes controls
and status on mobile. All controls disappear outside actionable checkout.

The navigation-safe v2-payment helper uses server time plus elapsed browser time
for display. At zero, or browser visibility/back-cache return, it requests Livewire
`$refresh` to re-read owned server state. It never calls FIB, changes financial
status, interprets an app click as success, or resets the existing polling limit.
Normal bounded polling and explicit status verification remain authoritative.
No payment, subscription, evidence, fulfillment, callback or cancellation logic changed.

Verification: 56 focused checkout/purchase PHP cases passed (55-case run plus a
separate real Livewire expiry/app-return refresh regression); 34 frontend cases,
Vite build, focused syntax/Pint and 48-key EN/AR/KU parity passed. Isolated rendered
fixtures were inspected at desktop width and 390px AR/KU mobile width, including
QR expansion. Fixtures used an existing image as a visual stand-in, not a generated
FIB checkout. No real provider app launch/payment was attempted.

The 2026-09-16 follow-up reproduced this configuration failure after cutover.
V2 recurring service checkout now checks it before persisting a Payment and shows
a specific EN/AR/KU setup error; current-epoch review protections are unchanged.
See [V2 purchase pages](PURCHASE-V2.md#post-cutover-subscription-checkout--2026-09-16).

Local runtime reports FIB staging enabled, but the current callback configuration
fails existing validation for payment and subscription channels. Staging does not
permit localhost callbacks in this integration. For real local QR generation,
configure `FIB_CALLBACK_BASE_URL` with a public HTTPS address reaching the local
application and use the configured staging credentials. This task did not change
environment settings, contact FIB, create a checkout or mutate application data.


## Admin closure of abandoned checkout — 2026-09-13

The Customer Register's existing payment review panel exposes **Close Abandoned
Checkout** only to operators with both `admin.finance` and `admin.reconcile` and
only when the shared customer policy classifies the selected Payment as review.
The existing SweetAlert bridge includes the customer/payment identity, entered
reason and explicit local-only impact. Enter a reason of 10–500 characters, then
confirm the action. Use the existing **Start a new correction** control for a
separate decision; retry keeps the original durable operation identity.

`InvalidateAdminReviewPayment` reuses `AdminOperationRunner` (fresh active Admin
capabilities, reason-bound intent, customer then Payment locks, audit and replay).
Its shared display/final eligibility check excludes fulfilled/applied/paid/refund
states, paid or coverage timestamps, future known checkout deadlines, unknown
current provider states, retained subscription/CreditOrder/allocation links, and
consumed coupon evidence. A matching
subscription provider reference also blocks closure. `AdminProviderEvidence` now
has a local-only conservative evidence check: current fields, stored responses,
callbacks and prior PaymentEvents cannot contain paid/active/refund collection
evidence. An older paid observation cannot be hidden by a newer DRAFT response.
This is an operator decision; neither missing deadlines nor age automatically
closes a review. `AdminPaymentReconciliation` and its remote paid-evidence
verification remain separate and unchanged; this action never calls them or FIB.

Success retains Payment, provider fields and all prior events, sets existing
status/internal status to `expired`, records `expired_at`, clears the open review
marker, and retains the reason/actor/operation/time in review resolution metadata.
The existing keyed invalidation event and Admin audit are recorded transactionally;
`CouponRedemptionService` releases unused reservations once; consumed redemptions
block closure and remain untouched. No wallet, ledger, revenue, refund or fulfillment evidence is created.

`PaymentCheckoutState` is unchanged. Customer V2 pages still show **Payment requires
review** before closure; after refresh they show expired and **Start a new purchase**
with the Subscription, Storage or Add-on destination. Another independent blocker
still prevents checkout. Customers have no Admin action. No application database
record was closed or repaired by this implementation; operator execution is separate.
A read-only transaction against the confirmed local copy found the known historical
Payment still in review and eligible for this explicit operator action.

Verification (isolated SQLite, fake HTTP/storage): final Admin P0 run **69 passed
(509 assertions)**, including the existing dependency/race cases and 21 new
abandoned-checkout cases. The broader Payments/Account/Phase 2/subscription/storage/
Admin UI/billing run has **304 other passing tests** and the previously known
Arabic V1 English-copy assertion failure at FibPaymentFlowTest:2470. Its two initial
coupon-fixture failures were corrected and pass in the final P0 run; overlapping
runs are not additive. All 12 Admin frontend tests, eight syntax checks, focused
Pint and EN/AR/KU catalog parity passed. Livewire rendering/actions are tested;
interactive Admin confirmation and native MySQL/RDS execution were not performed.


Implemented 2026-09-10. This replaces the initial purchase-page rule that treated
any unfulfilled pending/review Payment as an indefinite blocker.

## Shared policy and creation boundary

`PaymentCheckoutState` is the shared customer-checkout policy. It is used by V2
purchase selection, V2 Billing history, the V2 status page, legacy browser polling,
scheduled checkout eligibility and the final Create* action guard. Renewal policy
is separate: checkout `valid_until` must not become a paid `active_until` boundary.

| Evidence | Customer checkout behavior |
| --- | --- |
| Fulfilled/applied | Completed, no new-checkout blocker or polling |
| Refunded | Refunded, no blocker or polling |
| Explicit review/refund request | Review; age never clears it |
| Paid but unapplied | Confirming; new purchase blocked; explicit status check available |
| Conflicting paid/active/collection/coverage evidence | Review, even if the checkout deadline elapsed |
| Known unpaid failed/canceled/expired | Closed and non-blocking |
| Pending/awaiting, known unpaid state, elapsed provider checkout deadline | Expired and non-blocking |
| Pending/awaiting, future deadline and a reference for that provider object type | Actionable; reuse existing checkout |
| Missing/malformed deadline, missing provider reference or unknown state | Review; no age-based expiry |

Provider `validUntil` in stored status/create evidence is parsed with the existing
strict timestamp parser, retaining its UTC offset. When no raw deadline was
provided, the existing `valid_until` field is used. Malformed raw timestamps are
not replaced with guessed dates. No `expires_at` column or four-month cutoff was
invented. Creation-response and one-time-status DTOs now convert parsed instants
to the application timezone before Eloquent stores timezone-less dates. This
corrects premature expiry for new records; historical rows are not bulk repaired.

`CheckoutCreationGuard` wraps all three existing actions:
CreatePlanSubscriptionPayment, CreateStorageSubscriptionPayment, CreateAddonPayment.
It shares a customer/purchase-kind cache lock across V1 and V2 callers, closes
known unpaid sessions and rechecks blockers before provider creation. The original
actions still validate pricing, methods, modes, coupons and eligibility. Service,
storage and add-on scopes are separate; two service-plan choices share one scope.
Deployment must use a cache/lock store shared by application workers. Isolated
lock/replay tests are not native MySQL or multi-host concurrency acceptance.

Known closure at a creation/reconciliation boundary locks customer then Payment,
rechecks current evidence, uses PaymentTransitions and existing terminal enums,
records a keyed checkout_closed event and releases unused coupon reservations
through CouponRedemptionService. It never deletes the Payment or invents payment
or refund evidence. A read projects the correct state without changing stored
history. Legacy coupon replacement cannot bypass an unconfirmed cancellation or
claim that a coupon was applied when the guard reused the original checkout.

## Status and callback safety

Route: `app.v2.payments.fib.show`, `/{locale}/app-v2/payments/fib/{payment}`,
with UUID binding and the existing verified, active customer/App V2 gate.
V1 routes remain. Purchase redirects, blocker links and V2 billing-history links
use this route. New V2 one-time checkouts carry a server-selected checkout_ui
marker so their provider return URL also uses V2; callback endpoints are unchanged.

The view-based component inherits Account\PaymentPage. It exposes a safe display
projection, not a public Payment model/payload. A locked identity and fresh owned
query protect every action. Summaries show the purchased snapshot, original amount,
mode/interval, dates and available credit/quota details. Completed historical
purchases do not promise that their plan is still the customer's current plan.
Terminal states have V2 purchase/Billing/App return links and no payment controls.

Only actionable checkout displays QR/continuation links. QR accepts raster data
images or HTTPS FIB URLs; continuation links accept the known app/personal/business/
corporate entries on fib.iq or its subdomains, excluding URL credentials. Raw
payloads, failure reasons, provider IDs and callback details are not rendered.

V2 automatic polling runs every 10 seconds only while actionable, up to 60 checks
per component session. A per-Payment lock/throttle limits simultaneous status calls.
Paid/confirming, completed, failed, canceled, expired and review states stop automatic
polling. An explicit check for actionable/confirming/review state delegates to the
existing ConfirmFibPayment/SyncFibCheckoutStatus. GET rendering is provider-free.
Legacy paid-but-unapplied recovery remains available through its existing handler.

Known-expired browser/manual checks avoid unnecessary provider requests. Late
callbacks can still obtain authenticated provider evidence through the existing
sync path. Closed-to-paid transitions cannot reactivate checkout: late paid
evidence is recorded as requires_review with a safe reason, without fulfillment.
Payment locks, verified identity/money/reference checks, fulfillment idempotency,
supersession and Phase 2 allocation rules remain authoritative.

## Local evidence and limits

The historical payment currently linked from the signed-in customer's plans page
already has requires_review and review_required_at, DRAFT provider state and an
unparseable recorded provider deadline. It correctly remains a review blocker.
No attempt was made to clear it or contact FIB. An independently selected closed
payment renders expired with a V2 return action. History remains intact.

Browser inspection covered the V2 review page, EN expired layout, AR mobile/RTL
expired layout and KU mobile completed layout. No payment, cancellation, provider
status check, seeder, migration, wallet operation or historical repair was executed
against the application database. Real FIB and native MySQL/RDS acceptance remain
unverified. Storage replacement/one-time interval limits in PURCHASE-V2.md remain.

Regression evidence: broader Payments/Phase 2/subscription/storage/coupon/Admin/
account/timestamp run: 316 passed, one existing English-copy assertion failure on
the Arabic V1 checkout page (2,396 assertions). The subsequent focused callback/
checkout run had 79 passed and the same baseline failure (830 assertions).
These overlapping runs are not additive. All 28 frontend tests passed; Vite build,
focused syntax/Pint and EN/AR/KU catalog parity are checked separately.

Final account-only run: 67 passed (785 assertions). After the legacy polling
integration, the final Account + FIB flow + Coupon run has **136 passed and the
same one baseline failure (1,199 assertions)**. All 34 focused checkout cases pass,
including three real Create* paths after expiry, timezone preservation, late-paid
callback replay without grants, ownership on every action, bounded polling,
read-only expired history and refunded presentation. Terminal/review V2 rendering
and safe links are exercised in EN/AR/KU. Viewport was restored after mobile checks.
