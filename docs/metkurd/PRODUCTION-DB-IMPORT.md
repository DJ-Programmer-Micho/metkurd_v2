# V1 production snapshot to local V2 — 2026-09-06

## Current task — upgrade the existing local copy — 2026-09-07

The operator cancelled the separate Docker/MySQL environment. Upgrade the existing
local application database in place, after the operator makes a normal local export
backup. Do not import another snapshot, run broad seeders, repair history or redesign
the database. The earlier R2/R2A environment procedures below are superseded for this
task; no Docker environment was ever created and its prepared tooling was removed.

The effective connection matches the operator's specified loopback target on port
3306. The server remains MariaDB 10.4.28 under Laravel's `mysql` driver; this is an
existing-data upgrade rehearsal, not native RDS MySQL acceptance. Actual
`migrate:status` reports 66 recorded / eight pending, with Admin P0 already recorded:

- `2026_08_12_000000_add_submission_lifecycle_to_ml_jobs_table`
- `2026_08_15_000000_register_xomni_v2_tool`
- `2026_08_15_000100_register_vector_v2_tool`
- `2026_08_16_000100_register_leo_v2_tool`
- `2026_08_16_000200_register_caption_v2_access`
- `2026_09_05_000001_add_poll_coordination_to_ml_jobs`
- `2026_09_06_000001_add_v2_idempotency_to_api_jobs`
- `2026_09_07_000001_normalize_v2_launch_pricing`

`migrate --pretend` succeeded with no DROP/DELETE/TRUNCATE/RENAME operations.
Data-dependent preview limitations remain: registration reads can produce ID 0,
and the launch correction intentionally skips pretend mode. No normal migration
was executed. After a backup, the operator runs `php artisan migrate` manually.
Both effective V2 gates were true at inspection; pause V2 traffic/jobs during the
upgrade. No flags, worker state, prices or customer records were changed here.

Saved private before evidence covers 1,045 customers, 1,045 App wallets, 1,045 API
wallets, 6,499 ledger rows, 1,078 subscriptions, 151 payments, 34 CreditOrders,
2,187 MlJobs and 2,464 CustomerFiles. Wallet balances total 11,644,239 App credits
and 2,600,000 API credits. Complete wallet/ledger fingerprints and current
prices/entitlements/schema are saved for comparison. After execution verify nine
current actions, intended grants, approved launch prices, legacy price preservation,
required schema and unchanged customer/financial/history evidence. Results remain
pending operator execution; refresh before evidence if intervening activity occurs.

## R2A decision — separate native MySQL acceptance — 2026-09-07

Keep current development MariaDB and its normal Laravel configuration unchanged.
The operator chose a separate Docker native MySQL acceptance instance, with
production remaining Amazon RDS for MySQL. Operator-supplied dump-header evidence
reports server 8.4.8 (client 8.0.33); use the pinned 8.4.8 procedure in
[RELEASE-R2A-MYSQL.md](RELEASE-R2A-MYSQL.md), subject to confirming that production
evidence is current. Docker tooling is installed but the daemon was stopped.
No container, import or migration was executed during preparation.

The original snapshot was found at its relocated `H:\MET_DEV_DB\metkurd\db\local`
path with the exact previously recorded SHA-256. Use that file, not the mutated
development database. No SQL compatibility rewrite was needed by the offline
screen. Fresh import is expected to retain **65 migrations**, implying **nine
pending** against 74 source files, including P0. This replaces the previous R2
assumption of the development target's 66/eight for this separate environment;
actual imported names/batches must be reviewed before R2 resumes. The old manual
development-target sequence below is historical and must not be used to mutate
the existing MariaDB installation. R2A ends after environment/import instructions.

## R2 — native MySQL migration acceptance: blocked on engine identity — 2026-09-07

The intended database platform is **MySQL locally and Amazon RDS for MySQL in
production**, as confirmed by the operator. MariaDB is not the intended platform
and its acceptance cannot substitute for native MySQL evidence.

