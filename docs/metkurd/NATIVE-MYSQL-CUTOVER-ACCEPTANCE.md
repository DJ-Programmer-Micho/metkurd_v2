# Native MySQL cutover acceptance

Status: **prepared, not executed**. SQLite fixtures prove policy/algorithm behavior;
the local MariaDB dry-run proves only that copied server's read behavior. Neither
proves Amazon RDS MySQL DDL, locks, foreign keys, JSON, isolation or rollback.

## Target and isolation

The operator previously reported RDS MySQL 8.4.8. Before selecting the native binary,
obtain current RDS Console Engine/Engine version evidence. Do not connect to production
as part of this rehearsal. Match that major version (and patch where practical).

Use a separately provisioned **native MySQL** service on the local application machine,
with a separate port (for example 3307), data directory, credentials and copied schema.
No Docker is required or prepared. Leave the existing MariaDB installation/data intact.
The local policy requires SQL hostname to match this machine; do not use a remote
instance/container and then relax the hostname policy to make it pass.

The operator must supply the installed MySQL server/client paths, protected connection
option file, service-manager start/stop commands, original production-derived snapshot
path/hash and the disposable schema. None is guessed here. Import the original snapshot
into that disposable schema, preserving its migration history. Never import the mutated
post-cutover/mock database and never seed after import. Do not alter the source dump.
Review import syntax and native MySQL errors before applying any compatibility changes.

## Protected Laravel configuration

Prepare an ignored `.env.mysql-rehearsal` containing the normal required application
configuration, test-only credentials, APP_ENV=local, and these assertions:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=<disposable native MySQL schema>
BILLING_CUTOVER_ENABLED=true
BILLING_CUTOVER_TARGET=local-rehearsal
BILLING_CUTOVER_EXPECTED_HOST=127.0.0.1
BILLING_CUTOVER_EXPECTED_PORT=3307
BILLING_CUTOVER_EXPECTED_DATABASE=<same disposable native MySQL schema>
FEATURE_APP_V2=false
FEATURE_API_V2=false
FIB_RECONCILIATION_ENABLED=false
STORAGE_ALLOW_DESTRUCTIVE_OPERATIONS=false
```

Do not commit this file or reuse production provider credentials. Explicitly exclude
it locally (for example `.git/info/exclude`) and restrict filesystem access. No workers,
scheduler, callbacks, storage cleanup, FIB polling or real collection are allowed.
Check the actual FIB configuration key in this release before starting any processes;
keeping all writers stopped is the primary isolation requirement.

In a dedicated PowerShell process, set an isolated config-cache location and clear
inherited connection URLs so they cannot override the protected connection fields:

```powershell
$env:APP_CONFIG_CACHE='bootstrap/cache/mysql-rehearsal-config.php'
$env:DB_URL=''
$env:DATABASE_URL=''
php artisan --env=mysql-rehearsal config:clear
php artisan --env=mysql-rehearsal metkurd:production-preflight
php artisan --env=mysql-rehearsal migrate:status
php artisan --env=mysql-rehearsal migrate --pretend
```

Read-only preflight must identify native MySQL and the expected schema. Its failure
before migrations is expected; inspect the reported pending migration inventory.
Verify SELECT VERSION(), DATABASE(), @@hostname, @@port, @@foreign_key_checks,
@@global.read_only, @@global.super_read_only and @@server_uuid with the approved MySQL
client/option file. The `SHOW REPLICA STATUS` check needs REPLICATION CLIENT permission;
no replication channels are allowed. Verify the app/API gates remain false.

## Operator-controlled migration and cutover rehearsal

Only after backing up this disposable schema and reviewing the exact pending inventory:

```powershell
php artisan --env=mysql-rehearsal migrate
php artisan --env=mysql-rehearsal metkurd:production-preflight
php artisan --env=mysql-rehearsal admin:capability-audit $ADMIN_ID
php artisan --env=mysql-rehearsal billing:cutover-reset-payment-domain --target=local-rehearsal --dry-run
```

Never use fresh/refresh/reset or broad seeders. Record the native DDL/index/FK/JSON results
and the full private manifest. If separately approved, use the existing audited Admin
capability provisioning command; no automatic privilege grants.

After recording the backup/restore evidence, keeping every writer stopped and reviewing
the manifest, the operator may perform the disposable native rehearsal:

```powershell
php artisan --env=mysql-rehearsal down
php artisan --env=mysql-rehearsal billing:cutover-reset-payment-domain --target=local-rehearsal --dry-run
php artisan --env=mysql-rehearsal billing:cutover-reset-payment-domain --target=local-rehearsal --execute --review-hash=$REVIEW_HASH --confirm=RESET-V2-BILLING-DOMAIN --admin=$ADMIN_ID --reason="Approved isolated native MySQL cutover rehearsal" --workers-stopped
php artisan --env=mysql-rehearsal metkurd:production-preflight --require-epoch
```

These are future operator commands, not actions executed while preparing this document.
Use only the final hash obtained after stopping writers. Native rehearsal retains the
same shared algorithm and copy-specific provider disposition; production additionally
requires real provider obligations to be resolved. Do not fake APP_ENV=production on
this copy or route an RDS endpoint to localhost to simulate production identity.

## Required acceptance evidence

- Native version/schema/endpoint/port and exact immutable release revision.
- All expected migrations Ran, no unknown history, native indexes/FKs/JSON behavior.
- No non-InnoDB tables, triggers or unsupported references in the reviewed inventory.
- Read-only review sends no provider calls and performs no DML/DDL.
- Two independent native sessions demonstrate the reviewed row/range locks and
  repeatable-read behavior. On the disposable fixture, changed data invalidates a hash.
- A deliberately induced preservation failure rolls back deletions, patches and the
  epoch audit together. Confirm this against native MySQL, not only SQLite tests.
- Successful cutover matches exact fingerprints/projections, current billing starts
  empty, customer credits/history are retained, and a second cutover refuses.
- A restore from the retained backup actually recovers the pre-cutover schema/history.
- Record success/failure and evidence paths in the production runbook. No production
  approval until these native checks and real deployment/provider prerequisites pass.

Restore normal shell configuration by closing this dedicated PowerShell process.
Maintenance mode may use a shared file path: keep the normal application stopped while
rehearsing, and explicitly restore its intended maintenance state afterward. Do not
clear normal application caches or leave a rehearsal connection in a serving process.
