# Historical FIB environment provenance — 2026-09-30

New V2 Payments created under the [2026-10-01 compact contract](PAYMENT-PERSISTENCE-V2.md)
retain the exact HTTPS creation host instead of the full response link. Creation
row/event identities, request and response must still match. The historical branch
and the counts/evidence below are unchanged; no historical row is converted.

## Scope and evidence

Read-only investigation of loopback database `metkurd_local_260930`; no production
connection, FIB call, application write, migration, deployment or cutover. Database
location and APP_ENV do not identify the FIB environment. User-reported production
batch results are not present in this older local snapshot and are not independently
verified by this investigation.

The first 50 requested subscription objects contain **9 confirmed staging/test,
41 confirmed production, 0 unknown**. Classification uses the successful creation
response, not subsequent 404s or local payment status. Each has exactly one matching
`provider_subscription_created` / `customer_checkout` event: provider, object type,
subscription ID, local reference, full response and request copies agree with the
Payment. FIB supplied `appLink` on HTTPS `p-stage.fib.iq` for staging or `p.fib.iq`
for production. Private link paths, QR codes, credentials and full provider references
are intentionally omitted. These are provider-returned creation links, not a guessed
merchant-reference convention. Historical source accepts them only after authenticated
subscription POST returns HTTP 201, then stores the same raw response in both places.

| Payment | Creation event | Creation event time (stored DB value) | Matched creation host |
| --- | --- | --- | --- |
| 58 | 179 | 2026-04-21 16:27:06 | p-stage.fib.iq |
| 62 | 840 | 2026-04-22 13:12:17 | p-stage.fib.iq |
| 66 | 2964 | 2026-04-25 22:57:40 | p-stage.fib.iq |
| 69 | 3048 | 2026-04-25 23:36:39 | p-stage.fib.iq |
| 72 | 3112 | 2026-04-25 23:58:23 | p-stage.fib.iq |
| 74 | 3196 | 2026-04-26 00:44:33 | p-stage.fib.iq |
| 75 | 3209 | 2026-04-26 00:45:16 | p-stage.fib.iq |
| 77 | 3237 | 2026-04-26 00:47:16 | p-stage.fib.iq |
| 78 | 4898 | 2026-04-26 16:09:32 | p-stage.fib.iq |

The other requested Payments are confirmed production by the same checks:
79, 80, 81, 82, 83, 84, 85, 86, 87, 91, 93, 96, 106, 109, 110, 111,
112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125,
126, 128, 129, 130, 131, 132, 133, 134, 135, 137, 138.

**Payment 112** is production: event **214856**, created **2026-06-14 01:45:47**, and
matching `create_response.appLink` / event `payload.appLink` use `p.fib.iq`.
Its reported authenticated production 404 does not prove staging or cancellation.
It remains unresolved; failed GET yields provider_status=null and must not POST cancel.

All 118 local subscription-type Payments were also examined conservatively: 18
staging, 77 production, 23 unknown. This secondary inventory includes missing or
insufficient creation evidence; it is not a classification of one-time payments,
or a claim about current production batch state. Unknown objects get no environment
exemption. No dates, customer IDs, hourly-testing flags, DRAFT states or database
names were used to infer provenance.

## What this permits, and what remains blocked

All nine staging objects need **no production FIB cancellation**. An authenticated
production 404 is consistent with these being outside the production provider
environment; it is not the proof used to exclude them. Provenance does not assert
that the staging subscription itself was canceled.

The local payment-level cutover inventory classifies 58, 62, 77 and 78 as
`nonproduction_provider_history`. Payments 66, 69, 72, 74 and 75 retain independent
`paid_coverage_boundary_missing` review blockers. Their staging provenance does not
erase historical fulfillment, credits or local access. Linked subscription boundary
and ownership checks, financial dependencies and all full-cutover guards remain.
Thus nine remote obligations can be excluded, but this is **not nine unconditional
cutover approvals**. No full cutover command was run.