A fresh `SELECT VERSION()` through Laravel's active connection at
2026-09-07 11:59:04 UTC returned **`10.4.28-MariaDB`**. The default and active
connection name and driver were `mysql` (`Illuminate\Database\MySqlConnection`).
Environment was local, host was loopback, the selected database matched effective
configuration, and configuration was not cached. A connection driver name does
not establish the server engine; this result directly confirms the mismatch.

**BLOCKED — migration incomplete or evidence insufficient.** Native-engine
acceptance stopped at this prerequisite. No migrations, feature/environment
changes, worker control, seeds or customer/history mutations were performed.
Migration history, schema/catalog acceptance, before/after financial preservation,
native application rendering and post-acceptance regression were not rechecked
in this engine-identity step. Earlier R1 observations below remain historical.

The operator must decide whether to replace the local server or rehearse on a
separate MySQL target before R2 continues. Do not execute the manual migration
sequence below until the engine mismatch is resolved and operator prerequisites
are verified. On the confirmed MySQL target, evaluate DDL, indexes, nullable
unique keys, JSON, transactions, locking, foreign keys and application queries
against that MySQL version. Production/RDS execution remains separately unverified.

## Approved launch pricing follow-up — 2026-09-07

**Source correction approved and implemented; application migrations have not run.**
There is no reliable repository/deployment inventory proving the original Apollo 2,
Vector 2 and Leo registration migrations never ran in another maintained environment.
Preserve those historical files and append
`2026_09_07_000001_normalize_v2_launch_pricing.php`. No application database was
accessed for this source/test task. The R1 observations below remain dated evidence.

| Action | Metric / unit | all | app | mobile | api | Minimum |
|---|---|---:|---:|---:|---:|---:|
| `xomni-v2.generate` | character / 1 | 20 | 20 | 20 | 15 | 1 |
| `vector-v2.generate` | character / 1 | 24 | 24 | 24 | 18 | 1 |
| `leo.transcribe` | minute / 1 | 1,100 | 1,100 | 1,100 | 825 | 1,100 |

Every approved global rule uses priority 100, unit pricing, ceil with step 1,
no conditions/config and no start/end restrictions. These are explicit approved
constants, including equivalent legacy minimum conventions; the correction never
selects QASR economics from a row. Legacy Apollo 1.5, Vector 1.5, QASR and Caption
prices are untouched. Caption remains 1,300 all/App/Mobile, 975 API, minimum 1,000.

For each exact action code, the correction requires one correctly bound action
and its Tool. Missing/mismatched registrations abort the transaction. For each
channel it retains the smallest existing global pricing row ID only as a stable
identity, or inserts the missing rule. It writes the approved values above and
deactivates competing global rules for that action. Thus the 1/1.2 placeholders
cannot remain active fallbacks. Existing scoped plan/customer overrides retain
normal precedence and are not reset by a global launch policy. Extra superseded
rows remain inactive evidence rather than being deleted. Repeated execution adds
no duplicates and leaves unchanged rows/timestamps intact. `down()` intentionally
retains the approved policy; do not roll back to unapproved placeholders.

Original registrations still produce allowed `all` plan grants with null limits.
The correction does not change grants, plan rows, API allowlists, P1 scope ownership,
runtime resolvers, keys, wallets, ledgers, subscriptions, purchases, jobs or files.
API access still requires paid-plan enablement, scope/key/action entitlement and
the existing active-status, wallet, rate and concurrency controls. Approved prices
are not feature rollout or automatic API scope grants.

**Updated manual expectation:** relative to R1's 66 recorded migrations, the added
source file implies **eight pending and 74 recorded after a complete successful
run**, provided no other operator change occurred. This is not a new native status
read. The original 65-migration dump would now need nine migrations. Always trust
the actual migrations table and current repository file set, not a hard-coded count.
The four-plan R1 catalog would end at 16 tools, 21 actions, 84 entitlements and
**66 pricing rules** (12 new-action channel rules instead of three). Other
environments may retain additional inactive/scoped rules; validate active policy,
not an invented universal row count.

The approved source removes the pricing decision/nondeterminism blockers for the
complete ordered run. The original Leo step can still temporarily choose a tied
rule before the correction executes: keep both feature flags disabled and all
writers stopped until **every migration, including correction, succeeds**. A failed
partial run is not ready to serve. No environment flag or application DB was changed.
Operator target/backup/maintenance checks and manual execution remain pending;
native migration, provider/API and production acceptance remain separate.

