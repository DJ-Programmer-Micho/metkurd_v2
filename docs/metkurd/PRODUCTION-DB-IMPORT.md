# V1 production snapshot to local V2 — 2026-09-06

## Baseline and limits

Use the user-designated `eu-metkurd-v1-260906.sql` as the final V1 baseline for
this review, rather than the current development database. The dump was inspected
offline and was not imported into the application database or executed as SQL.
Only literal rows were parsed into an in-memory analysis database; no trigger,
routine, provider callback or scheduler was executed. Results below contain
aggregate counts/catalog facts, not customer identities, credentials or payloads.

Snapshot SHA-256:
`d057dc117d1c86dcf2145fe8b4fd6245529438348d359f3f594379a313193183`.
It has **58 tables, 65 recorded migrations, 1,045 customers, 2,090 wallets,
6,499 ledger rows, 151 Payments and 34 CreditOrders**. Every recorded migration
exists in the repository. Exactly **eight repository migrations are pending**.
This is not a native MySQL import/migration rehearsal or proof of live provider state.

## Required migrations and new data

Run pending migrations after importing into the confirmed local target database.
Laravel's existing `migrations` table is the authority: retain its 65 rows and do
not mark migrations manually, rerun old wallet-split migrations, or use
`migrate:fresh`, `migrate:refresh`, `migrate:reset` or `migrate --seed`.

| Pending migration, in normal execution order | Effect |
| --- | --- |
| `2026_08_12_000000_add_submission_lifecycle_to_ml_jobs_table` | Adds nullable V2 submission/endpoint/model/charge/refund identity and timing fields with indexes; no historical credit repair. |
| `2026_08_15_000000_register_xomni_v2_tool` | Registers Apollo 2 tool/action, plan access and a default price. |
| `2026_08_15_000100_register_vector_v2_tool` | Registers Vector 2 tool/action, plan access and a default price. |
| `2026_08_16_000100_register_leo_v2_tool` | Registers Leo tool/action/access and copies one QASR global pricing rule, with a fallback. |
| `2026_08_16_000200_register_caption_v2_access` | Ensures Caption access/action metadata; retains existing pricing when any Caption rule exists. |
| `2026_09_05_000001_add_poll_coordination_to_ml_jobs` | Adds poll scheduling/lease fields. Existing jobs receive default/null coordination fields. |
| `2026_09_06_000001_add_v2_idempotency_to_api_jobs` | Adds nullable API V2 idempotency hash and customer-scoped uniqueness. |
| `2026_09_06_000002_add_admin_operation_safety` | Adds Admin capability column, operation/audit tables and active status where absent. No privileges or historical financial repair are seeded. |

**No additional general seeder is needed for this dump.** The four V2 data
migrations provide the new catalog records. Existing V1 tools, plans, wallets,
prices, currencies, payment methods and customers already exist.

Data migration caveats must be reviewed before enabling services:

- Apollo 2 starts at **1 credit/character** and Vector 2 at **1.2**, both on
  channel `all`. Existing V1 Omni/clone prices are 20/24 on App and 15/18 on API.
  These are different tool identities; do not assume new default rates represent
  an approved production pricing decision or automatically copy V1 prices over them.
- The Leo migration selects a QASR rule by priority without a channel filter or
  tie-breaker. The dump has tied global QASR rules at 1,100 (all/App/Mobile) and
  825 (API). Its copied price can therefore be ambiguous. Review the resulting
  Leo rule and settle channel policy before enabling it; this review does not
  change the migration or pricing policy.
- The Caption migration updates existing action metadata and sets each plan's
  `all` entitlement to allowed with no limits. This snapshot already permits
  Caption for all four plans. Existing Caption prices (1,300 App/all/Mobile,
  975 API) are retained because rules exist. These migrations are data writes,
  not merely schema additions.
- New Apollo 2/Vector 2/Leo IDs do not collide with existing tools/actions in the
  snapshot. New `all` entitlements alone do not enable the API or add every V2
  scope to existing plan API allowlists. Review [API-V2.md](API-V2.md).

## Why not run the existing seeders?

