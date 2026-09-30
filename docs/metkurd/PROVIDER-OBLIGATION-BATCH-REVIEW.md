# Batch provider obligation review — 2026-09-30

Source implementation and isolated acceptance only. No production connection,
deployment, application migration, real FIB call or cutover was performed. The
local reproduction database was inspected read-only. This procedure supplements
[the final deployment runbook](PRODUCTION-DEPLOYMENT-FINAL.md); it does not authorize
execution or replace its maintenance, backup, identity and zero-blocker gates.

## Architecture and boundaries

Two CLI commands reuse fresh `admin.finance` + `admin.reconcile` authorization,
`AdminOperationRunner`, `FibStatusEvidence`, the existing authenticated FIB client
and the existing individual `ProviderCoverageDispositions` authority. No new Admin
route, generic “resolved” button, provider cancellation path or financial policy.

`ProviderReviewSnapshot` bulk-loads relevant tables and streams events once.
Inventory performs no per-obligation SQL or HTTP. Ordinary Free/provider-unrelated
subscriptions are excluded. Items are grouped into:

| Category | Meaning / permitted next step |
| --- | --- |
| `confirmed_retired` | Accepted terminal unpaid evidence, or confirmed cancellation with no remaining paid term; no new action proposed. |
| `paid_coverage` | Paid/applied/collection evidence; individual interval review required. Only eligible confirmed-cancellation cases propose coverage approval. Existing dispositions are identified without proposing duplicate approval; final cutover revalidates them. |
| `draft_unpaid` | Exact unpaid, unbound native FIB subscription currently DRAFT; optional bounded GET or explicit merchant attestation. DRAFT itself is never safe. |
| `active_trial` | Remote renewal/activation obligation remains blocked. |
| `conflict` | Invalid identity, rejected/stale GET, pending financial review, malformed term or orphan subscription. Reason codes distinguish these cases. An otherwise exact unpaid/unbound DRAFT with stale GET evidence can request a fresh GET only, never merchant retirement over that conflict. |
| `retained_fake_history` | Narrowly corroborated fake/manual history; retained, with no remote action. |
| `unresolved` | Other provider obligation; no automatic disposition. |

These are review categories, **not** a replacement for the complete cutover
classifier. A batch category count is not the number of final cutover blockers.
Paid ACTIVE/TRIAL objects remain in the stricter paid-coverage group with their
provider status visible. Non-FIB, one-time, orphan and conflicting cases receive
no generic GET/merchant exception. Existing paid coverage approval remains stricter
than unpaid retirement. New actions are refused after an audited billing epoch.

## Commands and private manifest

Both commands use the existing explicitly configured cutover target identity.
`BILLING_CUTOVER_ENABLED` still defaults false. No connection override, force,
ignore, implicit production identity or automatic capability provision is added.
Production requires the existing native MySQL/RDS identity checks. Read-only
review still requires that identity configuration and an active authorized Admin.

```sh
php artisan billing:provider-obligations-review --target=production --admin=1 --details --export --merchant-export
```

This makes no database writes or provider requests. `--export` writes two separate
files directly in `storage/app/private/billing`: an immutable source review and an
editable worksheet with prefilled exact identities. `--merchant-export` adds a
compact no-customer-PII list. Without export options, only console output is produced.
Protect console output too: it contains internal/customer/provider identifiers.

Source/worksheet structure:

```json
{
  "review": {
    "version": 1,
    "identity": {"target": "production", "configured_schema": "<verified schema>"},
    "code": {"revision": "<release SHA>", "source_hash": "<source SHA-256>"},
    "reviewed_at": "<ISO-8601 timestamp>",
    "fingerprints": {"payments": "<hash>", "payment_events": "<hash>"},
    "counts": {"draft_unpaid": 1},
    "items": ["<generated allowlisted review objects>"],
    "manifest_hash": "<immutable source hash>"
  },
  "decisions": ["<generated per-item decisions; selected=false initially>"]
}
```

This illustrates structure, not a hand-authored executable packet. Generated
identity/fingerprints include the complete actual fields and all relevant tables.
No raw payload, token, QR/app URL, customer name, email or audio/text content is exported.
The source hash includes PHP under app/config/migrations and composer.lock; the
release SHA comes from Git or `PROVIDER_REVIEW_RELEASE_REVISION` for a Git-free
artifact. Do not edit the review section. Edit only selected decision fields.

