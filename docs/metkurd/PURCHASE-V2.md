# V2 purchase pages

## Post-cutover subscription checkout — 2026-09-16

The observed local failure was callback configuration, not an epoch/history guard:
FIB recurring creation requires a public HTTPS callback even in staging. V2 service
checkout now validates this before creating a Payment or reserving a coupon and
returns a specific translated setup error. Unexpected failures no longer tell the
customer that billing history is necessarily at fault; logs record a bounded
exception type without exposing its payload in the UI.

CheckoutCreationGuard and PaymentCheckoutState retain their existing current-epoch
queries. Archived Payments and CreditOrders cannot block a new subscription;
actionable/review Payments in the current epoch still do. No history is repaired.
Configure `FIB_CALLBACK_BASE_URL` to a public HTTPS address reaching this application
before real local FIB staging acceptance. Do not substitute a production callback
for a local checkout or weaken the callback requirement.

When active paid service plans have one payment mode, the page automatically selects
it and displays a translated read-only value. Multiple modes retain the existing
filter. Plan modes and payment-method eligibility are unchanged.

Verification: 114 isolated SQLite tests (1,544 assertions) passed across
V2PurchasePagesTest, V2PaymentCheckoutTest and BillingEpochTest; focused PHP lint
and Pint passed. New cases exercise real checkout coordination/creation with a
mocked FIB client after cutover, both deleted and retained legacy Payments, retained
CreditOrders, current review, replay, unchanged wallets/ledger/history, setup failure
without new records, and single/multiple-mode rendering in EN/AR/KU. No live FIB
request, application database write or interactive browser acceptance was performed.

## Recurring replacement status — 2026-09-14

Creating a replacement checkout leaves the paid current plan untouched. Only
verified new fulfillment switches access and the existing App/API allowance;
old-provider cancellation follows commit and remains visible/retryable on failure.
Purchase/Billing pages show paid access and renewal separately, including pending
replacement, old cancellation and ended prior plan. See
[RECURRING-SUBSCRIPTION-LIFECYCLE.md](RECURRING-SUBSCRIPTION-LIFECYCLE.md).


## Customer checkout abandonment — 2026-09-14

