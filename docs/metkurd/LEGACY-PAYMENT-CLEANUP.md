# Legacy payment history review and complimentary grants

## Separate explicit reset — 2026-09-14

The newer [payment history reset](PAYMENT-HISTORY-RESET.md) implements the explicitly
requested maintenance operation that keeps the latest active Payment automatically,
with its identity bound to the review hash. It is a separate
command: `billing:reset-payment-history`. The conservative command documented below
is unchanged. Its historical counts and conclusions are dated observations, not a
current reset manifest. Reset execution was not run against the application database;
the automatic-selection follow-up verified only its read-only local inventory.
obligation/allocation and unapproved dependency blockers still require separate
resolution, including retained CreditOrder legacy-intent links.

## Local inventory — 2026-09-09

This is the designated local application copy, using Laravel's `mysql` connection and MariaDB 10.4.28. It is not a production query or verification of the operator's reported zero production subscribers. There are 1,045 customers, including 762 with both email and phone verification flags set.

| Table | Rows |
|---|---:|
| payments | 151 |
| payment_events | 38,872 |
| payment_intents | 1 |
| payment_transactions | 2 |
| payment_webhook_events | 0 |
| credit_orders | 34 |
| coupon_redemptions (payment-linked / total) | 0 / 0 |
| customer_service_subscriptions | 1,078 |
| customer_storage_subscriptions | 1,047 |
| subscription_credit_allocations | 0 |

Stored status evidence contradicts an assumption that the local history is unused:

- 13 active non-Free service rows: 9 from FIB and 4 manual. These are stored statuses, not proof of a currently collecting provider subscription. Some rows have stale dates; this task does not expire or repair them.
- A separate latest-row/date check found three current open FIB service rows, all missing Payment paid-through evidence, plus four current manual rows. This does not prove current provider collection or establish zero paid obligations.
- One active paid-tier storage row from FIB.
- 63 awaiting-customer-action payments (33 also require review), 28 paid/fulfilled Payments, and one paid/fulfilled legacy intent. No paid-but-unapplied Payment rows. The other 60 Payments are failed/canceled/expired.
- 85 Payments carry provider subscription references across 32 customers. Service/storage subscription rows retain 29/1 nonempty provider references. No provider reference was cleared and no provider was contacted.
- CreditOrders: 24 FIB service/storage purchase records, 4 FIB add-on purchases, 5 manual records, and one fake-provider compatibility record linked to the legacy intent. All five older manual orders lack billing-source/revenue-exclusion metadata, so the current paid-order revenue query still includes them. Manual origin is a classification for review, not permission to erase the record or repair its accounting flags. New complimentary grants create no order; those historical rows await the operator's separate disposition.

### Dependencies and credit safety

Physical foreign keys to `payments.id`: payment_events, credit_orders, customer_service_subscriptions, customer_storage_subscriptions, coupon_redemptions, subscription_credit_allocations and ad_conversion_events. Physical foreign keys to `payment_intents.id`: payment_transactions, payment_webhook_events and credit_orders. There are no physical foreign keys to `credit_orders.id` in this schema. Credit ledgers retain polymorphic CreditOrder links and subscription/ledger JSON retains order/payment references; lack of a foreign key is not lack of a dependency. Transaction rows also have a self-parent foreign key.

| Wallet | Total credits | Subscription bucket | Add-on bucket |
|---|---:|---:|---:|
| App (1,045 wallets) | 11,644,239 | 11,598,028 | 46,211 |
| API (1,045 wallets) | 2,600,000 | 2,600,000 | 0 |

There are 6,499 credit ledger rows. Ten customers with fulfilled Payment or paid CreditOrder history still hold 1,628,940 App credits (1,591,264 subscription / 37,676 add-on) and 2,300,000 API subscription credits. These aggregate balances do not establish which exact historic credit remains unspent; they prevent asserting that purchase evidence has no remaining financial relevance. No attribution repair was attempted.

## Guarded command

Run the read-only review from the repository root:

```powershell
php artisan billing:reset-legacy-payment-history --dry-run --before="2026-09-09 00:00:00"
```

The cutoff is exclusive and uses the application's timezone. It excludes newer records; age alone does not establish that a purchase is disposable. The command defaults to dry-run when `--execute` is absent. Output includes all inventory counts, states, foreign keys, classified order IDs, exact candidate deletion IDs, retained dependency IDs, wallet totals, blockers and a review hash. A blocked dry run deliberately exits with status 1 while still printing the inventory.

**Current result: zero eligible deletion IDs; execution blocked. No destructive mode was run.**