Verification: 110 tests / 643 assertions passed in the combined migration, P1,
grouped pricing, P0 safety and Admin billing run. Final expanded focused migration
coverage passed 12 tests / 206 assertions (overlapping coverage, not an additional
12 unique regressions): **112 distinct tests passed** across the final migration
suite and 100 existing P1/grouping/P0/billing cases. Includes varied QASR insertion order, all channel/fallback
quotes and Admin agreement, repeat/pretend behavior, transactional failure and
financial/legacy-price fingerprints. Both gates stayed disabled; API pricing/access
uses the actual Customer resolver and ApiCatalog directly. No application database
or feature flag was touched. See ADMIN-AUDIT.md for scope and acceptance limits.

## R1 current local readiness audit — 2026-09-07

**Inspection complete; schema execution/acceptance remains pending.** This section
supersedes the old snapshot's eight-pending assumption for the currently selected
local target and provides its current manual procedure. The older import sequence
below describes the original snapshot, not this seven-pending target. No normal
migration, seeder, price update, repair, capability provisioning, feature change,
environment edit or cache clear was performed during R1. No legacy deletion continued.

### Confirmed target and current differences

Effective Laravel connection/driver: `mysql`; application environment: `local`;
host class: loopback; engine: **MariaDB 10.4.28**. The selected database matches
the effective configuration. Laravel configuration is **not cached**. No schema
name, credentials or endpoint values are recorded here. Read-only PDO used the
same Laravel configuration loader and URL parser, a consistent-snapshot READ ONLY
transaction and rollback, without hydrating application models. Matching aggregates
do not prove a byte-for-byte import of the designated dump.

The target has **60 tables and 66 recorded migrations**, with no recorded migration
missing from source. `php artisan migrate:status` succeeds: **seven pending**;
`2026_09_06_000002_add_admin_operation_safety` is already recorded in batch 17.
`users.status`, `users.admin_capabilities`, `admin_operations` and
`admin_audit_events` are physically present; both Admin tables currently have zero
rows. Do not reapply P0 or add another status migration. Presence/history is native
read evidence, not newly executed P0 mutation/authentication acceptance.

Both effective `FEATURE_APP_V2` and `FEATURE_API_V2` gates are currently **true**.
This differs from the earlier environment report and is unsafe as a readiness
assumption: the selected schema still lacks submission lifecycle, poll coordination
and API V2 idempotency fields/indexes. R1 did not change either gate. The operator
must disable both before the manual maintenance sequence and leave them disabled
afterwards pending separate rollout acceptance. Current serving/worker processes
may retain different configuration; their runtime state was not established.

### Original R1 pending migrations and registration-stage effects

The seven entries below were observed before the new correction existed. The
correction above now follows them (Admin P0 remains already recorded in R1).

Classification: A = additive schema; B = catalog registration; C = existing-row
transformation; D = business/financial-sensitive configuration. A migration can
have multiple classifications; D here concerns access/pricing, not wallet repair.

