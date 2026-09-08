# R1 read-only SQL checklist

Current task: upgrade the existing local application database after an operator
backup, with no new environment/import. The separate R2A plan is cancelled.
Fresh status confirms 66 recorded / eight pending, Admin P0 already applied.
The checklist ran read-only (23 statements) to capture current before evidence;
normal migration and after comparison remain pending. Earlier R2A assumptions
below are historical, not the current task's baseline.

R2A now prepares a **separate** MySQL environment from the original snapshot;
existing development MariaDB stays unchanged. Follow
[RELEASE-R2A-MYSQL.md](RELEASE-R2A-MYSQL.md). Its original 65-migration history
implies nine pending against current source, not the development target's eight.
Do not reuse old MariaDB financial hashes as the new MySQL before evidence.

R2 is **native MySQL migration acceptance** for the intended local MySQL →
Amazon RDS for MySQL path. First verify `SELECT VERSION()` through Laravel's
active connection; the `mysql` connection name alone does not prove MySQL.
The 2026-09-07 R2 check returned `10.4.28-MariaDB`, so acceptance is stopped
pending the operator's choice of a MySQL target. Historical R1 MariaDB results
remain useful baseline observations, not RDS MySQL acceptance. The checklist
has not been executed against a confirmed MySQL server during R2.

Use the manual sequence in [PRODUCTION-DB-IMPORT.md](PRODUCTION-DB-IMPORT.md).
The single SQL block below can be copied into a SQL client or extracted by that
runbook into a private local `.sql` file. No schema change is performed here.