The implemented boundary is conservative: only failed/canceled/expired, unfulfilled, unpaid attempts before the cutoff can be candidates. Provider subscription/schedule references, payment collection evidence, unresolved transactions and retained foreign-key dependencies exclude them. Attempts belonging to customers with any ledger provenance or nonzero wallet balance are also retained because attribution is uncertain. Parent-linked event/webhook/failed-transaction rows are candidates only alongside their parent. Unlinked events, every CreditOrder, coupon redemption, subscription, allocation, payment-method/provider configuration and all other customer/history data are retained. This is not a full paid-history reset and is not Phase 3 retention pruning.

Execution has no blocker override. Active non-Free service rows, active paid storage, pending/review/unapplied payments or intents, fulfilled purchases, any CreditOrders or allocations refuse execution. A changed reviewed inventory also refuses execution. Clearing those dependencies needs a separately reviewed financial disposition; do not manually remove them merely to bypass this command.

If a future reviewed inventory is unblocked, execution additionally requires all of:

- The exact designated local loopback database; production/remote databases are refused. The only other allowed target is isolated SQLite `:memory:` in automated tests.
- A normal backup/export, application maintenance mode, stopped workers/schedulers and explicit `--workers-stopped` attestation.
- `--execute`, `--confirm=RESET-LEGACY-PAYMENT-HISTORY`, the reviewed `--review-hash`, an active `--admin` user with both `admin.finance` and `admin.reconcile`, and an audit `--reason` of at least ten characters.

The command checks transactional table support, locks the local rows, rechecks authorization and dependencies, displays the reviewed counts again, and deletes children before parents inside one transaction. It never disables foreign keys or truncates tables. An Admin audit record is part of the same transaction; audit failure rolls back deletion. Allocation rows are never fabricated or cleared. No production enablement or permission provisioning was added.

## Complimentary grants

The existing `AdminFinancialCorrections` → `AdminOperationRunner` → `ManualServicePlanGrantService` path already creates no Payment, event, intent, transaction or CreditOrder. It remains the sole Admin service-plan grant path. Existing durable intent IDs, capability checks and credit policy are retained.

Two actual gaps were corrected: the dashboard counted every active non-Free plan as a paid subscriber, including complimentary access; and grant supersession overwrote the older subscription's billing classification. Complimentary sources/metadata are now excluded from the paid-subscriber count. Supersession retains the older purchase's financial metadata and records the new operator/reason separately. Existing historical rows are not reclassified or backfilled.

New grants explicitly retain `grant_type=complimentary_internal`, structured reason code, operator, reason text, non-revenue flags and exact paid-free local term boundary. Monthly/yearly grants use `manual_renewal`, no provider reference, no auto-renew, and an explicit expiry. Existing expiry creates Free once and retains history/add-ons. No FIB collection or cancellation is performed by a grant.

The Customer register/detail page labels the operation **Complimentary / Internal Plan Grant**, offers existing reasons plus support compensation and promotional access, previews separate App/API allowances and credits to add, explains the existing upgrade/top-up policy, and shows estimated expiry. Confirmation uses the existing navigation-safe SweetAlert bridge and unchanged intent identity. History labels the new subscription as a complimentary Admin grant with its sanitized reason. EN/AR/KU translations and mixed-text direction are retained.

The approved credit policy is unchanged: an upgrade adds the full new allowance to remaining subscription credits; other changes top up a shortfall. Add-ons are retained. The displayed allowance is therefore distinct from the resulting combined wallet balance. This task does not redesign that policy.

Paid order/revenue queries continue to use the existing revenue exclusion rules. Complimentary grants create no sale row and do not increase dashboard paid-subscriber, revenue or credits-sold totals. No historical purchase data or business prices were modified.

## Verification

- 26 focused cleanup/complimentary tests passed (165 assertions), including all guard cases, changed review, rollback on audit failure, physical and logical dependencies, child-first deletion, untouched wallets/jobs/files, local manual expiry, authorization, replay and EN/AR/KU server rendering.
- 118 existing Admin P0/billing/dashboard/customer-payment and Billing Phase 2/subscription cases passed in the broader regression run. New-fixture and intentional label/cache-version assertion updates were retested in the focused suite; overlapping test counts are not added twice.
- 12 Admin frontend tests, syntax/focused Pint for 14 PHP files, and the asset build passed. Interactive browser confirmation and native MySQL destructive execution were not performed.
- The actual local command was run only in dry-run mode (expected blocked exit 1). End-of-task counts and full-row fingerprints match the saved local acceptance snapshot, including Payments, subscriptions, events, wallets, ledgers, customers, jobs, files and pricing. Both feature gates are unchanged; allocations remain empty. Production was not accessed.