| Pending migration (normal order) | Class | Actual `up()` effects on this target |
|---|---|---|
| `2026_08_12_000000_add_submission_lifecycle_to_ml_jobs_table` | A | Adds nullable submission/endpoint/model keys, charge/refund references, failure stage and two timestamps. Adds customer/submission uniqueness, unique charge/refund references and status/attempt-time index. Historical financial identities are not backfilled. |
| `2026_08_15_000000_register_xomni_v2_tool` | B, D | Upserts active `xomni-v2` / `xomni-v2.generate` with character metric and Omni metadata; these are absent, so three catalog/price inserts plus four plan grants are expected. Sets every plan's `all` grant to allowed, limits null; creates the default global `all` price described below. |
| `2026_08_15_000100_register_vector_v2_tool` | B, D | Upserts active `vector-v2` / `vector-v2.generate`, character metric and clone metadata; absent here. Sets four `all` grants allowed/unlimited and creates its global `all` price. |
| `2026_08_16_000100_register_leo_v2_tool` | B, D | Upserts active `leo` / `leo.transcribe`, minute metric and ASR metadata; absent here. Sets four `all` grants allowed/unlimited. Copies one global QASR rule using priority alone, with the ambiguity below. |
| `2026_08_16_000200_register_caption_v2_access` | C, D | Existing Caption tool is left intact. Updates existing action name, metric, active flag, metadata and created/updated timestamps. Resets each plan's `all` grant to allowed/unlimited, including timestamps. Existing grants already match allowed/null here. Any existing Caption pricing prevents insertion; all four current rules are retained. |
| `2026_09_05_000001_add_poll_coordination_to_ml_jobs` | A | Adds nullable `next_poll_at`, `poll_locked_until`, `poll_token`; unsigned `poll_attempts` default 0; `(status,next_poll_at)` index. Existing jobs get only null/default coordination metadata. |
| `2026_09_06_000001_add_v2_idempotency_to_api_jobs` | A | Adds nullable `CHAR(64) idempotency_hash` and unique `(customer_id,idempotency_hash)`. Existing API job table is empty here; no reservations/wallets are rewritten. |

The catalog migrations use Query Builder transactions, not customer/plan-switching
services. Upsert behavior can overwrite matching catalog fields if such records
appear before execution: pause and re-audit if the pending set/catalog changes.
`created_at` is explicitly replaced in those upserts; this is not a purely
insert-only migration design. None calls a seeder. Admin P0 is not in this pending
set; its source conditionally adds `status` default 1, adds nullable JSON capabilities
and operation/audit tables, but does not grant explicit mutation capabilities.

### Catalog and price approval

This table records the **original registration stage**, not final launch pricing.
The approved corrective migration above replaces its new-action defaults before
traffic resumes. The old nondeterministic Leo selection no longer defines final
launch economics after the complete ordered run.

Current active actions with correct Tool-code binding are `xomni.generate`,
`clone_xomni.generate`, `caption.standard`, `ocr.standard`, `stem.sep2`, `stem.sep4`.
**Missing:** `xomni-v2.generate`, `vector-v2.generate`, `leo.transcribe` (and their
parent tools). The four existing plans have the current six actions' `all` grants.
No missing record was inserted during this audit.

| Service | Source migration behavior | Current local state / approval impact |
|---|---|---|
| Apollo 2 | Global `all`, priority 100, unit size 1 character, **1 credit/character**, minimum 1, ceil/step 1; no conditions/config | No Apollo 2 row/rule. Apollo 1.5 remains 20 on all/App/Mobile and 15 API. New default is not approval to discount/reprice the older identity. |
| Vector 2 | Global `all`, priority 100, unit size 1 character, **1.2 credits/character**, minimum 1, ceil/step 1; no conditions/config | No Vector 2 row/rule. Vector 1.5 remains 24 on all/App/Mobile and 18 API. |
| Leo | Takes QASR action's first rule with null `service_plan_id`, ordered only by descending priority. No channel, active, date, condition or tie-break filter. Copies remaining rule fields, then forces Leo action, null plan, channel `all`, priority 100 and fresh timestamps. Fallback is 1,000/minute, minimum 1,000 | Four active global QASR candidates still tie at priority 100: all/App/Mobile **1,100/minute**, API **825/minute**, all with minimum **1,100**, unit 1, ceil/step 1. Either price can become Leo's `all` fallback, with minimum 1,100. Fallback 1,000 is not the expected real-data branch here. Resolve this policy/selection before approving the whole run. |
| Caption | Inserts 1,000/minute only when **no rule of any channel/plan/status exists** | Four rules exist: all/App/Mobile **1,300/minute**, API **975/minute**, minimum **1,000**. All pricing is retained; action/grant metadata changes as above. |

The three new prices use `all`; they are eligible App/API fallback rules under
`PricingRule::fallbackChannels`, not new explicit App/API rows. Existing plan
allowlists and explicit/derived API scope ownership are not migrated. Catalog
presence and fallback grants do not prove API authorization or a customer-specific
effective quote. No broad seed is required: the pending migrations provide these
registrations. DatabaseSeeder, DevDefault, BillingMasterData, currency/payment
bootstrap, OmniToolSeeder and CaptionToolSeeder remain excluded.