The 41 production objects remain subject to normal production retirement evidence.
This does not mean 41 cancellation POSTs: verified terminal objects need none, and
404/nonterminal/ambiguous replies stay unresolved. The local copy retains older
terminal observations for some of these objects; do not substitute those for the
user-reported newer unresolved production batch results. Fresh reviewed evidence
must follow the existing workflow after a separately authorized release.

## Historical configuration precedence

- Commit `27f61d6` (2026-04-08) already selected FIB_ENV independently of APP_ENV.
  Only the normalized value `production` selects production; other values, including
  `test`, select staging. Early generic FIB_BASE_URL / FIB_CLIENT_ID / FIB_CLIENT_SECRET
  override environment-suffixed variants. The earliest fallback base URL was staging
  even with FIB_ENV=production if no URL override existed.
- By `02e7903` (2026-04-20), before Payment 58, separate payment/subscription profiles
  existed. For each profile, URL precedence is profile-specific unsuffixed URL,
  profile-specific environment-suffixed URL, generic FIB_BASE_URL, generic
  environment-suffixed FIB_BASE_URL, then the environment's default bank host.
- Credential precedence is profile-specific unsuffixed client ID/secret, generic
  unsuffixed FIB_CLIENT_ID/FIB_CLIENT_SECRET, then generic environment-suffixed
  credentials. Each field is picked separately. Generic overrides can therefore
  defeat the FIB_ENV suffix. Current config retains this precedence.
- Callback URL fallback to APP_URL affects where MetKurd receives callbacks; it does
  not choose the FIB environment. Both staging and production creation records use
  the public MetKurd callback host.
- Consequently **APP_ENV=production with FIB_ENV=staging/test was possible**. Current
  environment values, source commit dates and current cached config cannot prove
  which credentials a historical deployment loaded. No retained deployment
  credential history was established. The adjacent creation events for Payment 78
  (staging) and 79 (production, 2026-04-26 16:10:36) show different provider hosts,
  not a proven permanent global credential-switch timestamp.

## Minimal source support

`FibProviderProvenance` reads corroborated subscription creation evidence and returns
`confirmed_test_or_staging`, `confirmed_production` or `unknown_environment`, with
only event ID and host. Missing, duplicate, mismatched, mock, synthetic, excluded,
unrecognized-host or unsafe-URL evidence fails closed. No historical metadata is
backfilled and no current config is used as historical evidence.

ProviderReviewSnapshot retains creation events during its existing bulk event pass;
there is no new per-Payment SQL query. Batch manifests include provenance and omit
production remote/draft/merchant actions for staging. Inventory admits staging
history only after existing financial and paid-boundary checks. An unpaid/unbound
staging checkout can clear its pending-processing blocker without inventing a
cancellation. Full review hashes include these source and evidence changes; old
worksheets must be regenerated. Existing audits and review rows are never rewritten.
The existing cutover audit's provider inventory retains the provenance classification,
source event ID and host. No migration or config change is required.

## Verification

- Combined isolated Billing suites: ProviderObligationBatchTest,
  ProviderObligationEvidenceTest, ProviderCoverageDispositionTest,
  PaymentDomainCutoverTest, CutoverTargetsTest and CutoverInventoryTest:
  **271 passed, 3,252 assertions**.
- After the final linked-coverage guard, focused provenance/host/coverage/404 tests:
  **25 passed, 203 assertions**. Includes production and unknown authenticated 404s
  with null provider status, durable unresolved evidence and no cancellation POST;
  staging remote selection is refused. HTTP is mocked and stray requests prohibited.
- Changed PHP files pass syntax lint and Pint; git diff whitespace check passes.
- Local SELECT-only transactions verified unchanged row fingerprints across
  credit_wallets, credit_ledgers, customer_service_subscriptions,
  customer_storage_subscriptions, payments, payment_events, payment_intents,
  credit_orders, subscription_credit_allocations and admin_audit_events.
- Tests used isolated SQLite :memory: and array cache/session. The inspected local
  copy uses MariaDB, not native MySQL/RDS; no production acceptance is claimed.