The source manifest hash binds database identity, raw row fingerprints, exact
Payment/subscription/event IDs, classifications/actions, review time and source
revision. **The apply dry-run returns a separate `review_hash` for the entire
worksheet including decisions.** Use that hash for execution, not `manifest_hash`.
Every relevant row change or code change invalidates the worksheet. Full-table
fingerprints deliberately also reject unrelated changes within those tables.
Re-export after each completed batch. Never copy decisions blindly onto new evidence.

Files use exclusive random filenames, directory mode 0700, file mode 0600 and source
mode 0400 where supported. Windows requires verified private NTFS ACLs. File reads
are capped at 10 MiB, JSON-only, directly inside private billing storage, with
symlink/public-path refusal. Never publish, email the full manifest, or commit it.
Filesystem permissions plus revalidation protect the source; this is not a signed
FIB document or cryptographic proof that database contents originated at FIB.

## One reviewed set, one durable parent operation

Select the desired generated decisions. One Payment can have only one selected
action. Keep a durable UUID and reason unchanged across dry-run/execution/replay.

```sh
php artisan billing:provider-obligations-apply --manifest="<private worksheet.json>" --admin=1 --operation="<UUID>" --reason="<specific reviewed purpose>" --dry-run
php artisan billing:provider-obligations-apply --manifest="<same worksheet.json>" --review-hash="<apply dry-run hash>" --admin=1 --operation="<same UUID>" --reason="<same purpose>" --execute
```

Omitting `--execute` is read-only, including no provider calls. Fresh capability
checks, exact identity/hash validation and all selected decisions must pass before
the first action. Execution locks and validates the fresh snapshot in a single
parent Admin operation transaction. Coverage records use deterministic child UUIDs
under that parent and reuse the unchanged single-payment approval validator.
One invalid decision or persistence failure rolls back the whole set, including
previous coverage inserts and child audits. The parent intent/failure audit remains
for safe recovery. No item is silently skipped.

An exact completed UUID replay returns its original result without another GET or
approval. Changed operator/reason/packet under that UUID is rejected. Replaying a
completed result does not certify current evidence; always run fresh inventory.
A crash before commit may require repeating authenticated GETs, which are read-only.
No remote cancellation or financial write needs compensation.

Network failures and nonterminal GET states are deliberately committed as
**unresolved observations**, not successful retirement. A completed batch means all
selected observations/approvals were durably recorded, not that cutover is safe.
Retry unresolved GETs using a fresh review and a new operation UUID.

## Paid terms

Each `coverage_approval` decision includes its own Payment/customer/provider object,
service/storage subscription, exact authenticated evidence event, `coverage_start`,
`coverage_end`, `coverage_confirmed=true` and nonempty `review_reference`.
Generated observed dates are proposals; an operator must confirm the actual interval
against merchant evidence. No generic end date or batch-wide approval interval.
The strict existing validator checks full identity, current paid/applied state,
renewal-stop evidence, exact observed dates and conflicting/newer subscriptions.

Approval only appends individual coverage records and operation/audit history.
Payments, subscriptions, wallets, ledgers, orders and allocations remain unchanged.
Only a later separately approved cutover may activate retained coverage under its
committed epoch. Its exact millisecond end boundary, provider provenance and no-new-
credit-cycle rules remain unchanged. See [individual authority](PROVIDER-OBLIGATION-REVIEW.md).

## DRAFT GET verification

Select `draft_get` decisions only for the prefilled exact unpaid/unbound objects.
Existing authenticated subscription GET is called serially. OAuth token acquisition
may POST to the token endpoint; there is **no cancellation/create/payment/refund POST**.
No fulfillment, polling lifecycle dispatch, Payment status rewrite, wallet debit,
credit allocation or subscription mutation runs.

| Setting | Default | Enforced bound |
| --- | --- | --- |
| `PROVIDER_REVIEW_MAX_ACTIONS` | 100 | 1–100 selected actions |
| `PROVIDER_REVIEW_MAX_GETS` | 25 | 1–25 selected objects |
| `PROVIDER_REVIEW_GET_INTERVAL_MS` | 1000 | 500–10000 ms between object request starts |

The shared FIB transport must have 1–3 configured attempts, 1–30 second timeout and
0–10000 ms retry delay; otherwise the batch refuses before HTTP. Defaults remain
2 attempts/15 seconds/200 ms. The object cap is not an HTTP-attempt count: up to
75 subscription GET attempts are possible, plus bounded token exchange attempts.
Existing intra-request retries use their configured delay; object pacing does not
replace that delay. Run small batches on one operator node with writers quiesced:
the transaction holds review locks during bounded HTTP. Native MySQL lock duration,
deadlock recovery and realistic provider latency require acceptance before use.

