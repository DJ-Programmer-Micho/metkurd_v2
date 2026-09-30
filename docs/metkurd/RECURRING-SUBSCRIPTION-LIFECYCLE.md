# Recurring subscription actions — 2026-09-14

## Legacy cancellation evidence review — 2026-09-30

The shared cancellation service now exposes read-only confirmation from canonical
context or the latest matched authenticated GET event, revalidated by FibStatusEvidence.
Legacy `provider_status_ignored` events can confirm renewal cancellation while the
paid Payment remains paid. Optional `payments:review-fib-cancellation` uses explicit
Admin identity/capabilities and a durable operation to obtain fresh GET evidence;
it preserves paid dates, retains the longest observed coverage bound and does not
POST cancellation, refill or transfer access. Future/disputed coverage still blocks
cutover. See [the operator review procedure](PROVIDER-OBLIGATION-REVIEW.md).

## Contract and ownership

The [official FIB subscription collection](https://documenter.getpostman.com/view/30814842/2sB3BHn9i6)
documents create, get and cancel: `POST /protected/v1/subscriptions`,
`GET /protected/v1/subscriptions/{subscriptionId}`, and
`POST /protected/v1/subscriptions/{subscriptionId}/cancel`. The collection was read
through its public Postman collection endpoint during this review. The cancel
request uses bearer authentication and no request fields. The local cancellation allowlist uses ACTIVE/TRIAL;
other unconfirmed states remain pending for review. Reasons, replacement identities, requested/confirmed
timestamps and effective access dates are MetKurd metadata, never FIB parameters.
The documented states are DRAFT, ACTIVE, TRIAL, REJECTED and CANCELLED; a network
failure does not create a remote FAILED state. Older aliases remain readable.

## Cancellation and paid access

`ProviderSubscriptionCancellation` commits a local intent and PaymentEvent before
provider work. It projects the context into the owned normalized service/storage
subscription, preserves `canceled_at`, disables local auto-renew and retains the
previous paid-through boundary. Customer cancellation rechecks ownership, current
subscription, fulfilled payment and future coverage under customer/payment/subscription
locks. Paid operator cancellation uses the same intent through the existing guarded
`payments:fib:cancel-subscription` command. Admin permissions and review actions are
unchanged; this does not add a customer financial correction.

POST acceptance records `requested`, not fabricated CANCELLED. Authenticated GET,
validated through `FibStatusEvidence`, records `confirmed`. An HTTP failure retains
`pending` (or `requested` after acceptance), its attempt and next retry. The provider
attempt runs after the enclosing transaction commits, so plan fulfillment cannot
roll back because old-provider cancellation fails. An enclosing transaction rollback
sends no cancellation. A repeated request reuses its persisted intent. Accepted POSTs
are followed by GET checks without repeated POSTs. An ambiguous transport failure can
require a retry if GET still reports an eligible state; remote exactly-once delivery
is not claimed.

Callbacks remain wake-ups; shared authenticated GET/evidence validation supplies
provider state. Direct FIB cancellation and customer cancellation retain paid access
through the verified `activeUntil`. Neither cancels a historical paid Payment nor
changes wallets, refunds, ledger entries or orders. At the boundary, existing
`ExpireSubscription` ends paid access and establishes Free once, preserving the
existing balance/allocation policy. Annual monthly fair-use slices within an already
verified prepaid term remain governed by Phase 2.

## Renewal and plan replacement

A new recurring collection must advance verified `lastPaymentAt`; an ACTIVE response
or an `activeUntil` extension alone cannot buy another period or allocate credits.
Incomplete observations retain previously verified collection evidence. At expiry
without a verified further cycle, local Free can become effective even if FIB is
unavailable. A `failed_renewal` termination intent remains pending remotely, without
asserting that FIB rejected a payment. Admin's missing-collection indicator means no
newer verified collection at the retained boundary, not a bank decline.

Upgrade/downgrade creates a new fixed-price FIB subscription. Starting or failing
that checkout leaves the old current subscription and renewal untouched. Only shared
verified payment fulfillment switches the normalized plan and applies existing App/API
allowances once, preserving add-on buckets. `PlanSwitcher` retains the old paid-through
date in historical metadata and ends/supersedes its authority. Fulfillment links and
requests cancellation only for the previous normalized subscription superseded by
this payment; it does not sweep unrelated historical provider references. Replacement
subscription/payment IDs are stored locally. No proration, refund or existing FIB
subscription amount update is invented.

Old cancellation failure leaves the new paid plan authoritative and old cancellation
pending. Late collections after a cancellation intent, local expiry or supersession
retain observed evidence and enter financial review; they do not extend access,
restore the old plan or allocate a new cycle. A benign later status check cannot erase
that review. Phase 2 durable allocation identities and customer-lock ordering remain.

## Recovery and presentation

Under the existing `fib.reconciliation.enabled` scheduler gate,
`payments:reconcile-fib-cancellations --limit=100` runs every five minutes using the
existing scheduler locks. It considers due intents including ended/superseded rows,
prioritizes older attempts, and confirms cancellation via authenticated GET. A
`--customer-id` filter and read-only `--dry-run` are available. Confirmed cancellation
and persisted cancellation intents are excluded from ordinary renewal polling;
pending cancellation has its own recovery loop. Manual invocation performs provider
work unless `--dry-run` is supplied. No gate is enabled by these source changes.

V2 Billing and Subscription/Storage Plans show renewal requested/confirmed separately
from paid access, renewal date, pending replacement checkout, previous-plan expiry and
old remote cancellation pending. A persisted REJECTED observation can show a renewal
payment issue; a transport failure alone cannot. EN/AR/KU copy uses existing page direction and
mixed-text handling. Admin Operations payment-review includes remote pending
cancellation even for fulfilled payments; its projections separate local access,
provider state, request/confirmation, paid-through boundary, replacement and expiry.
Deep payment evidence retains existing finance/reconcile gates. Rendering only reads
owned local evidence and never calls FIB.

## Verification boundary

Verification uses isolated SQLite fixtures, fake notifications/storage and mocked FIB.
No application/production database mutations, migrations, seeders or live FIB cancel
were executed. The Payment History Reset implementation is outside this task and
unchanged. Native MySQL/RDS concurrency, deployed scheduler execution and live FIB app
presentation require separate operator acceptance. Existing historical rows are not
backfilled into new cancellation intents merely by this source update.

### Recorded checks

- 17 focused recurring action tests passed, including EN/AR/KU HTTP rendering,
  transport failure/retry, rollback-before-HTTP, duplicate accepted cancellation,
  late collection review and read-only Admin attention.
- Across the focused and broad regression runs (with corrected fixtures rerun),
  430 distinct PHP cases passed; one pre-existing legacy Arabic payment-page test
  still expects the English text `Redirecting you to your app home` and fails.
  This includes Billing Phase 1/2, FIB creation/fulfillment/callback/reconciliation,
  service/storage separation, V2 account/checkout and Admin P0/P2/deletion controls.
- 23 Admin/account/payment frontend tests passed, including new lifecycle locale
  key/placeholder parity. PHP syntax and focused Pint passed on 28 PHP files.
- Cancellation POST disables blind transport retries; the durable recovery loop
  performs a fresh GET first. Customer paid-subscription fixtures now explicitly
  record applied fulfillment; existing expiry source naming remains compatible.
- No asset source changed, so no asset rebuild was needed. HTTP rendering above
  is isolated test rendering, not interactive browser or live provider acceptance.
