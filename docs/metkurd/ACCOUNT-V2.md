# V2 customer account pages

## Renewal and access presentation — 2026-09-14

Billing and plan purchase pages display cancellation requested/confirmed separately
from paid access dates. Pending replacement checkout, previous subscription expiry
and old-provider cancellation awaiting confirmation use owned local reads and
EN/AR/KU translations. Cancellation records durable intent; a provider outage does
not remove paid access or lose the request. See
[RECURRING-SUBSCRIPTION-LIFECYCLE.md](RECURRING-SUBSCRIPTION-LIFECYCLE.md).


Implemented 2026-09-10. V1 remains a compatibility UI; V2 account navigation uses:

- `app.v2.profile`: `/{locale}/app-v2/profile`
- `app.v2.billing`: `/{locale}/app-v2/my-billing`

## Shared behavior

Profile and Billing view-based components inherit shared handlers under
`app/Livewire/Account`. Profile retains Customer/CustomerProfile validation,
country rules, phone verification invalidation, strong password/current-password
checks and shared email/phone OTP and password-reset pages. The phone OTP return
URL selects the originating V2 profile. No duplicate account or verification
system was introduced.

The avatar preview and independent save use the existing S3 customer avatar
location. The previous avatar is removed after the replacement is stored and
persisted. V1 has no avatar-remove action, so none was invented. Shared profile
saving retains compatibility. The phone input initializes on initial load,
library readiness and Livewire navigation, destroying the old instance before
navigation detaches its input.

## Billing read boundary

V2 reuses the V1 server-side usage queries and CustomerBillingStateService.
App/API totals and subscription/add-on buckets are displayed separately.
Period, group, tool, status, search, from and to retain URL state. Usage charts
and jobs share the selected filters; payment and ledger history use the date
window. V2 adds Vector 2/Leo filters while retaining legacy history identities.

Job, payment and ledger tables paginate on the server with separate page names.
Payments and eligible unlinked CreditOrders are scoped to the authenticated
customer. Safe projections omit provider payloads, internal provider references
and signed result URLs. Amounts use the existing payment model and legacy
currency service. Complimentary grants remain distinct from payment history.
An old recorded term is shown for review without claiming new paid coverage.

Rendering never refills, expires, repairs, polls or reconciles financial records.
Cancellation runs only through the existing ScheduleServicePlanCancellation
service after the customer confirms. V2 selection now uses the
[purchase pages](PURCHASE-V2.md); checkout and provider boundaries remain shared.
No prices, schema, customer balances, ledger history
or rollout gates were changed.

## Verification

- 34 isolated PHP tests passed, 227 assertions: V2 account routes in EN/AR/KU,
  auth/verification/gate enforcement, profile updates, avatar replacement and
  invalid files, changed-phone OTP return, mocked send/resend cooldown, password
  rules, separate wallets, ownership/filter/date/chart queries, usage/payment
  pagination, complimentary and cancellation states, sanitized failures; plus
  existing V1 profile and billing regressions.
- 28 frontend tests passed, including delayed phone-library availability, fresh
  configuration after navigation and cleanup before DOM replacement.
- Focused PHP syntax/Pint, 105 matching EN/AR/KU account translation keys and
  frontend production build verified.
- Signed-in local browser: EN desktop Profile/Billing, AR mobile Profile, KU
  mobile Billing, read-only historical date queries, scrolling tables and charts,
  live OCR filtering updating the URL, totals and history, and a Billing-to-Profile
  navigation round trip with the phone widget initialized without refresh. Browser viewport
  restored after mobile inspection.

Account changes, avatar storage, OTP messages and cancellation were tested only
with isolated fixtures/fake storage/mocked delivery. Real SMS/email delivery and
provider cancellation were not exercised. Historical subscription anomalies were
left intact. This is local UI/source verification, not deployment or native
MySQL/RDS acceptance.