Accepted exact CANCELLED/CANCELED/REJECTED evidence can retire only an unpaid,
unbound object with no historical collection/coverage evidence. DRAFT, ACTIVE,
TRIAL, expired checkout, `non_cancelable`, missing IDs, malformed/mismatched replies,
auth/network failure and unknown statuses remain blocked. No irreversible DRAFT
expiry semantics are inferred. Safe PaymentEvents and durable review evidence are
appended; raw provider responses/errors are not placed in these records/exports.

## Merchant/FIB bulk return

Send only the compact merchant export through an approved channel. It contains
Payment ID, provider object ID, safe local reference, statuses, created time and
paid indicator. It deliberately excludes customer identity and raw payloads.
Retain the actual external response privately; compute its SHA-256 and retain a
nonsecret merchant case/reference. A status spreadsheet alone is not provider proof.

An authorized operator prepares a private JSON return tied to the source manifest:

```json
{
  "manifest_hash": "<source manifest hash>",
  "items": [{
    "payment_id": 152,
    "provider_subscription_id": "<exact exported object ID>",
    "disposition": "PERMANENTLY_NON_ACTIVATABLE",
    "review_reference": "<merchant case reference>",
    "evidence_sha256": "<SHA-256 of privately retained response>",
    "observed_at": "<reviewed evidence timestamp>",
    "attested": true,
    "no_collection": true,
    "irreversibly_non_activatable": true
  }]
}
```

Allowed retirement dispositions are CANCELLED, REJECTED and
PERMANENTLY_NON_ACTIVATABLE, all requiring every attestation above. The evidence
timestamp must be at/after the inventory and not future-dated. ACTIVE/TRIAL, DRAFT,
ambiguous collection, missing references, duplicate/wrong IDs and unexpected fields
are refused. This narrow path accepts only still-eligible unpaid unbound DRAFT
objects; paid/active/conflicting cases require their separate authority.
The hash binds an externally retained artifact; the application does not download
it or independently prove its authenticity. Admin accountability is explicit.

```sh
php artisan billing:provider-obligations-apply --manifest="<private worksheet.json>" --merchant-import="<private attested-return.json>" --admin=1 --operation="<UUID>" --reason="<merchant review purpose>" --dry-run
```

Import validates and creates a **new prepared worksheet**, never executes. It
cannot be combined with `--execute`. Review the printed path/hash, then use the
ordinary apply flow on that prepared worksheet. Only select one action per Payment;
unselect a GET decision if selecting its merchant attestation. No arbitrary CSV
status list is accepted. The database review retains the external reference, artifact
hash, attestations, manifest hash, event ID, exact source basis and Admin operation.

## Cutover authority and migration

`ProviderRetirementEvidence` is shared by `ProviderObligationInventory` and
`CutoverInventoryReader`. Valid terminal unpaid proof requires unchanged exact
Payment/subscription/event/order/allocation basis, completed audited parent identity
and matching approval hash. A newer failed or invalid review prevents fallback to
older proof. New callback/source changes invalidate approval. “Reviewed” alone is
never safe. Individual paid coverage still uses its separate existing authority.

One additional additive migration is required:
`2026_09_30_000002_create_provider_obligation_reviews.php`.
It preserves evidence after Payment/event retirement using logical historical IDs
and restrictive customer/Admin/operation foreign keys. No backfill, HTTP, credits
or grants. Populated rollback is refused. It follows the retained-coverage migration
`2026_09_30_000001`. Neither was applied to the reproduction database in this task.
The release now contains 88 source migrations (65 baseline + 23 additions).

## Local reproduction and acceptance

Read-only loopback inspection of `metkurd_local_260930`, 2026-09-30:

| Review category | Items |
| --- | ---: |
| Confirmed retired | 13 |
| Paid coverage | 12 |
| DRAFT/unpaid | 38 |
| ACTIVE/TRIAL | 0 |
| Conflicting evidence | 92 |
| Retained fake/manual history | 6 |
| Other unresolved | 1 |
| Total | 162 |

Payment 161 remains paid-coverage review, with matched Event 216568 and verified UTC
term 2026-09-29T16:42:15.998+00:00 through 2026-11-29T16:42:15.998+00:00. No automatic
date correction or approval. Payments 152–154 and 156–160 are unresolved DRAFTs;
155 has a stale/invalid persisted GET: it remains conflicting, but can request a
fresh exact authenticated GET; merchant attestation is unavailable while conflicted.
Intent 15 and Order 1 remain
corroborated fake/manual retained history. Conflicting legacy observations are not
bulk-cleared just to reduce the exception count.