### Historical R1 preview and detected MariaDB compatibility limits

This subsection describes the server detected during R1 only. Its MariaDB-specific
observations are not MySQL/RDS compatibility acceptance; the intended platform and
current R2 engine mismatch are recorded above.

`php artisan migrate --pretend` returned exit 0 for all seven migrations, with
**no DROP, DELETE, TRUNCATE or other destructive SQL** in the forward preview.
The console's standard “Running migrations” heading belongs to pretend mode;
no migrations were applied. A second `migrate:status` is identical to the first.

Preview queries do not return actual catalog data: generated SQL shows action ID
0, skips the four-plan entitlement loops, takes Leo's 1,000 fallback and proposes
Caption inserts despite existing rows. These are preview artifacts, not proof of
real FK failures, actual Leo pricing or Caption repricing. Do not execute the
printed SQL manually or treat preview as full data-dependent acceptance.

No concrete unsupported pending column/SQL construct was found. Affected tables
are InnoDB/Dynamic, utf8mb4, with a 16-KiB InnoDB page size. New indexes are short
(largest new string key is 120 characters, at most 480 utf8mb4 data bytes).
No generated/default expressions or database JSON operators occur in pending
`up()` methods; JSON payloads are PHP-encoded strings into existing columns.
MariaDB's existing Admin JSON columns report as LONGTEXT, which is expected for
its [JSON alias](https://mariadb.com/docs/server/reference/data-types/string-data-types/json).
The [InnoDB index limits](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-limitations)
do not expose an index-length conflict here. Nullable unique keys permit retained
null historical identities; see [MariaDB NULL handling](https://mariadb.com/docs/server/reference/data-types/null-values).

No new foreign-key DDL is pending. Catalog DML relies on existing InnoDB foreign
keys and real generated IDs. New column/index names are currently absent, so no
partial-application collision was found. Real DDL may acquire metadata locks and
implicitly commit; the ordered migration run is not one atomic rollback unit. See
[MariaDB implicit commits](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit).
Catalog `down()` methods delete registration rows and schema `down()` methods
drop columns/indexes; do not use rollback/reset as routine recovery on retained
history. Native DDL/DML execution, locking/race behavior and production acceptance
remain unverified. No speculative compatibility edit was made.

### Financial baseline and expected preservation

| Read-only aggregate | Current | Comparison |
|---|---:|---|
| Customers | 1,045 | Matches snapshot |
| App / API wallets | 1,045 / 1,045 | Matches snapshot; separate wallets |
| Duplicate wallet groups / bucket mismatches | 0 / 0 | Matches snapshot |
| App balance / subscription / add-on credits | 11,644,239 / 11,598,028 / 46,211 | Current pre-execution baseline; must remain unchanged |
| API balance / subscription / add-on credits | 2,600,000 / 2,600,000 / 0 | Current pre-execution baseline; must remain unchanged |
| Ledger rows | 6,499 (App 4,985 / API 1,514) | Total matches snapshot |
| Service subscription rows | 1,078 | Matches P4A |
| CreditOrders / Payments | 34 / 151 | Matches snapshot |
| Missing historical add-on `credit_product_id` | 4 | Matches snapshot; remains unresolved |
| All orders with null `credit_product_id` | 34 | Broader count includes non-add-on orders; not 34 proven missing add-on links |
| Customers with multiple status-active service subscriptions | 1 | Matches snapshot; do not repair |
| MlJobs / CustomerFiles | 2,187 / 2,464 | Matches P4A; no deletion authorized |

The seven `up()` methods do **not** reset/refill/merge wallets, write customers or
credit ledgers, reclassify revenue, repair subscriptions, or delete files/jobs.
MlJob additions are schema/default metadata only. Catalog price/access writes
are the explicit exception to “no business data effects.” Fingerprints of complete
wallet and ledger row projections were stable across the R1 verification reads;
the SQL checklist returns hashes without exposing row identities or metadata.
For migration acceptance, capture a fresh baseline with writers stopped and compare
the **before/after** fingerprints as well as counts/sums. Net ledger delta need
not equal a wallet's current balance; do not infer historical repairs from totals.

### Manual command sequence — not executed by R1

The launch pricing policy and additive correction above are now approved in source.
Complete operator backup/maintenance checks before executing any migration. Use
the complete normal ordered path, including the correction; do not stop after the
old registration prices or substitute a seeder/ad hoc insert.

1. In the repository root, confirm target and cache/feature state in the local
   console only (schema name is deliberately printed only there):

   ```powershell
   php artisan tinker --execute='dump(["environment"=>app()->environment(),"connection"=>config("database.default"),"host_class"=>in_array(DB::connection()->getConfig("host"),["127.0.0.1","localhost","::1"],true)?"loopback":"STOP","schema"=>DB::connection()->getDatabaseName(),"engine"=>DB::selectOne("SELECT VERSION() AS version")->version,"config_cached"=>app()->configurationIsCached(),"app_v2"=>config("metkurd_v2.enabled"),"api_v2"=>config("customer_api.v2_enabled")]);'
   ```

   Stop if environment/host/schema differs from the intended local import. Do not
   infer the target from `.env` alone or publish this console output.
2. Stop local application writers before taking the acceptance baseline. Use
   `php artisan down` for web maintenance. Stop the actual scheduler/queue/reconcile
   terminals with Ctrl+C or their configured process manager, and disable any
   launcher that restarts them. Maintenance alone does not stop every scheduled
   command/worker; `queue:restart` is not a stop command. No supervisor or Windows
   scheduled-task name was verified, so no guessed stop command is prescribed.
   Do not run refill/reconciliation/retention commands against copied references.
3. Manually set `FEATURE_APP_V2=false` and `FEATURE_API_V2=false` in the effective
   local environment, keeping provider/FIB reconciliation and destructive storage
   isolated as in the import sequence below. R1 did not edit these. There is no
   config cache to clear now. **Only if one exists after an intentional config
   change**, run `php artisan config:clear`, then recheck step 1. No route change
   requires `route:clear`. Keep long-lived workers stopped throughout.
4. Back up the confirmed local database and save the before-check results. The
   installed client/dump binaries were found at the paths below. Substitute the
   console-confirmed schema/connection interactively; password is prompted and
   never placed on the command line. Save outside the repository:

   ```powershell
   $r1DbHost = Read-Host 'Confirmed loopback DB host'
   if ($r1DbHost -notin @('127.0.0.1','localhost','::1')) { throw 'Local target required' }
   $r1DbPort = Read-Host 'Confirmed local DB port'
   $r1Schema = Read-Host 'Confirmed local schema name'
   $r1DbUser = Read-Host 'Local database user'
   $r1BackupDir = Read-Host 'Existing private backup directory outside the repository'
   if (-not (Test-Path -LiteralPath $r1BackupDir -PathType Container)) { throw 'Backup directory missing' }
   $r1Stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
   $r1Dump = Join-Path $r1BackupDir "metkurd-before-r1-$r1Stamp.sql"
   $r1Before = Join-Path $r1BackupDir "metkurd-r1-before-$r1Stamp.txt"
   $r1After = Join-Path $r1BackupDir "metkurd-r1-after-$r1Stamp.txt"
   $r1ChecksSql = Join-Path $r1BackupDir "metkurd-r1-checks-$r1Stamp.sql"
   $r1ChecksDoc = Get-Content -Raw docs/metkurd/RELEASE-R1-CHECKS.md
   $r1Sql = [regex]::Match($r1ChecksDoc, '(?s)```sql\r?\n(.*?)```').Groups[1].Value
   if ([string]::IsNullOrWhiteSpace($r1Sql)) { throw 'Read-only SQL block missing' }
   [System.IO.File]::WriteAllText($r1ChecksSql, $r1Sql, [System.Text.UTF8Encoding]::new($false))
   $r1SourceCommand = 'SOURCE ' + $r1ChecksSql.Replace('\','/')
   & 'C:/xampp/mysql/bin/mysqldump.exe' --host=$r1DbHost --port=$r1DbPort --user=$r1DbUser --password --single-transaction --routines --triggers --events --hex-blob --result-file=$r1Dump $r1Schema
   if ($LASTEXITCODE -ne 0 -or (Get-Item -LiteralPath $r1Dump).Length -eq 0) { throw 'Backup failed' }
   Get-FileHash -Algorithm SHA256 -LiteralPath $r1Dump
   & 'C:/xampp/mysql/bin/mysql.exe' --host=$r1DbHost --port=$r1DbPort --user=$r1DbUser --password --database=$r1Schema --batch --execute=$r1SourceCommand > $r1Before
   if ($LASTEXITCODE -ne 0) { throw 'Before checks failed' }
   ```

   A nonempty dump/hash is not restore acceptance; confirm the backup is recoverable
   before proceeding. No backup/import was performed by R1.
5. Recheck/preview. Based on unchanged R1 history, expect the seven original pending
   names plus `2026_09_07_000001_normalize_v2_launch_pricing`, with P0 already Ran:

   ```powershell
   php artisan migrate:status
   php artisan migrate --pretend
   ```

   Stop on changed pending set, partial schema, preview failure or destructive SQL.
   Under `--pretend`, registration SELECTs return no data and their INSERTs do not
   create actions. The correction therefore explicitly skips its data-dependent
   body in pretend mode: no corrective SQL preview or acceptance is claimed.
   In a real run missing/mismatched registrations still abort the transaction.
   The full registration/correction path and pretend safety are covered by
   isolated migration tests. No new
   pretend run against the application database occurred in this source-only task.
6. **Only after the blockers and backup checks above are resolved**, manually run:

   ```powershell
   php artisan migrate
   if ($LASTEXITCODE -ne 0) { throw 'Stop and inspect partial migration state' }
   php artisan migrate:status
   ```

   Expect 74 recorded migrations and none pending if R1's 66-row baseline still
   applies; otherwise reconcile actual source/history first. Do not use `--seed`, `--force`,
   `fresh`, `refresh`, `reset` or blanket rollback as part of this local procedure.
7. With writers still stopped, run the same read-only checks and review differences:

   ```powershell
   & 'C:/xampp/mysql/bin/mysql.exe' --host=$r1DbHost --port=$r1DbPort --user=$r1DbUser --password --database=$r1Schema --batch --execute=$r1SourceCommand > $r1After
   if ($LASTEXITCODE -ne 0) { throw 'After checks failed' }
   Compare-Object (Get-Content -LiteralPath $r1Before) (Get-Content -LiteralPath $r1After)
   ```

   Catalog/schema changes are expected; wallet/ledger/customer/purchase/history
   differences are not. Follow the checklist below, rather than treating every
   diff as a failure or ignoring a failed/null fingerprint.
8. P0 already exists; do not overwrite current operator permissions as an automatic
   upgrade step. If explicit provisioning is needed, choose the verified existing
   Admin ID and complete intended capability list. For support-only access:

   ```powershell
   $r1AdminId = Read-Host 'Verified existing numeric Admin ID'
   php artisan admin:capabilities $r1AdminId admin.read --reason='Approved local R1 support access'
   ```

   This **replaces** the list, does not activate an inactive account and records an
   audit event. Select additional mutation capabilities only intentionally. Capture
   migration verification before this separate authorized audit write. Afterward
   verify active login, inactive rejection and capability behavior separately.
9. Leave both feature flags false. Resume web/workers/provider reconciliation only
   under the subsequent acceptance plan; `php artisan up` and worker restarts are
   not implied by successful schema checks. Recheck effective flags before serving.

### Exact post-migration checks and expectations

[RELEASE-R1-CHECKS.md](RELEASE-R1-CHECKS.md) contains the SQL for both before and after
migration. The original 21 statements passed during the read-only R1 MariaDB audit;
the two additional launch-policy checks were added in this source-only follow-up,
not run against the application DB. The twelve-channel policy query is exercised
in isolated SQLite migration tests. The checklist intentionally queries column/index
metadata so missing pre-migration fields do not make the checklist fail to run.

- All nine expected actions must have the correct non-null Tool-code binding and
  active flags. New defaults against the unchanged current catalog yield 16 tools,
  21 actions, 84 entitlements and 66 pricing rules; four-plan grants are resolved
  by current IDs, never invented numeric IDs. Caption's existing four prices stay
  byte-for-byte unchanged. The policy query must return twelve rows, each with
  exactly one active global rule and `launch_policy_match=1`; unexpected active
  global channels must be zero. Confirm approved rates/minimums and scoped overrides.
- Customer count, wallet counts, all balance/bucket/lifetime totals and wallet
  fingerprint must match the fresh before file. Ledger count, financial totals
  and complete-row fingerprint must match; **no ledger mutation is expected**.
  Subscription, order, payment, job and file counts remain unchanged. Historical
  anomalies remain 0 duplicate wallets, 0 bucket mismatches, 4 missing add-on
  product links and 1 overlapping status-active subscription customer.
- Confirm all eight lifecycle fields, four poll fields, API hash, four unique
  indexes and two scheduling indexes through information_schema. Required API
  unique index: `api_jobs_customer_v2_idempotency_unique`, ordered columns
  `customer_id`, `idempotency_hash`, `NON_UNIQUE=0`. Presence is not a concurrent
  request/replay acceptance test. Both Admin tables and both user fields must remain.
- Once those fields exist, run this **additional read-only** query with writers
  still stopped; expect zero in each result (new metadata has not been populated):

  ```sql
  SELECT COUNT(*) AS unexpectedly_initialized_job_metadata FROM ml_jobs
  WHERE submission_key IS NOT NULL OR endpoint_key IS NOT NULL OR model_key IS NOT NULL
     OR charge_reference IS NOT NULL OR refund_reference IS NOT NULL OR failure_stage IS NOT NULL
     OR submission_attempted_at IS NOT NULL OR refunded_at IS NOT NULL
     OR next_poll_at IS NOT NULL OR poll_locked_until IS NOT NULL OR poll_token IS NOT NULL
     OR poll_attempts <> 0;
  SELECT COUNT(*) AS initialized_api_hashes FROM api_jobs WHERE idempotency_hash IS NOT NULL;
  ```

  This extra query was **not executed** against the current schema because its
  columns are absent. Normal accepted jobs will populate these fields later.

### Existing tests and execution blockers

The five independently reproduced P4B failures were not changed or rerun as an R1
test campaign. The three legacy V1 voice/product/clone cases are catalog-fixture
issues (missing Omni default fixtures/access). The two API-credit cases are legacy
API-page balance binding and a V2 resource-meter API-row visibility expectation.
The latter is a display/access-scope fixture issue on the V2 shell: the resource
component derives visibility from `ApiCatalog::scopes()`, whereas the old fixture
expects the row from a paid plan/wallet alone. It is not evidence of
wallet reservation corruption. Under the current V2 scope they are informational,
not V2 core/schema blockers: P4B's 30 API V2 and four explicitly provisioned
Apollo/alias cases passed. They remain recorded follow-ups, not waived tests or a
fresh R1 native API acceptance claim.

Before normal migration: stop writers/disable the gates last observed enabled;
confirm recoverable backup and correct local target. Approved launch correction
removes the price/nondeterminism blockers in source; execute the entire ordered
path before any traffic. No native incompatibility
has been demonstrated that warrants speculative migration rewriting. Schema
absence itself blocks current V2 readiness. Historical billing findings require
separate reconciliation and are not repaired by this migration sequence. R1
changed only this current runbook section and its read-only SQL companion. The
subsequent launch correction adds the migration/tests and documentation recorded above.

## Baseline and limits

The sections below preserve the original 2026-09-06 dump analysis. Its eight-file
plan predates the approved correction; that same 65-recorded snapshot would now
need nine migrations. Use the current manual sequence above and actual migration
history for execution, not the historical count below.

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
authorized. Admin P1 is now implemented in source with catalog/access/quote
diagnostics and explicit/derived scope ownership; see [ADMIN-AUDIT.md](ADMIN-AUDIT.md).
It adds no migration, seeder or import-time scope/price rewrite. P2/P3/P4,
broader billing repair, pricing decisions and production rollout
remain open; a clean import does not itself resolve them.