| Seeder | Risk on this imported baseline |
| --- | --- |
| `DatabaseSeeder` | In `local`, calls BillingMasterData then DevDefault. This is not a production-import upgrade procedure. |
| `DevDefaultSeeder` | Uses `updateOrCreate` for existing plans, storage, add-ons, tools/actions, entitlements, pricing, voices/access. Replaces production catalog settings with development defaults. |
| `BillingMasterDataSeeder` | Despite `firstOrCreate` for some records, calls the currency bootstrap and overwrites payment-method configuration. Not an insert-only seed. |
| `BillingCurrencyBootstrapSeeder` | Deactivates current managed rates and seeds fixed replacement anchors, including 1 USD = 1,500 IQD. |
| `PaymentMethodSeeder` | Overwrites existing methods/configuration, including fake/testing method defaults. |
| `OmniToolSeeder`, `CaptionToolSeeder` | Update existing tool metadata, entitlements and prices; not required after the listed migrations. |
| Landing demo seeder | Unrelated to this task; do not run it as part of the import. |

For example, the snapshot's Student/Pro/Premium monthly amounts are
12,000/24,000/48,000 IQD. Running the bootstrap plus development defaults can
replace them with 15,000/30,000/60,000 IQD. A clean import should retain the
production values, not silently redefine them through a development seed.

## Local import sequence

The user will perform the import. Commands below were **not run against the
application database** during the review.

1. Keep the source dump unchanged and back up any local database being replaced.
   Import into a dedicated local database; do not import over a live environment.
   Confirm the effective database target before any migration. Preserve the
   dump's migration history and financial/customer records.
2. Keep local workers/scheduler stopped and integrations isolated during import.
   The copied rows retain real provider references and object-storage paths.
   Use separate local cache/session storage and sandbox/disabled provider access.
   Keep `FEATURE_APP_V2`, `FEATURE_API_V2`, `FIB_ENABLED`,
   `FIB_RECONCILIATION_ENABLED` and `STORAGE_ALLOW_DESTRUCTIVE_OPERATIONS` false
   until their relevant acceptance checks; use array mail for local verification.
   Do not run reconciliation, refill, cleanup, queue retries or storage deletion
   as an import-cleanup step. Do not use real provider credentials for rehearsal.
3. Set the local DB configuration, then clear only the configuration/route cache
   needed to make that confirmed target effective:

   ```powershell
   php artisan config:clear
   php artisan route:clear
   php artisan migrate:status
   ```

   Expect the eight migrations above to be pending. If the list differs, stop
   and compare the imported dump, selected DB and migration history. Do not
   compensate with a broad seed.
4. Preview, then apply the pending migrations to that confirmed local database:

   ```powershell
   php artisan migrate --pretend
   php artisan migrate
   php artisan migrate:status
   ```

   `--pretend` is an SQL preview, not a full execution rehearsal: data-dependent
   migrations can choose different branches because preview queries do not load
   the normal data. Read the effects above and verify resulting catalog values.
   Keep V2 disabled while reviewing the new rates and billing findings below.
5. Provision the imported Admin explicitly after the migration. Replace the
   placeholder with the verified existing numeric Admin ID:

   ```text
   php artisan admin:capabilities ADMIN_USER_ID admin.customers admin.catalog admin.pricing admin.finance admin.reconcile --reason="Approved local production-snapshot administration"
   ```

   Select only the capabilities intended for that operator. Use `admin.read`
   alone for support. The command replaces the list and does not activate an
   inactive existing account. The P0 migration supplies status 1 only when the
   status column did not previously exist. The snapshot's Admin also has its
   required profile; preserve that relationship.
6. Verify `/adm/signin`, the localized Admin pages and POST `/adm/logout`.
   Inspect the new tools/prices/entitlements and migration status. Rerun the
   aggregate checks below against the local import; applying these migrations
   should not change customer wallet balances or repair/reclassify historical
   payments. Provider acceptance and production-engine tests remain separate.

If only Admin P0 is needed first, its migration is independently applicable to
this snapshot, which already has `users` and `customers`:

```powershell
php artisan migrate --path=database/migrations/2026_09_06_000002_add_admin_operation_safety.php
```

That does **not** install the seven other V2 requirements. Do not mark them as
applied. No new seeder should be used as a substitute for their migrations.

## Billing findings requiring controlled follow-up