The 92 conflicts break down into 32 missing/unsafe provider identities, 33 financial
review flags, 9 subscription identity conflicts, 17 noncurrent/invalid persisted
GET observations and 1 orphan/unmatched provider subscription. A fresh GET may
resolve eligible stale observations; it cannot override financial or ownership
conflicts, and previous collection evidence is retained even after its review basis
changes. No blanket DRAFT retirement or generic “mark resolved” action exists.

All ten before/after fingerprints matched: credit_wallets 2330, credit_ledgers 8516,
customer_service_subscriptions 1199, customer_storage_subscriptions 1167, payments
161, payment_events 39074, payment_intents 1, credit_orders 35,
subscription_credit_allocations 0, admin_audit_events 1. No corrective command ran
against the local copy. These are diagnostic counts, not an executable production
manifest or evidence of current production state. Local MariaDB is not native RDS
MySQL acceptance.

Remaining acceptance: native MySQL migrations/JSON hash stability/locks/rollback,
privately reviewed real merchant evidence, individually confirmed paid intervals,
approved controlled provider GET acceptance, operator filesystem permissions,
production identity/configuration/backup/restore/writer control and zero remaining
cutover blockers. Source tests cannot replace any of these.

Verified in isolated SQLite `:memory:` with array cache/session, synchronous queue
and stray HTTP blocked: **241 tests / 3037 assertions passed** across batch review,
individual provider coverage, provider evidence, payment-domain cutover, target
identity, diagnostic inventory and recurring-action lifecycle. This includes 52
batch cases: 120 mixed objects with fewer than 22 inventory queries, deterministic
manifest/tamper rejection, Free exclusion, strict merchant import, all GET outcomes,
bounded calls, authenticated transport with no cancellation POST, financial
preservation, exact replay, fresh capabilities, stale proof, historical collection
aliases, per-item dates, late-insert rollback and a mixed-disposition cutover fixture.
Payment 161-shaped coverage and fake Intent 15/Order history regressions pass.

The existing four focused Admin frontend suites also pass **41 tests**. PHP lint
and focused Pint pass for all 13 changed/new PHP files; `git diff --check` passes.
No frontend assets changed, so a Vite rebuild was not required. Real provider and
native MySQL behavior remain untested; the cutover fixture is not application execution.

```sh
php vendor/bin/pest tests/Feature/Billing/ProviderObligationBatchTest.php tests/Feature/Billing/ProviderCoverageDispositionTest.php tests/Feature/Billing/ProviderObligationEvidenceTest.php tests/Feature/Billing/PaymentDomainCutoverTest.php tests/Feature/Billing/CutoverTargetsTest.php tests/Feature/Billing/CutoverInventoryTest.php tests/Feature/Billing/RecurringActionLifecycleTest.php --compact
node --test tests/Frontend/admin-ui.test.mjs tests/Frontend/admin-localization.test.mjs tests/Frontend/admin-customer-workspace.test.mjs tests/Frontend/admin-billing-workspace.test.mjs
```

Use the repository's isolated test environment for these commands, never a live
connection or cached production configuration.

## Safe operator sequence after separately approved deployment

1. Complete release, native MySQL and migration acceptance under the final runbook.
   On one authorized node, install its exact protected cutover identity assertions
   for review. This does not authorize reset execution; unchanged unresolved-provider,
   backup, maintenance and stopped-writer guards still block cutover.
2. Quiesce writers and export a fresh private review/worksheet/merchant list.
3. Review/select bounded GET decisions if needed; dry-run, record packet hash/UUID,
   then separately authorize apply execution. Re-export after the observations.
4. Send compact unresolved IDs to merchant/FIB; retain the external reply privately.
   Import only explicit eligible attestations; validate/review/apply the new worksheet.
   Re-export after each set; unchanged DRAFT and exceptional cases stay blocked.
5. Confirm each legitimate paid interval/reference, select its coverage decision,
   dry-run and atomically apply with one parent UUID. No giant per-customer commands.
6. Run `php artisan billing:cutover-inventory --details`. Resolve remaining exceptions
   through their appropriate authority; do not edit financial rows or invent evidence.
7. Re-quiesce writers, refresh backup/recovery evidence and run:

```sh
php artisan billing:cutover-reset-payment-domain --target=production --dry-run --admin=1
```

Only a fresh **complete zero-blocker** dry-run can proceed to the existing separately
approved cutover procedure. Batch success, source tests and old review hashes are
never cutover authorization. No cutover execute command is part of this batch task.
