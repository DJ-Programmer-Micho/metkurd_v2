# Explicit payment history reset

## Status — 2026-09-14

`billing:reset-payment-history` is a dedicated operator command, separate from the
unchanged conservative `billing:reset-legacy-payment-history`. It intentionally
removes approved old payment-processing history while automatically retaining the
latest active Payment. It is not checkout abandonment, reconciliation, a credit
reset, or production cutover approval. No application reset has been executed.

Implementation: `ResetPaymentHistory` delegates to `PaymentHistoryReset`. No
migration, seeder, provider request or feature-gate change is involved.

## Operator procedure

1. Export a complete backup of the intended database and verify it can be restored.
   The audit manifest records references and fingerprints, not recoverable copies
   of deleted payloads; it does not replace the backup.
2. From the intended application's repository, inspect the default read-only review:

   ```powershell
   php artisan billing:reset-payment-history --dry-run
   ```

   Omitting `--dry-run` also means review only. Confirm the reported Laravel
   environment, driver, host, port, active schema and actual server version against
   the intended database. No credentials are printed. An endpoint alone does not
   prove the destination behind a tunnel. The command accepts a single MySQL-driver
   connection; it reports the actual MySQL/MariaDB version without equating them.
3. Review the automatically selected latest active Payment's UUID, customer,
   purchase type, date, state, fulfillment, provider type and amount/currency.
   No `--keep-payment` argument is needed or accepted. Active means open or paid:
   local failed/canceled/expired/refunded rows are excluded, and the shared checkout
   state must be awaiting, confirming, review or completed. A paid Payment is not
   proof of a currently active subscription. Selection orders by `created_at`
   descending, then numeric ID descending. No active Payment means refusal, never
   an empty keep set. The review hash binds the selected UUID and all database state.
4. Put the application in maintenance mode and stop workers, schedulers,
   reconciliation processes and callback intake, including any maintenance bypass.
   Stop other writers/DDL and drain in-flight requests. The command verifies
   maintenance and requires an explicit attestation; it cannot prove process
   shutdown. For the application's existing deployment tooling, use its normal
   stop procedure. The maintenance command itself is:

   ```powershell
   php artisan down
   ```

5. Capture the final review **after** stopping writers:

   ```powershell
   php artisan billing:reset-payment-history --dry-run
   ```

   Keep the report private. Review exact delete IDs/counts, detachment mappings,
   archived references, preservation fingerprints and every blocker. A blocked
   report exits 1 and cannot be executed. Never bypass a blocker by editing data
   or weakening the reset policy. Resolution is a separate authorized operation.
6. Only for an approved, unblocked review, replace the placeholders below and
   execute manually with an existing active Admin who has both capabilities:

   ```powershell
   php artisan billing:reset-payment-history --execute --review-hash="REVIEW_HASH" --confirm="RESET-PAYMENT-HISTORY" --admin=ADMIN_ID --reason="Reset legacy payment history after billing V2 correction" --workers-stopped
   ```

   The reason must be substantive (10–1000 characters). `--workers-stopped`
   attests that workers, scheduling and provider callback intake are stopped.
   `--execute` cannot be combined with `--dry-run`. Execution recomputes selection
   under locks: a new latest active Payment, changed/disappeared selected Payment,
   or any changed reviewed state invalidates the hash and aborts before mutation.
7. Inspect the committed counts/audit and retained Payment before restoring normal
   traffic. A post-reset dry-run can confirm counts; it creates no audit. Restore
   workers/scheduling and leave maintenance using normal operator procedures only
   after acceptance. Reset execution remains an operator-controlled step.

## Reviewed deletion and preservation contract

- Keep the selected `payments` row and all its `payment_events` byte-for-byte,
  including QR/code/links, provider references, status and dates. Keep its existing
  subscriptions, CreditOrders, allocation claims and other normalized dependencies.
- Delete all other `payments`, their events, and reviewed unlinked
  `payment_events`. Delete all legacy `payment_webhook_events`,
  `payment_transactions` and `payment_intents` only when the entire review is safe.
  This is all-or-nothing, not a partial cleanup of the easy candidates.
- Preserve every CreditOrder. Only its nullable `payment_id` may be detached.
  Preserve amounts, status, revenue classification, products, credits, customer,
  metadata and timestamps. `credit_orders.payment_intent_id` is **not** an approved
  detachment and therefore blocks deletion of the referenced legacy intent.
- Preserve all normalized service/storage subscription rows. Detach only
  `payment_id` for ended/expired/superseded or explicitly superseded historical
  rows, without future/unknown boundaries, auto-renewal, unresolved review or
  pending cancellation. Metadata and provider references remain unchanged; the
  manifest retains the old numeric Payment ID/UUID and provider-reference mapping.
- Never detach or delete `subscription_credit_allocations`. An allocation pointing
  to any old Payment blocks the whole reset. A claim for the kept Payment is valid.
- Preserve customers and verification, all App/API wallet fields and buckets,
  lifetime counters, ledgers, jobs, files, usage, catalogs, prices, entitlements,
  voices and prior Admin audit. Whole-row fingerprints cover every physical table.

## Dependency blockers

Current or unresolved service/storage relationships are discovered through physical
`payment_id`, normalized metadata and provider subscription references. Future or
unparseable paid-through/cancellation evidence blocks, including persisted payload
and event evidence. Explicit financial review, unapplied collections, active provider
states, actionable old checkout and unknown remote checkout disposition block.