```sql
-- R1 read-only checks. Select the confirmed LOCAL schema in your SQL client first.
-- Run with writers stopped immediately before and after the approved migration.
-- Save both result sets locally. This file creates no tables and changes no rows.
START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY;

SELECT VERSION() AS engine_version;

-- R1 observed 66 recorded. With the new correction, expect eight pending / 74
-- recorded after success only if that baseline is unchanged. migrate:status and
-- the repository migration set are authoritative, never this historical count.
SELECT COUNT(*) AS recorded_migrations FROM migrations;

-- R1 observed 13 / 18 / 72 / 54; expected 16 / 21 / 84 / 66 after the approved
-- migration against this unchanged four-plan baseline. No hard-coded row IDs.
SELECT 'tools' AS catalog_type,COUNT(*) AS records FROM tools
UNION ALL SELECT 'actions',COUNT(*) FROM tool_actions
UNION ALL SELECT 'entitlements',COUNT(*) FROM plan_entitlements
UNION ALL SELECT 'pricing_rules',COUNT(*) FROM pricing_rules;

-- Exactly nine rows. Before R1, Apollo 2, Vector 2 and Leo are absent.
-- Afterwards every found_action/bound_tool is non-null and both active values are 1.
WITH expected AS (
    SELECT 'xomni.generate' AS action_code, 'xomni' AS tool_code
    UNION ALL SELECT 'xomni-v2.generate', 'xomni-v2'
    UNION ALL SELECT 'clone_xomni.generate', 'clone_xomni'
    UNION ALL SELECT 'vector-v2.generate', 'vector-v2'
    UNION ALL SELECT 'leo.transcribe', 'leo'
    UNION ALL SELECT 'caption.standard', 'caption'
    UNION ALL SELECT 'ocr.standard', 'ocr'
    UNION ALL SELECT 'stem.sep2', 'stem'
    UNION ALL SELECT 'stem.sep4', 'stem'
)
SELECT e.action_code AS expected_action, e.tool_code AS expected_tool,
       a.full_code AS found_action, a.tool_code AS action_tool,
       t.code AS bound_tool, a.is_active AS action_active, t.is_active AS tool_active
FROM expected e
LEFT JOIN tool_actions a ON a.full_code=e.action_code
LEFT JOIN tools t ON t.code=a.tool_code AND t.code=e.tool_code
ORDER BY e.action_code;

-- After migration: all four plans have allowed, unlimited 'all' grants for each
-- of Apollo 2, Vector 2, Leo and Caption. Existing channel-specific grants survive.
SELECT a.full_code, p.code AS plan, e.entitlement_channel, e.allowed, e.limits
FROM tool_actions a CROSS JOIN service_plans p
LEFT JOIN plan_entitlements e ON e.tool_action_id=a.id AND e.service_plan_id=p.id
WHERE a.full_code IN ('xomni.generate','xomni-v2.generate','clone_xomni.generate',
    'vector-v2.generate','leo.transcribe','caption.standard','ocr.standard','stem.sep2','stem.sep4')
ORDER BY a.full_code,p.code,e.entitlement_channel;

-- Approved launch policy: exactly twelve active global channel rules, each matched.
-- Before migration these rows can report 0. After migration all must report 1.
WITH policies AS (
    SELECT 'xomni-v2.generate' AS action_code, 'character' AS metric, 20 AS base_rate, 15 AS api_rate, 1 AS minimum
    UNION ALL SELECT 'vector-v2.generate','character',24,18,1
    UNION ALL SELECT 'leo.transcribe','minute',1100,825,1100
), channels AS (
    SELECT 'all' AS channel UNION ALL SELECT 'app' UNION ALL SELECT 'mobile' UNION ALL SELECT 'api'
)
SELECT p.action_code,c.channel,
       CASE WHEN c.channel='api' THEN p.api_rate ELSE p.base_rate END AS approved_rate,
       p.minimum AS approved_minimum,COUNT(r.id) AS active_global_rules,
       CASE WHEN COUNT(r.id)=1 AND SUM(CASE WHEN
           r.credits_per_unit=CASE WHEN c.channel='api' THEN p.api_rate ELSE p.base_rate END
           AND r.minimum_credits=p.minimum AND r.metric_code=p.metric AND r.unit_size=1
           AND r.rule_type='unit' AND r.rule_scope='global' AND r.priority=100
           AND r.rounding_mode='ceil' AND r.rounding_step=1
           AND r.conditions IS NULL AND r.config IS NULL
           AND r.starts_at IS NULL AND r.ends_at IS NULL
           THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END AS launch_policy_match
FROM policies p CROSS JOIN channels c
LEFT JOIN tool_actions a ON a.full_code=p.action_code
LEFT JOIN pricing_rules r ON r.tool_action_id=a.id AND r.service_plan_id IS NULL
    AND r.pricing_channel=c.channel AND r.is_active=1
GROUP BY p.action_code,p.metric,p.base_rate,p.api_rate,p.minimum,c.channel
ORDER BY p.action_code,c.channel;

-- Expect zero. Superseded rows may remain as inactive evidence.
SELECT COUNT(*) AS unexpected_active_global_channels FROM pricing_rules r
JOIN tool_actions a ON a.id=r.tool_action_id
WHERE a.full_code IN ('xomni-v2.generate','vector-v2.generate','leo.transcribe')
  AND r.service_plan_id IS NULL AND r.is_active=1
  AND r.pricing_channel NOT IN ('all','app','mobile','api');

-- Show stored App/API/all rules, not a customer-specific effective quote.
-- Correction supplies explicit all/App/Mobile/API global rules for the three new
-- actions; all remains the fallback. API scopes and scoped overrides are unchanged.
SELECT a.full_code,p.code AS plan,r.pricing_channel,r.priority,r.rule_scope,
       r.rule_type,r.metric_code,r.unit_size,r.credits_per_unit,r.minimum_credits,
       r.rounding_mode,r.rounding_step,r.is_active,
       r.conditions IS NOT NULL AS has_conditions,r.config IS NOT NULL AS has_config
FROM tool_actions a
LEFT JOIN pricing_rules r ON r.tool_action_id=a.id
LEFT JOIN service_plans p ON p.id=r.service_plan_id
WHERE a.full_code IN ('xomni.generate','xomni-v2.generate','clone_xomni.generate',
    'vector-v2.generate','leo.transcribe','qasr.standard','caption.standard',
    'ocr.standard','stem.sep2','stem.sep4')
ORDER BY a.full_code,p.code,r.pricing_channel,r.priority DESC;

SELECT 'customers' AS record_type,COUNT(*) AS records FROM customers
UNION ALL SELECT 'wallets',COUNT(*) FROM credit_wallets
UNION ALL SELECT 'ledgers',COUNT(*) FROM credit_ledgers
UNION ALL SELECT 'service_subscriptions',COUNT(*) FROM customer_service_subscriptions
UNION ALL SELECT 'credit_orders',COUNT(*) FROM credit_orders
UNION ALL SELECT 'payments',COUNT(*) FROM payments
UNION ALL SELECT 'ml_jobs',COUNT(*) FROM ml_jobs
UNION ALL SELECT 'customer_files',COUNT(*) FROM customer_files;

SELECT wallet_type,COUNT(*) AS wallets,SUM(balance_credits) AS balance,
       SUM(subscription_balance_credits) AS subscription_balance,
       SUM(addon_balance_credits) AS addon_balance,
       SUM(lifetime_earned) AS lifetime_earned,SUM(lifetime_spent) AS lifetime_spent,
       SUM(lifetime_refunded) AS lifetime_refunded
FROM credit_wallets GROUP BY wallet_type ORDER BY wallet_type;

SELECT wallet_type,COUNT(*) AS ledger_rows,SUM(credits_delta) AS net_delta,
       SUM(amount) AS amount,SUM(balance_before) AS balances_before,
       SUM(balance_after) AS balances_after,
       SUM(subscription_balance_after) AS subscription_balances_after,
       SUM(addon_balance_after) AS addon_balances_after
FROM credit_ledgers GROUP BY wallet_type ORDER BY wallet_type;

-- Order-sensitive cryptographic fingerprints also detect offsetting row changes.
-- No customer identifiers or ledger metadata are returned. NULL means the server's
-- GROUP_CONCAT limit is too small; it is NOT a matching/accepted fingerprint.
SELECT COUNT(*) AS wallet_rows,
    CASE WHEN COUNT(*)=0 THEN SHA2('',256)
         WHEN @@group_concat_max_len >= COUNT(*)*64 THEN
           SHA2(GROUP_CONCAT(SHA2(JSON_ARRAY(id,customer_id,wallet_type,balance_credits,
             subscription_balance_credits,addon_balance_credits,lifetime_earned,
             lifetime_spent,lifetime_refunded,cycle_started_on,cycle_ends_on,
             current_cycle_key,last_granted_at,last_charged_at,created_at,updated_at),256)
             ORDER BY id SEPARATOR ''),256)
         ELSE NULL END AS wallet_fingerprint
FROM credit_wallets;

SELECT COUNT(*) AS ledger_rows,
    CASE WHEN COUNT(*)=0 THEN SHA2('',256)
         WHEN @@group_concat_max_len >= COUNT(*)*64 THEN
           SHA2(GROUP_CONCAT(SHA2(JSON_ARRAY(id,customer_id,type,bucket,credits_delta,
             balance_after,subscription_balance_after,addon_balance_after,related_type,
             related_id,reference_code,meta,created_at,wallet_type,source_type,source_id,
             direction,amount,balance_before,tool_code,tool_action,metric_code,
             metric_quantity,api_key_id,api_job_id,ml_job_id),256)
             ORDER BY id SEPARATOR ''),256)
         ELSE NULL END AS ledger_fingerprint
FROM credit_ledgers;

SELECT COUNT(*) AS duplicate_wallet_groups FROM (
    SELECT customer_id,wallet_type FROM credit_wallets
    GROUP BY customer_id,wallet_type HAVING COUNT(*)>1
) d;

SELECT COUNT(*) AS wallet_bucket_mismatches FROM credit_wallets
WHERE balance_credits <> subscription_balance_credits + addon_balance_credits;

SELECT COUNT(*) AS missing_historical_addon_product_links FROM credit_orders
WHERE source_type='credit_product' AND credit_product_id IS NULL;

-- Null links on other order types are not necessarily missing add-on links.
SELECT COUNT(*) AS all_orders_without_credit_product_id FROM credit_orders
WHERE credit_product_id IS NULL;

SELECT COUNT(*) AS customers_with_multiple_active_service_subscriptions FROM (
    SELECT customer_id FROM customer_service_subscriptions
    WHERE status='active' GROUP BY customer_id HAVING COUNT(*)>1
) s;

-- Expected after migration: eight lifecycle fields and four poll fields on ml_jobs,
-- idempotency_hash on api_jobs, plus the two already-present Admin user fields.
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (
    (TABLE_NAME='ml_jobs' AND COLUMN_NAME IN ('submission_key','endpoint_key','model_key',
      'charge_reference','refund_reference','failure_stage','submission_attempted_at',
      'refunded_at','next_poll_at','poll_locked_until','poll_token','poll_attempts')) OR
    (TABLE_NAME='api_jobs' AND COLUMN_NAME='idempotency_hash') OR
    (TABLE_NAME='users' AND COLUMN_NAME IN ('status','admin_capabilities'))
) ORDER BY TABLE_NAME,ORDINAL_POSITION;

-- NON_UNIQUE=0 for the four unique indexes; ordered columns must match the source.
SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME IN (
    'ml_jobs_customer_submission_key_unique','ml_jobs_charge_reference_unique',
    'ml_jobs_refund_reference_unique','ml_jobs_status_submission_attempted_idx',
    'ml_jobs_reconciliation_due','api_jobs_customer_v2_idempotency_unique'
) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;

SELECT TABLE_NAME,ENGINE,ROW_FORMAT FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('admin_operations','admin_audit_events')
ORDER BY TABLE_NAME;

-- Do not check nonexistent poll columns before migration. Once information_schema
-- confirms them, run the additional SELECT in the runbook (no writes).
ROLLBACK;
```