| Finding | Snapshot/source evidence | Required interpretation |
| --- | --- | --- |
| Unverified revenue classification | Five `admin_manual` paid orders and one `fake` paid order have no revenue-exclusion metadata. All 34 paid orders lack those classification fields. | Current predicates include these six orders. This is not proof money was or was not collected; reconcile evidence per order. P0 future-write fixes do not backfill them. |
| Missing product links | All four existing add-on orders have null `credit_product_id`; provider/payment relationships remain present. | Future writes are fixed. Any historical FK repair needs a controlled, validated mapping; not a broad seed. |
| Multiple subscriptions marked active | One customer has five service-subscription rows with `status='active'`. | Investigate local effective dates and provider subscriptions before ending anything. This alone does not prove duplicate credits or charges. |
| Past cycle dates | 1,002 rows marked active have cycle end before 2026-09-06: 990 free and 12 paid-plan rows. | Free-plan cycle dates alone are not an unpaid-subscription defect. Paid rows need provider/manual-term reconciliation; a snapshot cannot establish current provider state. |
| Manual/provider renewal mismatch | Some historical `admin_manual` subscriptions carry `provider_schedule` and auto-renew metadata. | Review their real payment/renewal evidence. Do not convert them or issue renewals during import. |
| Review queue | 33 Payments require review: 22 DRAFT subscriptions and 11 UNPAID payment objects in stored provider fields. | Do not treat all reviews as paid-but-unfulfilled or bulk fulfill them. Fresh provider evidence is required. |
| P0 customer-plan column defect — fixed in source | The redundant Customer query was removed from ServicePlan. PlanVoiceAccess now queries its parent plan's `subscriptions` / `previousSubscriptions` relationships, using the real subscription foreign keys without status/date filters. | Existing catalog/financial/API dependencies and final locking remain protected. No additional migration, seed or historical repair is needed for this fix. Native MySQL execution remains unverified; source regression coverage is not deployment acceptance. |
| New V2 pricing ambiguity | New Apollo/Vector default rates differ from V1; Leo's copied rule has tied channel candidates. | Review new catalog results before feature enablement. No pricing-policy change was made during this task. |

SQLite accepts some double-quoted unknown identifiers as string literals, which
concealed the original deletion-guard defect. The subsequent focused repair adds
pre-execution SQL checks that fail on the invalid customer-column assumption,
plus current/historical subscription, previous-plan, disposable-resource and
retained catalog/financial dependency tests. An access row alone does not imply
customer use; subscription references to its own plan determine that guard.
The routing review itself made no catalog repair; this later source fix changes
only the two guards. Native MySQL execution remains a separate acceptance check.
All 21 new deletion regressions and 48 existing P0 safety cases passed as part
of the 84-case focused plan/P0 run. See [Admin audit](ADMIN-AUDIT.md) for exact
verification scope and the separate pre-existing legacy API voice-test failure.

Checks that passed on the snapshot: one App and one API wallet per customer;
no duplicate customer/wallet-type pairs; no total-versus-bucket mismatches;
no latest-ledger-versus-wallet balance mismatches; no paid/unfulfilled Payments;
no fulfilled Payments marked expired/failed; no duplicate non-null
Payment-to-CreditOrder groups; and no orphans in the six checked customer,
payment, product and service-plan relationships. These are selected consistency
checks, not complete financial reconciliation or evidence of deployed scheduler health.

## Read-only local comparison queries

Run only after confirming the local database target. They return aggregate counts.

```sql
SELECT COUNT(*) AS wallet_bucket_mismatches
FROM credit_wallets
WHERE balance_credits <> subscription_balance_credits + addon_balance_credits;

SELECT COUNT(*) AS duplicate_wallet_groups
FROM (SELECT customer_id, wallet_type FROM credit_wallets
      GROUP BY customer_id, wallet_type HAVING COUNT(*) > 1) AS duplicate_wallets;

SELECT COUNT(*) AS missing_addon_product_links
FROM credit_orders
WHERE source_type = 'credit_product' AND credit_product_id IS NULL;

SELECT COUNT(*) AS customers_with_multiple_active_subscriptions
FROM (SELECT customer_id FROM customer_service_subscriptions
      WHERE status = 'active' GROUP BY customer_id HAVING COUNT(*) > 1) AS overlapping;

SELECT status, internal_status, COUNT(*) AS payment_count
FROM payments GROUP BY status, internal_status;
```

Expected baseline counts for the first four queries are **0, 0, 4, 1**. Historical
findings intentionally remain until a separate controlled reconciliation is
authorized. P1, broader billing repair, pricing decisions and production rollout
remain open; a clean import does not itself resolve them.