An old provider subscription reference needs a persisted local terminal status
(`CANCELED`, `CANCELLED`, `EXPIRED`, `REJECTED`, `FAILED`, `DECLINED`, `TIMED_OUT`)
and no contradictory current obligations. Age, a missing deadline or NOT_FOUND is
not terminal proof. Fulfilled historical collections may be deleted only when all
remaining dependency/obligation checks pass. Nothing cancels a provider subscription
or contacts FIB. This is a local-evidence policy, not live provider verification.

Legacy intents must be terminal; paid intents must be fulfilled. Provider schedule
references and unresolved authorization block. Transactions must be resolved and
webhooks processed/ignored; unresolved or unknown statuses fail closed. Transaction
parent cycles block. Kept-Payment references into the legacy subsystem block its
removal. Nullable coupon, conversion, other FK, undeclared `payment_id`/
`payment_intent_id`, and retained polymorphic payment links are not permission to
detach or cascade-delete them: unapproved retained dependencies block.

Missing required schema, non-InnoDB tables, cross-schema inbound FKs, unsupported
composite dependencies, replicas/split connections, prefixes and socket connections
also refuse execution. SQLite is accepted only as `:memory:` in the test environment.

## Transaction, review identity and audit

The deterministic SHA-256 review binds database identity, selected UUID, exact
candidate IDs, dependency dispositions, counts, schema/index/trigger metadata and
full-row fingerprints. Same-count content changes invalidate the review. Full-table
reads and locking make this a maintenance operation; allow time for large histories.

Execution locks customer/Payment rows and the reviewed tables, repeats inspection,
compares the hash, and rechecks fresh active `admin.finance` + `admin.reconcile`
authorization immediately before mutation. Stopped writers are still required;
row locks alone are not a fence against every writer or schema change.

`AdminAudit` writes `billing.reset_payment_history` with operator, reason, selected
UUID and full sanitized reset manifest. The review hash is its durable `target_id`.
The audit is outside PaymentEvents and is created in the same transaction before
approved detachments. Existing audit rows are checked unchanged. Children are deleted
before parents (legacy transaction leaves first), with normal FKs enabled. No model
billing mutations, truncation or FK disabling occur.

Expected post-reset fingerprints allow only the exact deletions, approved
`payment_id = NULL` updates and new Admin audit. Any unexpected retained-data change,
audit error, capability loss or maintenance loss rolls back the transaction. A replay
of the old review cannot delete the kept Payment or create a second reset audit.

Deleted Payments stop participating in checkout. `PaymentCheckoutState` remains
unchanged: the retained Payment can still block if actionable or requiring review.
The command does not promise every historical database is eligible for this reset.

## Verification scope

### Automatic selection and schema-scope correction

The previous dry-run hit SQLSTATE 42S02 / driver 1146: Laravel's unscoped table
listing included other schemas visible to the local database user, and the command
then tried to count a foreign table in the MetKurd schema. Inventory, locking and
engine inspection now explicitly pass the active schema to Laravel's schema API.
Database errors report safe SQLSTATE/driver codes without SQL, bindings or raw
exception text. Attached-schema regression coverage protects this boundary.

The user subsequently requested automatic latest-active selection instead of manual
UUID entry. The review policy is now `payment-history-reset-v2`; prior hashes are
invalid. Selection changes still abort. This correction changes no obligation,
allocation, authorization, preservation, abandonment or checkout policy.

Focused reset and existing billing/Admin regressions use isolated SQLite fixtures,
array cache/session and blocked/mocked HTTP. No application reset, migration or
seeder has been run. Native reset execution, InnoDB locking and RDS acceptance
remain unverified; SQLite tests do not establish native-engine acceptance.

The corrected inventory was additionally verified against the confirmed local
MariaDB 10.4.28 copy inside a database-enforced READ ONLY transaction. It completed
without the missing-table error and reported 195 dependency findings across the
candidate history (not 195 distinct Payments): unresolved financial review,
unretired/current provider subscriptions, service/storage obligations, ambiguous
collections, retained links, legacy-intent metadata and an unresolved legacy
transaction. These findings block execution; no dependency was detached or repaired.
The automatically retained row is shown in the live report, not hardcoded in source.

Automatic-selection/schema-fix verification: **301 tests passed (2,599 assertions)**:
67 focused reset tests / 416 assertions and 234 existing billing, checkout and Admin
regressions / 2,183 assertions. Coverage includes another visible SQLite schema,
newer closed rows, shared checkout expiry, deterministic ties, no active candidate,
changed selection, safe database error codes and all prior preservation guards.
PHP syntax and focused Pint passed for the changed command, service and tests.

Earlier explicit-UUID implementation verification: **293 tests passed (2,546 assertions)**:

- `PaymentHistoryResetTest`: 59 tests / 363 assertions; retained bytes/events,
  subscriptions/allocations, CreditOrders, logical/physical dependencies, legacy
  children, review changes, authorization, audit/trigger rollback, replay and checkout.
- Legacy reset, recurring financial cycle, billing audit characterization, Admin P0
  safety, Admin customer payment review and V2 payment checkout: 213 tests /
  2,093 assertions.
- Admin plan deletion: 21 tests / 90 assertions.

PHP syntax and focused Pint passed for the command, service, refusal exception and
new test file. No frontend assets were changed for this command.
