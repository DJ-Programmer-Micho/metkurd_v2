# Batch provider obligation retirement — 2026-09-30

## Historical FIB environment follow-up

See [the read-only provenance audit](FIB-PROVENANCE-AUDIT.md). Manifests now expose
creation provenance and the additional `nonproduction_provider_history` category.
Corroborated staging creation evidence excludes an object from production remote
actions, including retry-reviewed selection; it does not claim remote cancellation.
Existing financial and paid-coverage blockers remain. Unknown and production 404s
receive no retirement exemption. No new migration/configuration. Source/evidence
hashes change: regenerate review worksheets rather than editing old packets.

Source implementation and isolated acceptance only. No production access, deployment,
application migration, real FIB request or application cutover was performed. The
local reproduction database was inspected read-only. This supplements the
[deployment runbook](PRODUCTION-DEPLOYMENT-FINAL.md), not its execution authority.

## Final scope and existing authority

The existing cutover deletes all old rows in exactly five processing tables:
payments, payment_events, payment_intents, payment_transactions and
payment_webhook_events. Customers, wallets, balances, ledgers, orders, allocations,
valid subscriptions, jobs, files, usage and Admin history survive under existing
fingerprint guards. Reviewed nullable processing links may be detached with their
original mappings retained in the audit. Immutable allocation/ledger FK dependencies
still block deletion. Old poll/callback rows do not become live V2 processing state.

Corroborated fake/manual intent history makes no FIB requests. Its allowlisted intent,
transaction and order provenance is archived in the immutable cutover audit under
legacy_processing_provenance, alongside original table fingerprints/link mappings.
Its processing rows are deleted too. This supersedes the earlier exception retaining
fake intents/transactions inside live processing tables.

The two existing review/apply commands reuse fresh active Admin finance/reconcile
authorization, AdminOperationRunner, exact configured CutoverIdentity,
FibStatusEvidence, the existing cancellation service and ProviderCoverageDispositions.
No identity override, generic resolved flag, refund, fulfillment, wallet mutation or
automatic refill. New actions are refused after a committed billing epoch.
BILLING_CUTOVER_ENABLED remains false by default; review configuration is not reset
approval. Production still requires native MySQL/RDS identity and readiness checks.

The bulk snapshot streams events and loads financial evidence without per-obligation
SQL in inventory. Categories remain confirmed_retired, paid_coverage, draft_unpaid,
active_trial, conflict, retained_fake_history, nonproduction_provider_history and unresolved. They are not final
cutover blocker counts. Remote eligibility is separate: stale GET can be refreshed;
missing/unsafe identity, ownership/reference mismatch, financial review, duplicate
objects or synthetic/mock/revenue-excluded Payments cannot trigger HTTP. Ordinary
Free rows are excluded. Local synthetic Payment 176 must never be polled.

## Contract and remote state handling

| Authenticated current state | Batch behavior |
| --- | --- |
| CANCELLED/CANCELED or REJECTED | Verified renewal stop, no POST. Only unpaid/unbound objects without collection/coverage evidence can be confirmed retired; paid terms remain separate. |
| ACTIVE or TRIAL | Existing cancellation service, one fenced POST, then authenticated GET. Nonterminal/ambiguous results stay unresolved. |
| DRAFT | GET only, unresolved; no fabricated local CANCELLED state. |
| Unknown, malformed, wrong identity or failed GET | Unresolved, no cancellation POST. |