The V2 payment page has an owned, locked, idempotent local-draft cancellation action
with stricter eligibility than Admin review. The Admin evidence predicate is shared,
while PaymentCheckoutState and final creation guards remain unchanged. Historical
NOT_FOUND/ambiguous paid-reference review still requires Admin resolution. V2 purchase
pages no longer accept coupons and refresh open reviews after closure. Provider fields,
financial history and legacy coupon services remain intact. See
[PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md#customer-abandonment-of-unavailable-checkout--2026-09-14).


## Checkout follow-up — 2026-09-10

The initial indefinite pending-payment guard and shared V1 checkout destination
below are superseded by [PAYMENT-CHECKOUT-V2.md](PAYMENT-CHECKOUT-V2.md). All three
purchase pages now use the shared lifetime/evidence policy and V2 status page.
Known-expired sessions are non-blocking; actual review/unknown payments remain
blocked. The final guard now wraps the existing Create* actions across V1 and V2.
Original purchase economics and storage limitations below remain unchanged.

Implemented 2026-09-10. Local source/UI acceptance only; real provider and native
MySQL/RDS acceptance remain separate.

## Routes and presentation

The existing gated, verified customer V2 group exposes localized
`app.v2.subscription-plans`, `app.v2.storage-plans` and `app.v2.addon-credits`.
The existing account dropdown includes these with Profile, Billing and My Storage,
marks the current route and retains Bootstrap-controlled open/close state. V1 routes
remain. Anonymous Livewire components share `Account\PurchasePage` and V2 account
styles; no products or prices are defined in Blade.

Current state comes from CustomerBillingStateService, persisted subscriptions,
wallets and storage usage. Complimentary access is labelled separately. Free does
not display an old recorded term as paid coverage. Rendering/filtering/review never
creates payments, reconciles, renews or deletes files. Catalog numeric fields supply
allowances/prices; numeric marketing strings are omitted because existing prose
can disagree with actual allowance fields.

## Authoritative checkout and policy

CustomerPurchaseCheckout validates fresh catalog mode/interval and configured
available methods, then delegates to CreatePlanSubscriptionPayment,
CreateStorageSubscriptionPayment or CreateAddonPayment. It does not implement a
FIB client or grant credits. Only methods supported by those actions are offered;
add-ons use their existing `fib` method/fee path. Coupons, final prices, fees and
eligibility remain authoritative in those actions.

Each plan record owns its payment_mode. The selector filters records; it cannot
turn a recurring record into a one-time purchase. Recurring collection remains
provider-scheduled. One-time purchases use existing fixed-term fulfillment;
add-ons are one-time App purchases, not API products or subscription bucket grants.
Checkout redirects to the existing owned FIB page with its QR/app links and
pending/success/failure/expired/review states. Browser status is not payment proof.

Paid service switches apply after verified payment, without invented proration.
Existing fulfillment supersedes local old subscriptions and attempts remote
retirement using its existing FIB action; failure remains an operational issue.
Later old observations retain Phase 2 current-subscription checks. Returning to
Free delegates to ScheduleServicePlanCancellation through the known boundary;
storage cancellation delegates separately to ScheduleStoragePlanCancellation.
No direct subscription-row edits or page-level renewal jobs were introduced.

**PlanSwitcher resets subscription credits to the target monthly allowance; it
does not add remaining subscription credits.** Its pure servicePlanBalanceResult
calculation supplies both the actual switch and preview. App/API subscription
balances, retained add-ons and estimated totals are separate. Intervening usage can
change the final balance. Same-plan checkout, including interval changes, remains
unsupported by the current Create actions. Action labels compare service monthly
prices or storage capacity, not merchandising sort order.

## Duplicate entry and storage boundaries

V2 holds a customer/purchase-kind cache lock during checkout creation and reuses
owned unfulfilled pending/review payments. GET/refresh never creates payment.
Existing payment locks, evidence checks, durable recurring allocations and
CreditOrder/add-on replay guards remain authoritative. The cache lock coordinates
V2 entries only; it does not prove cross-V1/V2 or distributed worker concurrency.
Existing ambiguous provider-create failures still require operational review.

- Storage lacks automatic retirement of the old remote recurring subscription.
  V2 blocks replacement while a fulfilled storage provider subscription lacks a
  persisted CANCELED/CANCELLED/EXPIRED status. Local cancellation intent alone does
  not unblock it; the customer sees a support/review explanation.
- FulfillStorageSubscription does not forward purchased billing_cycle to
  PlanSwitcher. One-time yearly purchase without provider active_until receives
  the monthly fallback. V2 therefore blocks non-monthly one-time storage checkout.
  Recurring boundaries still use verified active_until, but missing stored interval
  metadata remains a domain follow-up.
- A smaller quota warns that files remain and future storage growth is constrained
  by existing quota enforcement. No cleanup/deletion is invoked.

These existing domain gaps are reported rather than changing economics in a UI task.

## Verification

- Final account/profile/billing run: **51 passed, 355 assertions**, including 17 new
  purchase cases covering EN/AR/KU, auth/gates, configured modes/intervals, shared
  preview versus actual switch, cancellation delegation, pending ownership/reuse,
  cache contention, sanitized errors, unavailable methods and storage blockers.
  A real add-on Create action with mocked FIB client proves replay invokes creation
  once, retains one Payment and does not grant before verified payment.
- Broad regression: **290 passed, 3 existing failures, 1,778 assertions** across
  Payments, recurring cycles, subscriptions, storage, wallets, coupons, Admin billing
  and accounts. Failures match the Phase 2 baseline: English redirect copy on Arabic
  checkout, legacy API component balance, API resource visibility. Not full-green.
  Existing payment suites cover authenticated application and callback replay.
- All 28 frontend tests and Vite build passed; scoped PHP syntax/Pint and matching
  translation catalogs checked. Tests use isolated SQLite memory, mocked providers
  and fake delivery, not the application database. These runs overlap.
- Signed-in browser: EN desktop plans/yearly/review controls; dropdown and active
  route; AR mobile storage/Free summary and RTL dropdown; KU mobile add-ons.
  Existing pending history/storage blockers were displayed without resuming a
  payment, purchasing, cancelling or contacting FIB. Actual provider payment and
  cancellation acceptance were not performed.

Prices, customer balances, history, migrations, rollout gates and AI service pages
were not changed. No seeders or maintenance ran against the application database.