The existing isCancelableProviderStatus permits ACTIVE/TRIAL. The public
[FIB subscription documentation](https://gist.github.com/first-iraqi-bank-bot/3e78260f90b143d0c5b853685ca0fb01)
describes cancellation of active subscriptions but does not establish DRAFT
cancellability. No real transition was tested. Obtain an applicable FIB contract or
reviewed merchant disposition for unresolved DRAFTs; the one-time-payment cancel API
is not proof. “No Service Available” is a local audit reason, never a FIB parameter.

## Exact production commands after separately approved deployment

Use one operator node, accepted native MySQL migrations and the runbook's protected
identity/configuration. Quiesce payment writers/callback processing to prevent drift.
Protect console output and private files, which contain internal/provider identities.

```sh
php artisan billing:provider-obligations-review --target=production --admin=1 --details --export --remote-limit=25
php artisan billing:provider-obligations-apply --manifest="<printed worksheet_file>" --admin=1 --operation="<new UUID>" --reason="No Service Available" --dry-run
php artisan billing:provider-obligations-apply --manifest="<same worksheet_file>" --review-hash="<apply dry-run review_hash>" --admin=1 --operation="<same UUID>" --reason="No Service Available" --execute
```

Review/export and apply dry-run make no DB writes or provider calls. --remote-limit
requires export and preselects the next 1–25 eligible, never-reviewed objects in
Payment-ID order with exact identities filled. No customer-by-customer CLI editing.
Repeat fresh review/export for the next group until selected_objects=0; empty apply
is refused. A completed group can contain unresolved observations. Re-export after
each completed group; success is not cutover authorization.

For interruption, retain the original file/hash/UUID/reason and repeat the same
execute command. Do not repeat dry-run against the now-changed manifest. For completed
unresolved objects, fresh review with --remote-limit=25 --retry-reviewed permits a
new review but never clears a prior POST marker. This explicit option also includes
completed terminal objects; inspect its selected set, do not blindly loop it.

## Durable execution, concurrency and bounds

The parent Admin operation commits selected intent events/review rows and source
bases before HTTP. Each object acquires a ten-minute owner lease in a short
transaction. Authenticated validated GET precedes POST. Immediately before POST,
fresh capabilities, source and lease are rechecked and post_started=true is committed.
No HTTP runs inside those transactions during application execution. The shared
cancel client makes one POST attempt with automatic POST retry disabled.

All reviews for the exact provider object are checked for prior POST markers. Timeout,
crash, final persistence failure or a new UUID cannot erase that fence. Replay checks
with GET; even a crash after the fence but before actual POST prohibits automatic
repeat. Expired leases can be reclaimed; active leases block another worker. Completed
child operations replay without HTTP. Parent completion means preparation committed,
not every remote result completed. Each safe result/event/Admin audit commits separately.

Failed, pending, tampered or contradictory remote review cannot reuse older cancellation
proof. Changed source stays unresolved. Pre-POST paid timestamps survive if cancellation
removes response dates; individual paid-interval approval is still required. Raw provider
replies/errors are not stored by the batch. Changed Admin/reason/packet for a UUID is
rejected. Remote effects cannot roll back with SQL; no false atomic rollback promise.

Cap: 25 objects, or configured lower PROVIDER_REVIEW_MAX_GETS. Object spacing is
PROVIDER_REVIEW_GET_INTERVAL_MS (default 1000, bounded 500–10000 ms). Each object can
require two GETs, one POST and token exchange. Shared GET configuration must bound
attempts 1–3, timeout 1–30 seconds and retry delay 0–10000 ms. Object count is not HTTP
attempt count. Full snapshots are bounded by historical dataset size, not 25 rows.
Native MySQL lock duration, deadlock/lease behavior and actual latency need acceptance.

## Paid access and exceptional merchant review

After remote batches, export a normal worksheet:

```sh
php artisan billing:provider-obligations-review --target=production --admin=1 --details --export --merchant-export
```

Normal decisions are unselected. Each coverage_approval includes exact Payment,
customer/provider/subscription/event and observed dates. Confirm each legitimate
interval, set coverage_confirmed=true and a nonsecret review_reference; activeUntil
alone is not interval approval. Select eligible coverage decisions together and use
the same apply dry-run/hash/UUID flow. Existing strict paid/applied/bound/current-access
validation remains. Local coverage/merchant/GET-only actions remain atomic, max 100
local actions and max 25 GETs. They cannot be mixed with remote_retire actions.

Approval writes disposition/audit only. Cutover revalidates it and retains the same
subscription through its reviewed exact boundary. No fake Payment, V2 revenue,
recurring allocation or credit refill. Compensation remains a separate operator
decision through the existing audited credit mechanism.

Merchant attestation is an exception after durable unresolved API review, limited
to exact unpaid/unbound DRAFT with no collection/term or identity conflict. It cannot
override paid/active/unknown/conflicting objects. Required fields are disposition
(CANCELLED, REJECTED or PERMANENTLY_NON_ACTIVATABLE), review_reference, evidence_sha256,
current observed_at and explicit attested/no_collection/irreversibly_non_activatable.
Privately retain the real evidence; arbitrary returned status lists are not approval.

```sh
php artisan billing:provider-obligations-apply --manifest="<private worksheet>" --merchant-import="<private attested return>" --admin=1 --operation="<UUID>" --reason="No Service Available" --dry-run
```

Return JSON binds manifest_hash and exact payment_id/provider_subscription_id in each
items entry. Import is prepare-only and prints a new worksheet: inspect it, dry-run
and apply its hash through separately approved execution. Import cannot use --execute.
Only one action per Payment may be selected.

## Private files and two existing migrations

Immutable source and separate worksheet live directly in storage/app/private/billing,
with random exclusive filenames, directory 0700, file 0600 and source 0400 where supported.
Verify private NTFS ACLs on Windows. Direct local JSON only, max 10 MiB; no symlinks/public
paths. No token, QR/app URL, customer name/email or raw provider payload is exported.
Manifest hashes bind DB identity, release/source PHP+composer.lock, review time and
full evidence fingerprints; apply hashes also bind decisions. Never hand-edit review.

The existing two migrations suffice; no third migration:
2026_09_30_000001_create_provider_coverage_dispositions.php and
2026_09_30_000002_create_provider_obligation_reviews.php. Existing JSON evidence stores
preparation, lease and POST fences. Logical historical Payment/event IDs survive
retirement with restrictive customer/Admin/operation FKs. Populated rollback is
refused. No migrations were applied to the reproduction or production database.

## Local reproduction and remaining acceptance

Read-only loopback metkurd_local_260930 has 162 review items: 13 confirmed_retired,
12 paid_coverage, 38 draft_unpaid, 0 active_trial, 92 conflict, 6 fake/manual, 1 unresolved.
Separate remote eligibility identifies 73 objects: 21 REJECTED, 2 CANCELLED, 7 ACTIVE,
43 DRAFT. Therefore 30 have a potential automatic confirmation/cancellation path,
subject to current GET and successful confirmation. None were remotely processed.
If all 43 remain DRAFT, those plus 83 non-fake items outside eligibility (82 conflicts
and one unresolved item) leave potentially
126 exceptional review items, not 126 proven provider debts. Paid approvals are separate.

This copy identifies only one customer via the seven-day recent-paid marker:
Customer 1182 / Payment 161 / service subscription 1216. Event 216568 reports UTC
2026-09-29T16:42:15.998 through 2026-11-29T16:42:15.998; old local coverage ends in October.
Confirm actual paid interval before preservation. The user's second recently paid
customer is not established by this copy: obtain its identity before final cutover.
No compensation or refill was applied.

All ten protected fingerprints remained unchanged: wallets 2330, ledgers 8516,
service subscriptions 1199, storage subscriptions 1167, payments 161, events 39074,
intents 1, orders 35, allocations 0 and Admin audits 1. Local MariaDB 10.4.28 is not native
MySQL/RDS acceptance or a production executable manifest.

Isolated SQLite tests mock all HTTP and cover bounds, identities, GET/POST/GET,
closed/DRAFT no-POST, transport no-retry/no-reason, committed fences, persistence
failure/replay, permissions, stale proof, merchant exceptions, paid access and
all-five-table retirement with financial fingerprints. Native MySQL JSON/locks/
commit/rollback, concurrent leases, real provider acceptance, both paid customers,
merchant evidence, release/configuration, filesystem permissions, backup/restore
and stopped writers remain acceptance requirements.

Final source regression: **269 tests / 3268 assertions passed** across the eight PHP
suites below. All **41 focused Admin frontend tests** passed. PHP lint and focused
Pint passed for all 13 changed/new PHP files; git diff --check passed. No frontend
assets changed, so no Vite rebuild was required. Tests use SQLite :memory:, array
cache/session and mocked HTTP; fixture cutovers are not application cutover execution.

```sh
php vendor/bin/pest tests/Feature/Billing/ProviderObligationBatchTest.php tests/Feature/Billing/ProviderCoverageDispositionTest.php tests/Feature/Billing/ProviderObligationEvidenceTest.php tests/Feature/Billing/PaymentDomainCutoverTest.php tests/Feature/Billing/CutoverTargetsTest.php tests/Feature/Billing/CutoverInventoryTest.php tests/Feature/Billing/RecurringActionLifecycleTest.php tests/Feature/Payments/FibSubscriptionRepairCommandsTest.php --compact
node --test tests/Frontend/admin-ui.test.mjs tests/Frontend/admin-localization.test.mjs tests/Frontend/admin-customer-workspace.test.mjs tests/Frontend/admin-billing-workspace.test.mjs
```

Use the repository's isolated test environment, never a live connection or cached
production configuration.

After those reviews, run the existing read-only final checks:

```sh
php artisan billing:cutover-inventory --details
php artisan billing:cutover-reset-payment-domain --target=production --dry-run --admin=1
```

Only a fresh complete zero-blocker dry-run may proceed to the separately approved
cutover procedure. No cutover execute command is authorized/performed in this task.
