# V1 → V2 production launch runbook

## Independently gated MCP addition — 2026-09-26

MCP source adds six reviewed OAuth/connection migration files to the earlier
78-file inventory (84 total), including CIMD identity widening and native client
classification. No application migrations have been run for this
addition. FEATURE_MCP_V2 remains false; neither App nor REST enablement activates
it. Follow the [MCP operator sequence and rollback](MCP.md#configuration-and-operator-rollout)
for explicit migration review, shared signing/session/cache configuration,
commercial/API plan permissions and exact public client callbacks. Do not run
seeders, passport:install, regenerate APP_KEY or assume a tested local fixture
authorizes production changes. Resolve inherited dependency advisories and run
native distributed/OAuth/client acceptance before production release.

## Current-state review — 2026-09-26

Status: **SOURCE REVIEWED; PRODUCTION ACCEPTANCE REQUIRED**. The current
[release review](CURRENT-STATE-PRODUCTION-REVIEW.md) supplies the complete twelve-product
matrix, 78-file migration inventory, endpoint/worker requirements, verification
record and ordered operator acceptance checklist. This update supersedes the old
service and migration counts below; dated historical evidence is retained.

- **SOURCE VERIFIED:** Apollo 1.5/2.0, Vector 1.5/2.0, Zeta 1.0, Theta 1.0,
  Leo, Caption, OCR Scanner, Harakat 1.0, STEM 2 and STEM 4 are current V2 products.
  Production preflight now derives required active Tool/ToolAction checks from
  the current V2 catalog instead of its former nine-action list.
- **SOURCE VERIFIED:** five endpoint configurations cover V2:
  `RUNPOD_ENDPOINT_ID_OMNI_V2`, `RUNPOD_ENDPOINT_ID_QASR_V2`,
  `RUNPOD_ENDPOINT_ID_KOCR_V2`, `RUNPOD_ENDPOINT_ID_STEM`, and
  `RUNPOD_ENDPOINT_ID_TASHKEEL_V1`. See the review for shared transport/storage
  configuration. Never print their values in release evidence.
- Zeta = `model_2 + builtin_ref_batch`; Theta = `model_2 + audio_url_batch`.
  Keep ONE Omni endpoint, multiple Serverless workers for concurrency, one model
  session per project and one final WAV. The deployed worker needs
  **`OMNI_BATCH_ENABLED=true`**; its source default stays false. Verify worker
  image/models/reference files, limits, pauses, all-or-nothing failures and warm
  cleanup before launch. Laravel/worker defaults are documented in the review;
  they are not production capacity certification.
- Harakat = `harakat.diacritize`, dedicated `tashkeel_v1`, text-only App/API,
  `HARAKAT_MAX_TEXT_CHARS=5000` default, private TXT persisted before done.
- Two catalog migrations follow the old snapshot:
  `2026_09_20_000001_register_multi_speaker_tools` and
  `2026_09_21_000001_register_harakat_tool`. They copy initial source-action
  pricing/plan entitlements into independent new actions, preserving existing
  target economics. Source actions and active pricing are prerequisites.
  No generic seeders. The former 11-pending original-snapshot count is now
  **13 only for that unchanged snapshot** (old R1 10 becomes 12). Actual target
  `migrate:status` always wins; do not infer today's pending count from this text.
- API V2 now has nine generation routes: speech, voice-clone, transcriptions,
  captions, ocr, stem, zeta, theta, harakat. Auxiliary references, voices,
  services, jobs and private downloads remain behind independent API authorization.
  Zeta shares `v2:speech`, Theta shares `v2:voice-clone`, Harakat uses
  `v2:harakat`. Copied entitlements do not automatically grant scopes. API OCR
  accepts multipart local bytes, not a customer-supplied worker URL.
- The global [Process Queue](PROCESS-QUEUE.md) reads one bounded local query,
  never provider/storage/billing operations. Verify 8-second active/60-second
  idle reads, hidden-tab pause, immediate submission event, status colors and
  session-only acknowledgement across navigation/Back/Forward/locale changes.
  All twelve result links require owned authorized workspace selection. No new
  migration. Scheduler/queue workers must persist results with browser closed.
- The current topbar has V2 Plans links in its profile dropdown, but **no
  standalone plan badge/Subscribe CTA**. Shared effective-plan data/cache authority
  exists; do not certify an absent UI feature. Mobile/RTL/browser acceptance remains open.
- **LOCAL VERIFIED:** see the current review's exact test/build record, separately
  from historical counts below: 681 broad PHP cases passed, then 11 focused
  preflight/epoch cases passed (682 distinct cases); 65 frontend tests and Vite
  build passed, as did focused PHP syntax/Pint. Isolated SQLite does not certify
  RDS DDL/locks. Concurrent maintenance work requires its own acceptance and a
  frozen final release; no production cache compilation was performed here.
- **USER-REPORTED WORKER ACCEPTANCE:** successful services, specifically Zeta/Theta,
  Harakat and repaired STEM playback, are user reports rather than independent
  verification of the production image, endpoint configuration or load capacity.
- The user reports the local migration/capability/preflight/cutover rehearsal
  already committed. **Do not rerun that local cutover.** The later Sep15 section
  describing an unexecuted fresh-copy dry-run is historical, not today's instruction.
- **PRODUCTION UNVERIFIED:** immutable release, actual twelve-action catalog and
  App/API prices/access, native RDS identity/migrations/preservation, backups/restore,
  provider disposition, live endpoint capability, private storage, workers/scheduler,
  public callbacks/FIB lifecycle and interactive locale/mobile acceptance.

Current smoke additions to section 10: test both Apollo/Vector versions, Zeta's
different catalog voices, Theta's repeated owned reference/order/0/500/1000/2000
pauses/no trailing silence, batch failure without partial success, Harakat mixed
UTF-8/max-length/TXT, and all three API routes with independent API wallet settlement.
Test voice discovery and OCR multipart uploads; STEM same-origin seeks/Range;
Process Queue with browser hidden, navigation and persisted background completion.
Read-only metadata/private downloads validate storage without enabling deletion.

Production cutover still uses `--target=production` and
`--confirm=RESET-V2-PRODUCTION-BILLING-DOMAIN`, with backup/restore confirmations,
fresh review hash and all safeguards in section 6. Migrations/catalog/pricing
changes affect whole-database fingerprints: obtain the final hash after approved
changes with writers stopped. If the target already has an epoch, verify it;
do not repeat cutover. No production operation or gate activation is authorized here.

## Historical baseline — 2026-09-15

Historical status: **BLOCKED for production execution**, updated 2026-09-15. This is the
ordered operator checklist and source-backed command inventory, not launch approval.
No production connection, deployment, migration, provider request or cutover was
performed while writing it. Do not promote the local rehearsal database to production.

Latest target-policy verification: 265 billing/Admin regression cases passed, followed
by a final 68-case cutover suite (four additional cases; 269 distinct cases across runs).
This includes the real identity policies with simulated production server
facts and the shared transaction algorithm on isolated SQLite, not native MySQL.
Pint and syntax checks pass. The configured local dry-run on the newer
`metkurd_local_260915` copy succeeded without blockers or writes; no cutover was executed.

## 1. Required release/deployment evidence — STOP until completed

| Required fact | Evidence currently available / action required |
| --- | --- |
| Immutable release revision | Workspace base HEAD `ecf4dd9bc38ba9b73973969941a75e29c0c581a2`; substantial uncommitted billing changes. This HEAD alone does **not** contain the accepted changes. Record the reviewed commit/tag and artifact digest before deployment. No release tag has been selected. |
| Production engine | Intended Amazon RDS MySQL; operator previously supplied server version 8.4.8. Obtain current RDS Configuration engine/version or operator-run `SELECT VERSION(), DATABASE();`. Local acceptance used MariaDB 10.4.28, not native MySQL DDL acceptance. |
| Application directory and release switch | Production path, web document root, deployment account and artifact delivery mechanism are not in this workspace. Record them; document root must serve the release's `public/`. |
| PHP/OS/process manager | PHP ^8.2 / Laravel 12 source; production binary, OS, PHP-FPM pool, Supervisor/systemd unit names and worker counts are unverified. No deployment/process-manager manifests or Horizon dependency establish these. Do not invent `supervisorctl`/`systemctl` service names. |
| Scheduler and queue | Get the actual cron entry or schedule:work unit, queue manager configuration, running process list and recent execution evidence. `schedule:list` is registration evidence only. |
| Provider obligations | Obtain an approved inventory/disposition of every outstanding production subscription/payment. A local deletion does not cancel remote collection. See section 6. |
| Production cutover implementation | One shared `PaymentDomainCutover` algorithm now supports explicit `local-rehearsal` and `production` identity/preflight policies. It is disabled by default. Source support is not execution approval: native MySQL rehearsal, deployment assertions, Admin readiness, backup/restore and provider disposition must pass. Never spoof APP_ENV or tunnel RDS through loopback. |

The commands below run from the **operator-confirmed release root**. Uppercase shell
variables denote operator-supplied, reviewed paths/identifiers, not known deployment
values. Never paste secrets into command arguments, shell history, this document or Git.
Unfilled deployment evidence is a launch blocker, not permission to improvise.

## 2. Backup and rollback point

1. Record old release/artifact, protected environment/configuration location, storage
   mapping, current RDS identifier, parameter group, KMS access and restoration owner.
2. Disable new checkout/submission intake at the deployment edge. Put Laravel into
   maintenance on every serving instance:

   ```sh
   php artisan down --retry=60 --render="errors.503"
   ```

   Maintenance presentation follow-up (2026-09-26): use Laravel's **file**
   maintenance driver (`APP_MAINTENANCE_DRIVER=file`) for this service-independent
   response, and apply down/up on every serving instance. Confirm the active
   configuration before the window; do not change the driver while already down.
   The view embeds its logo, CSS and EN/AR/KU copy when the command runs. The
   visitor's pathname selects Landing versus App/Admin styling and locale; no
   authenticated layout, DB, Redis, Vite manifest or network polling is needed to
   serve the resulting document. Plain `down` is also branded, but still needs
   Laravel to boot and is not the recommended option when dependencies are stopped.

   `public/index.php` wraps Laravel's **unchanged** generated maintenance script
   with response negotiation: blocked `/api/*` and JSON-preferring requests receive
   the stable `service_unavailable` JSON envelope at HTTP 503, preserving Retry-After.
   Laravel still owns exclusions, secret/cookie checks and redirects. Do not use
   `--redirect` (it changes the destination and JSON behavior), `--status=200`, or
   `--refresh` for this procedure. A private operator `--secret` remains supported;
   it is not an Admin exemption and never goes in docs or public links. The cache
   maintenance driver and alternate HTTP entry points need separate verification;
   the generated early-response script checks the local `storage/framework/down`.

   Package the view, `resources/lang/maintenance.json`, local logo and
   `app/Support/MaintenanceResponse.php` with the release. Generate the response
   while Laravel can boot, before stopping dependencies. Preserve the maintenance
   files across release switches; regenerating the view requires another successful
   down command. `php artisan up` removes Laravel's maintenance marker/response;
   Retry reloads the visitor's exact URL. See [MAINTENANCE.md](MAINTENANCE.md) for
   verification scope. This does not change cutover approval or stopped-writer rules.

3. Stop scheduler launchers, queue consumers and separate reconciliation launchers
   with the **confirmed manager commands from section 1**. Drain or stop in-flight
   writes; `queue:restart` is not a stop command. Record handling of pending jobs and
   provider callbacks. Laravel maintenance alone does not stop existing workers,
   manual commands, callbacks excluded by a deployment proxy, or remote billing.
4. Suspend/route callback delivery according to the provider's confirmed retry/retention
   arrangement. Preserve payloads privately if an approved intake buffer exists.
   No such buffer is established by this repository. Never acknowledge discarded
   production collections as successfully processed.
5. Create a named manual RDS snapshot in RDS Console → Databases → selected instance
   → Actions → Take snapshot; wait for completion and verify restore access. Record
   its ID/time privately. Restore rehearsal must establish recovery time and the new
   endpoint procedure. [AWS snapshot procedure](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_CreateSnapshot.html).
6. Also take a logical backup with an existing protected MySQL option file containing
   the reviewed host/user/TLS settings. Passwords stay in that protected file:

   ```sh
   mysqldump --defaults-extra-file="$MYSQL_BACKUP_OPTIONS" --single-transaction --routines --triggers --events --hex-blob --no-tablespaces --set-gtid-purged=OFF --databases "$MYSQL_DATABASE" --result-file="$BACKUP_SQL"
   ```

   Require exit 0, nonempty file, a recorded SHA-256 and a successful test restore.
   No concurrent DDL during the dump. Review engine/privilege requirements and GTID
   restore implications with the DBA. `--result-file` avoids PowerShell redirection
   encoding problems. [MySQL 8.4 backup options](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html).
7. Preserve S3 objects, bucket/prefix mapping, encryption keys and access policy.
   Record versioning/backup coverage; never assume a DB snapshot protects S3. Keep
   `STORAGE_ALLOW_DESTRUCTIVE_OPERATIONS=false` through rehearsal/cutover. Do not run
   cleanup commands or change customer file keys. Preserve local private audit exports
   outside the public web root.

Full rollback after financial cutover requires restoring the coordinated DB backup
and matching old code/configuration, with provider reconciliation decisions for any
collection during the window. `migrate:rollback` cannot restore deleted payment history.
After public traffic/new payments resume, restoring an old DB can lose new orders/jobs:
stop writes and reconcile those externally recorded events before any restore.

## 3. Deploy reviewed code and assets under maintenance

Use the actual artifact/release mechanism recorded in section 1. If deployment uses
Git, verify the approved immutable revision and clean deployment checkout; do not
deploy this dirty development workspace or run a broad reset:

```sh
git rev-parse HEAD
git status --porcelain
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
npm ci
npm run build
```

An artifact pipeline may build assets beforehand; then verify its `public/build/manifest.json`
and matching hashed assets rather than rebuilding on the production host. Package scripts
are in composer.json/package.json. **Do not use `composer setup`**: it includes key
generation and automatic migration. Never regenerate an existing APP_KEY.

Install the protected production configuration and shared storage paths using the
confirmed deployment procedure. The PHP/worker account needs write access to
`storage/` and `bootstrap/cache/`; actual owner/group and permission commands remain
unverified. Do not use `chmod -R 777`. Verify required PHP extensions, media binaries
(including PDF probing), private storage and release asset availability.

## 4. Exact migration inventory and catalog policy

The original final V1 dump documented in PRODUCTION-DB-IMPORT.md lacked the first eight
entries below. Three later source migrations bring the original-dump delta to eleven.
The later local R1 baseline already had Admin P0 applied; that baseline instead has ten
pending entries. **Actual migration history wins**. The already-upgraded local database
on 2026-09-15 has all 76 repository migrations Ran; none should run again there.

| Order | Migration basename | Purpose |
| --- | --- | --- |
| 1 | `2026_08_12_000000_add_submission_lifecycle_to_ml_jobs_table` | Submission lifecycle and durable job identity fields/indexes |
| 2 | `2026_08_15_000000_register_xomni_v2_tool` | Apollo 2 action/tool/access/default registration |
| 3 | `2026_08_15_000100_register_vector_v2_tool` | Vector 2 registration |
| 4 | `2026_08_16_000100_register_leo_v2_tool` | Leo registration |
| 5 | `2026_08_16_000200_register_caption_v2_access` | Caption V2 access metadata; preserve existing Caption pricing |
| 6 | `2026_09_05_000001_add_poll_coordination_to_ml_jobs` | Poll coordination fields/index |
| 7 | `2026_09_06_000001_add_v2_idempotency_to_api_jobs` | Nullable customer-scoped API V2 idempotency hash/index |
| 8 | `2026_09_06_000002_add_admin_operation_safety` | users.status where absent, capabilities, Admin operations/audit; no automatic privilege grant |
| 9 | `2026_09_07_000001_normalize_v2_launch_pricing` | Approved Apollo 2 / Vector 2 / Leo channel pricing |
| 10 | `2026_09_09_000001_create_subscription_credit_allocations_table` | Durable recurring allocation claims |
| 11 | `2026_09_13_000001_create_service_plan_agreements_table` | Dated external agreements |

```sh
php artisan migrate:status
php artisan migrate --pretend
```

Save/compare the exact pending list against the imported snapshot history. Stop on
missing/unknown migrations, partially applied DDL, unexpected deletes/drops or preview
errors. Pretend mode does not prove data-dependent registration branches or native MySQL
DDL semantics. Rehearse on the production MySQL version with the intended snapshot,
including indexes, nullable uniqueness, JSON, FK and locking checks. Only after backup
and approval, the operator runs normal deployment migration:

```sh
php artisan migrate --force
php artisan migrate:status
php artisan metkurd:production-preflight --production
php artisan metkurd:diagnose-billing-master-data
```

Require no pending/unknown migrations. MySQL DDL may commit independently; do not assume
transactional rollback of a partially completed migration. Compare saved wallet/ledger
fingerprints and important counts before/after migration.

**Required seeders: none. Required one-time catalog registration commands: none.**
The listed migrations register the V2 catalog. Never run generic db:seed, migrate --seed,
migrate:fresh/refresh/reset, DatabaseSeeder, DevDefaultSeeder, BillingMasterDataSeeder,
BillingCurrencyBootstrapSeeder, PaymentMethodSeeder, OmniToolSeeder, CaptionToolSeeder
or landing/demo seeders in this upgrade. Do not use the diagnostic's `--seed-missing`.
These can overwrite prices, currencies, methods or access.

Verify active bindings for xomni.generate, xomni-v2.generate, clone_xomni.generate,
vector-v2.generate, leo.transcribe, caption.standard, ocr.standard, stem.sep2, stem.sep4.
Apollo 2 all/app/mobile=20, api=15; Vector 2=24/18; Leo=1100/825. Caption and legacy
prices remain unchanged. Preserve explicit API scope ownership and customer overrides.

## 5. Admin authority and billing preflight

```sh
php artisan admin:capability-audit "$ADMIN_ID"
php artisan billing:cutover-inventory
```

The capability audit reads fresh active status and actual Gates; exit 0 requires
all six reported Admin capabilities: admin.read, admin.customers, admin.catalog,
admin.pricing, admin.finance and admin.reconcile. It reports missing capabilities before cutover.
Inactive Admins fail even with stored capabilities. It never provisions access. If a
deployment owner separately approves changes, the existing audited provisioning command
is `admin:capabilities USER CAPABILITIES... --reason=...`; it **replaces** the explicit
list and does not activate the user. Do not run it merely to silence a failed audit.

`metkurd:production-preflight` reports the actual database engine, migration differences,
catalog/schema checks, epoch and gates without provider/storage requests. With
`--production` it requires production/non-debug configuration, native MySQL, production
FIB profile/credential presence, HTTPS callback scheme and an asynchronous queue.
Exit 0 covers only these checks: it explicitly does not certify running processes,
remote callbacks, backup recovery or native MySQL migration acceptance.

## 6. Billing cutover — explicit deployment target

`CutoverIdentity` checks deployment assertions about Laravel's **normal connection**.
LocalRehearsalIdentityPolicy and ProductionIdentityPolicy differ only in identity/
readiness. Inventory, dependency patches, locking, deletion, audit, full-row preservation
and effective-plan verification remain the same algorithm. No free-form CLI database,
host, force or identity-bypass options exist. A missing/disabled configuration refuses
both targets. No schema name is hardcoded in the cutover PHP implementation.

Configure these in the deployment's protected environment/configuration, not in Git:

```dotenv
BILLING_CUTOVER_ENABLED=true
BILLING_CUTOVER_TARGET=production
BILLING_CUTOVER_EXPECTED_DATABASE=<operator-confirmed production schema>
BILLING_CUTOVER_EXPECTED_HOST=<operator-confirmed RDS endpoint>
BILLING_CUTOVER_EXPECTED_PORT=<operator-confirmed SQL port>
BILLING_CUTOVER_ADMIN_ID=<reviewed Admin ID>
BILLING_CUTOVER_BACKUP_REFERENCE=<private completed backup evidence reference>
BILLING_CUTOVER_RESTORE_REFERENCE=<private tested restore evidence reference>
```

These values do **not** redirect Laravel. DB_HOST/DB_DATABASE/DB_PORT continue selecting
its existing connection. Configured and actual database/port must exactly match the
assertions. The host must exactly match the asserted endpoint. Rebuild the normal
configuration cache after approved deployment configuration changes. Never print passwords
or use credentials as backup references. Use identifiers for privately retained evidence.

Production requires APP_ENV=production, mysql driver, an RDS DNS endpoint, native MySQL
version/comment/server UUID, read_only=0 and super_read_only=0, no replica channels,
foreign_key_checks=1, no split read/write connection, socket or table prefix, and InnoDB
for every inventoried table. It does **not** compare @@hostname to the app machine.
The read-only `SHOW REPLICA STATUS` check requires the DBA-approved REPLICATION CLIENT
privilege; it retains only the channel count, never remote connection details. Missing
privilege fails closed. [MySQL statement and privilege](https://dev.mysql.com/doc/refman/8.4/en/show-replica-status.html).
Writable flags are server facts; they do not grant mutation privileges to the SQL user.
Native rehearsal must demonstrate that the approved operator role can perform the exact
transaction. No write probe runs during dry-run.

Local rehearsal requires APP_ENV=local, mysql driver, loopback host, the same exact
host/schema/port assertions, FK/writable/no-split/no-prefix/no-socket checks and InnoDB.
SQL @@hostname must match the local application machine. MariaDB is allowed only for
this local target; it cannot satisfy native MySQL acceptance.

Current local configuration (not a production value): target local-rehearsal, expected
database `metkurd_local_260915`, host `127.0.0.1`, port `3306`. Change these protected
assertions when an operator selects a different copied database; no PHP edit is needed.

```sh
php artisan config:clear
php artisan billing:cutover-reset-payment-domain --target=local-rehearsal --dry-run
```

Local dry-run may omit Admin identity; the report then states no reviewed Admin.
Execution still requires fresh active finance/reconcile authorization. Production
dry-run binds the exact Admin from `--admin` or BILLING_CUTOVER_ADMIN_ID and requires
all six capabilities. Before production review:

```sh
php artisan admin:capability-audit "$ADMIN_ID"
```

Only after separate privilege approval, the supported audited replacement command is:

```sh
php artisan admin:capabilities "$ADMIN_ID" admin.read admin.customers admin.catalog admin.pricing admin.finance admin.reconcile --reason="$CAPABILITY_REASON"
php artisan admin:capability-audit "$ADMIN_ID"
```

No Tinker or automatic permission grant is part of cutover.

Before **any production deletion**, the operator must decide each old FIB obligation:
retain and map valid paid coverage, confirm termination of renewal, or explicitly
escalate an unresolved collection. Confirm remote cancellation with authenticated
provider evidence and identify late/cross-window charges. Preserve private provider
references required for support/refund/reconciliation. Never copy the local assumption
“unknown remote state may be discarded” to production. Merely changing FIB_ENV cannot
retire production subscriptions.

ProviderObligationInventory reads all provider-normalization candidates, recurring
Payments and legacy recurring/scheduled PaymentIntents, including orphaned/conflicting
references. Legacy schedules without a supported retirement proof block production
even when their local intent says cancelled. Summary counts are review items (Payments
plus retained-row conflicts), not a count of unique remote subscriptions. It classifies them as confirmed
retired/cancelled, valid coverage to preserve, requires operator review, or unresolved
remote obligation. It reuses strict timestamp parsing, FibStatusEvidence and persisted
ProviderSubscriptionCancellation confirmation. Requested POST cancellation, missing
objects, arbitrary local terminal flags and unknown evidence do not prove retirement.
Future or ambiguous normalized coverage also blocks production. Even confirmed stopped
renewal does not discard a still-paid term. **Valid coverage is reported and blocks**;
this cutover does not automatically create replacement grants or agreements. Complete
an independently approved preservation/disposition phase (or the existing coverage)
before attempting deletion. There is no provider-disposition override flag.

Unknown remote state is allowed only for an explicitly selected disposable local copy.
Neither target sends FIB requests. Remote cancellation and verified disposition are
separate operations outside the cutover transaction; no cancellation is performed here.

Production preliminary review can run before maintenance and performs no writes:

```sh
php artisan billing:cutover-reset-payment-domain --target=production --dry-run --admin="$ADMIN_ID"
```

The manifest reports identity/version/writability, migration and Admin readiness,
backup references, provider classifications, exact deletion IDs/counts, detachments,
normalizations, effective-plan projection, wallet totals, ledger count, boundary
projection, blockers and a review hash. It does not expose credentials or raw provider
payloads. Preserve the full manifest privately. A blocked review has a hash for evidence,
but that hash cannot authorize execution.

After provider disposition, backup/restore review, maintenance and stopped writers,
run a **fresh** dry-run for the same target. Whole-database changes, including sessions,
queues and audit rows, invalidate earlier hashes. Then, and only after operator approval:

```sh
php artisan billing:cutover-reset-payment-domain --target=production --execute --review-hash="$REVIEW_HASH" --confirm=RESET-V2-PRODUCTION-BILLING-DOMAIN --admin="$ADMIN_ID" --reason="$CUTOVER_REASON" --workers-stopped --backup-confirmed --restore-confirmed
```

`--workers-stopped` attests **all** queue/scheduler/callback/manual writers are stopped;
the backup/restore flags explicitly confirm the target-specific evidence references.
The command does not stop services, take backups, verify an external restore, grant
privileges or enter maintenance for the operator. Missing attestations refuse execution.
The local confirmation phrase cannot execute production. Local execution contract:

```sh
php artisan billing:cutover-reset-payment-domain --target=local-rehearsal --execute --review-hash="$REVIEW_HASH" --confirm=RESET-V2-BILLING-DOMAIN --admin="$ADMIN_ID" --reason="$CUTOVER_REASON" --workers-stopped
```

The review hash binds target, environment, configured host/schema/port, actual server
facts, migration state, production Admin capabilities, backup references, provider
disposition, schema/table/wallet/ledger fingerprints, exact candidate IDs, patches,
detachments and effective-plan results. Local and production hashes are not interchangeable.
Re-review every change. Its transaction clears only the five
processing tables, verifies preservation and inserts one Admin audit containing
`after_state.reporting_boundary`. That is the single epoch; do not create a second
timestamp in .env, reset auto increments or insert an epoch by hand.

Production command support now exists in source. **Execution approval is still blocked**
until native MySQL rehearsal and all target-specific evidence passes. See
[native MySQL cutover acceptance](NATIVE-MYSQL-CUTOVER-ACCEPTANCE.md). This phase did not
execute either cutover target. Disable BILLING_CUTOVER_ENABLED after an accepted cutover
and rebuild config cache; the committed epoch independently refuses every repeat.

After a future approved production execution, verify:

```sh
php artisan metkurd:production-preflight --production --require-epoch
php artisan billing:cutover-inventory
```

Require the recorded epoch/audit identity, exact expected cleared processing counts,
unchanged protected balances/history, consistent effective plans, empty **current**
history before the first new payment, and zero current revenue/sales. Old orders stay
in Legacy / Pre-V2 History. New failed attempts legitimately increase current payment
count without increasing paid sales/revenue. Do not erase them to force a zero result.

## 7. Table disposition and the billing epoch

A = V2 live operations; B = financial provenance; C = catalog/configuration;
D = customer balances/account state; E = pre-V2 archive. Several tables have different
roles for old and new rows. Physical operations below describe the **local** reviewed
cutover implementation; production execution still requires section 6 approval.

| Table | Classification | Physical cutover / current reporting |
| --- | --- | --- |
| payments | A; old E | Cleared locally. New current rows use committed audit timestamp **and** retained payment ID watermark. |
| payment_events | A/B; old E | Cleared with local processing history; new events retained. This explicit cutover exception is not ordinary payment-review deletion policy. |
| payment_intents | A; old E | Cleared locally; no legacy intent may initiate current reconciliation. |
| payment_transactions | A/B; old E | Cleared locally; never reinterpret old transactions as new revenue. |
| payment_webhook_events | A/B; old E | Cleared locally; unknown future callbacks cannot establish paid entitlement by themselves. |
| credit_orders | B/E for retained rows; A/B for new | Retained, reviewed old payment/intent links detached. Current totals/history use timestamp + order watermark; legacy evidence remains separate. |
| credit_products | C | Retained unchanged; no reseeding. |
| credit_wallets | D | Full rows/buckets preserved; balance never establishes paid plan access. |
| credit_ledgers | B/D | Retained continuously; do not hide service credit activity with the financial epoch. |
| credit_monthly_grants | B/D | Retained; historical grant identity remains, but invalid historical subscriptions cannot authorize new refills. |
| customer_service_subscriptions | D/A for effective rows; B/E for old | Retain history; reviewed provider rows become ended/nonrenewing and old payment links detach. Valid bounded manual grants/cash agreements remain eligible. |
| customer_storage_subscriptions | D/A; B/E for old | Same provider retirement and history retention; effective storage uses shared authority. |
| subscription_credit_allocations | B/D/A | Preserve allocation identity/history. Conflicting retained links block cutover; never delete claims to allow replay. |
| service_plan_agreements | D/A/B | Retained; effective access requires actual active agreement/customer/plan/subscription binding and valid dates. Amount is not revenue. |
| coupon_redemptions | B/A | Retain historical statuses/amounts/counters; detach only reviewed retired-payment link. No historical redemption repair. |
| coupons | C | Retained unchanged. |
| ad_conversion_events | B/E; new A | Retained with reviewed old payment link detached. Do not republish old purchase conversions as V2 revenue. |
| payment_methods | C | Retain method definitions/configuration; no generic seed. |
| customer_payment_methods | D/B | Retain account/provenance data; not proof of current paid coverage. |
| admin_audit_events | B/A | Retain prior audits plus atomic cutover manifest/epoch; never edit an old audit to change the boundary. |
| ml_jobs | A/B, continuous | Retain all AI job history; no billing epoch filter. |
| customer_files | A/B, continuous | Retain owned files, references and service outputs; no billing epoch filter or S3 deletion. |
| customer_usages / usage_events | D/B, continuous | Retain usage and counters; no billing epoch filter. |

BillingReportingBoundary reads the latest committed cutover audit. Current revenue,
sales, customer payment history, Admin current orders/payments, checkout blockers,
scheduled reconciliation and fulfillment use that authority. Historical status labels
remain recorded facts, not effective access. Job/usage/file timelines remain continuous.

## 8. Caches, workers and scheduler

After migration/cutover checks, while writers remain stopped, prepare caches from the
final production configuration and correct release:

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Require successful exit and actual Admin/Livewire HTTP smoke rendering, not route:list
alone. Anonymous `admin::pages.*` components remain view-based Livewire components;
do not rewrite routes to satisfy an IDE warning. Source tests use isolated cache paths.

The effective plan resolver rereads normalized eligibility; AppShell cache keys include
the effective plan/subscription and billing boundary, and Admin dashboard keys include
database identity/boundary. Existing unrelated cached data can be cleared with:

```sh
php artisan cache:clear
```

Run that **only after all writers/lock users are stopped** and only against the verified
application cache namespace/store, never a shared unrelated deployment. Clearing locks
under running workers can permit duplicate work. Do not use optimize:clear as an
unreviewed broad cache/lock reset. Native engine and deployment cache acceptance remain
required. Follow cache preparation with worker startup; queue:restart only signals an
already managed fleet and requires a shared persistent cache:

```sh
php artisan queue:restart
php artisan queue:failed
php artisan schedule:list
```

Do not blindly retry all failed jobs. An ambiguous paid provider submission needs its
own recovery policy. Inspect actual queue status/process heartbeats and non-sensitive
job completion evidence before declaring workers healthy.

| Worker / job | Source contract | Deployment requirement |
| --- | --- | --- |
| Laravel queue consumers | config/queue.php defaults to database / default queue; redis and other backends configurable. No explicit queue override found for required jobs. | Confirm effective connection/queue name from deployed config. Source does not define fleet size or manager unit names. Record actual commands and counts. |
| ReconcileMlJob | Queued ML completion persistence, timeout=240 seconds, tries=1 | Consumers must service its configured default queue. retry_after must exceed timeout. This does not replace remote GPU workers. |
| ProcessFibPaymentStatus | Queued callback status verification; normal fulfillment listener is synchronous inside processing | Worker-level retry/timeout defaults must be explicitly set in reviewed process configuration; source provides no per-job timeout/tries here. |
| SyncPendingPaymentIntentJob | Compatibility intent status fallback; max attempts from FIB_STATUS_SYNC_MAX_ATTEMPTS (5), delay 15 seconds by default | Keep old queued IDs from replaying retired intent state; review queue disposition during cutover. |
| ProcessYoutubeDownloadJob | Shared/legacy optional work: timeout=7200, tries=1 | Do not broaden V2 scope to YouTube; if it remains on the same queue, reserve capacity and honor its longer timeout. |

Database/Redis/Beanstalk retry_after defaults to 10800 seconds. Do not shorten below
the longest served job. No Horizon is required by composer.json. The actual worker
start/stop commands, process counts and OS ownership are section 1 blockers; no guessed
Supervisor/systemd command in this document is an executable production instruction.

Scheduler registration in routes/console.php (cadences in configured app timezone):

| Command | Cadence | Gate / purpose |
| --- | --- | --- |
| `ml-jobs:reconcile --limit=200` | Every minute | No App/API feature gate at registration; queue local completion recovery |
| `ml-jobs:mark-stale-failed --queued-minutes=30 --processing-minutes=60` | Every 10 minutes | Existing stale job policy; inspect ambiguous/paid jobs before manual runs |
| `billing:process-service-agreements` | Every minute | Independent of FIB reconciliation; activate/expire dated local agreements |
| `credits:refill-monthly` | Daily 00:15 | Shared cycle authority/idempotent monthly allowances; no old provider refill |
| `payments:reconcile-fib-cancellations --limit=100` | Every 5 minutes | FIB_RECONCILIATION_ENABLED; retry recorded current cancellation intent |
| `payments:reconcile-fib-payments --chunk=100 --stale-minutes=5` | Every 5 minutes | FIB_RECONCILIATION_ENABLED; defaults configurable |
| `subscriptions:reconcile --chunk=100 --stale-minutes=5 --grace-minutes=0` | Every 10 minutes | FIB_RECONCILIATION_ENABLED; invokes provider reconciliation and local service/storage expiry |
| `youtube:cleanup-expired --limit=200` | Every 10 minutes | Shared optional cleanup; keep destructive storage operations disabled until separately approved |
| `api:cleanup-expired-files --limit=200` | Every 10 minutes | Private API result retention; inspect configured deletion policy |

All named events have overlap locks; shared Redis/database/memcached/dynamodb cache
enables onOneServer. File/array cache does not coordinate a multi-node scheduler.
Exactly one approved cron launcher or managed schedule:work process must be established.
The conventional cron command is `php artisan schedule:run` each minute **from the
confirmed release directory using the confirmed PHP binary**. The repository does not
prove a production crontab exists. Do not run schedule:run during forensic/rehearsal reads:
it invokes real billing, provider and cleanup work. Require observed scheduled execution
and queued completion after approved restart, not merely a listed schedule.

## 9. FIB, gates and return to service

Configuration checklist (names only; never print credential values):

- Production FIB_ENV and FIB_ENABLED, PAYMENTS_FAKE_ENABLED=false; separate payment
  and subscription client credentials and base URLs. Generic FIB_* overrides take
  precedence over profile-suffixed/legacy values in config/fib.php; inspect resolved
  profile ownership privately. A production historical ID must not be queried with
  staging credentials to establish production payment evidence.
- FIB_CALLBACK_BASE_URL is the public HTTPS base. Payment callback route is POST
  `/payments/webhooks/fib`; subscription callback is POST
  `/payments/webhooks/fib/subscription`. Verify DNS, TLS, proxy headers, provider reachability,
  CSRF exclusion, bounded notifications, throttling, queue delivery and authenticated
  status verification. A local callback secret is optional transport filtering, not
  invented FIB signature evidence; confirm provider can send its configured header.
- Verify recurring create, collection, renewal and cancellation against the approved
  production profile, with operator-approved transactions. A callback itself does not
  prove payment. Confirm correct future paid-through dates and cancellation GET evidence.
- Keep FEATURE_APP_V2=false and FEATURE_API_V2=false during initial deployment review.
  Keep FIB_RECONCILIATION_ENABLED=false while financial writers are intentionally stopped;
  this does not disable local agreement/refill schedules or callback routes.
- Enable FEATURE_APP_V2 only after migrations, cutover and preservation pass. Rebuild
  config cache, verify worker/scheduler/callback readiness under maintenance using the
  approved deployment bypass/smoke mechanism. Enable FEATURE_API_V2 separately only after
  explicit API acceptance, scopes, quotas and private result checks. App enablement does
  not imply API enablement. Restore FIB reconciliation only after provider disposition
  and current-epoch queue checks pass. No gates were changed by this task.

Do not use a public maintenance bypass or exempt provider callbacks without the approved
intake plan. Once checks, approved smoke access, workers, scheduler and callbacks are
ready, complete the launch record and only then leave maintenance:

```sh
php artisan metkurd:production-preflight --production --require-epoch
php artisan admin:capability-audit "$ADMIN_ID"
php artisan up
```

## 10. Smoke and final PASS checklist

- Account/Profile: owned identity, verification, no cross-customer data.
- Subscription/Storage/Add-ons: identical effective plan, eligible methods, correct
  amounts/intervals, no legacy checkout block; Free add-on restrictions remain explicit.
- Billing/Payment: current epoch only, first new order counted once; failed attempts
  are not revenue; no old order reappears. App/API/add-on buckets stay separate.
- FIB recurring: verified initial collection and renewal, duplicate callback/replay,
  Cancel/Upgrade/Downgrade and old-provider cancellation recovery without duplicate credits.
- API: explicit scopes, Free/paid eligibility, gate independent from App, ownership,
  idempotency and reservations. Carried API credits alone do not grant API plan access.
- Apollo, Vector, Leo, Caption, OCR, STEM2/STEM4: submission, active status, persistence,
  billing, owned preview/download. Storage and old History remain continuous.
- Admin: dashboard revenue, Operations, Customer Register, current plan agreement,
  finance/reconcile capability enforcement, separate legacy evidence.
- EN, AR, KU: translated errors/actions, RTL, mixed references and mobile layout.
- Release record: exact revision/artifact, migration list, epoch/audit ID, manifest hash,
  original/post counts and fingerprints, backup IDs and owner sign-off.

**Production PASS requires every item above and verified worker/scheduler/callback
execution. NO LAUNCH on missing Admin capabilities, migration/cutover mismatch,
wallet fingerprint mismatch, unavailable scheduler/worker, invalid callback, inconsistent
effective plan, unresolved provider disposition, or unapproved production cutover code.**

## Local evidence, 2026-09-15

Customer 1 selected old manual subscription 407: status active/ends_at null, but its
recorded term ended July 2. The previous cutover retained the row; shared eligibility
missed its metadata term. No cache/wallet rule caused that selection. The new epoch
authority rejects expired/ambiguous local terms without changing the historical row.

V2 Billing previously UNIONed native Payments and detached CreditOrders without an
epoch. Both branches now use BillingReportingBoundary. Browser checks show customer 1
Free in Billing, Subscription Plans, Admin Register/Operations, with Free API eligibility;
App 270,021 and API 300,000 preserved. Old jobs and legacy Admin orders remain visible.
Two failed post-cutover attempts (174/175) already existed, so current history correctly
contained two attempts, not zero. Revenue and paid sales were zero.

The first successful **mock** purchase was created through CreatePlanSubscriptionPayment,
mocked FibSubscriptionClient status and normal SyncFibCheckoutStatus/fulfillment for
synthetic customer 1083, Payment 176. No provider/network/notification was sent.
Free→Pro; App=250,000/API=300,000; replay unchanged; paid orders 0→1, local test revenue
0→24,000 IQD, current payment attempts 2→3. Every pre-existing protected row fingerprint
matched. Customer 1 remains Free. The synthetic receipt is explicitly marked in metadata
and a local-rehearsal audit; it is **not real revenue or production provider acceptance**.
Do not promote this database or feed its mock provider reference to live reconciliation.

Native MySQL execution, final production revision, service-manager configuration,
production scheduler/workers, public callbacks and production cutover remain unverified.

### Later deployment-target review on the fresh local copy

The operator's normal connection now selects `metkurd_local_260915`. Protected cutover
assertions select local-rehearsal / 127.0.0.1 / 3306 / that schema. SQL reported MariaDB
10.4.28, METone, foreign keys enabled and read_only=0; migrations have no pending entries.
The read-only manifest selected 155 Payments, 38,941 PaymentEvents, two transactions,
one intent and zero webhook events. It retained App/API wallet totals 12,262,194 /
2,600,000 and 7,356 ledgers, with zero cutover blockers. Unknown provider obligations
were inventoried and ignored only under the disposable-copy policy; production would
block them. This evidence concerns a **new pre-cutover copy**, not a rerun of the earlier
committed epoch or promotion of its mock payment. Exact manifests remain private.
Final read-only review returned exit 0 and no blockers. Protected customer, wallet,
ledger, order, subscription, job/file and all processing-table fingerprints matched
the initial review exactly. No database mutation was performed.

### Verification record

- Interactive signed-in customer 1: Billing and Subscription Plans show Free;
  API Keys rejects paid-plan access; App/API balances remain 270,021/300,000.
  No legacy checkout blocker; existing jobs and usage remain available.
- Interactive Admin: Customer Register and Operations agree on Free; retained
  subscriptions are labelled historical evidence. Legacy / Pre-V2 History shows
  the old orders separately. The synthetic customer's register shows Pro and one
  applied LOCAL-MOCK receipt. Dashboard after that mock shows one paid plan,
  one service-plan order and 24,000 IQD; storage/add-on revenue remains zero.
- The synthetic customer's own Payment History was verified through isolated
  Livewire tests, not by impersonating that customer in the signed-in browser.
- 441 selected PHP regression cases were run: after focused fixture corrections
  and reruns, 440 pass. The remaining existing V1 `FibPaymentFlowTest` assertion
  at line 2476 expects English redirect text on an Arabic page. It remains failed;
  no V1 localization behavior was rewritten to silence it. All ten new epoch
  cases pass across the focused runs, alongside Admin P0 and billing lifecycle tests.
- 23 Admin/account/payment frontend tests pass, including EN/AR/KU catalog and
  replacement-token checks. Focused Pint and PHP/Blade syntax checks pass.
- Configuration, routes and all Blade templates compiled successfully using
  isolated private cache paths. Normal application cache/config paths were untouched.
- No new migrations, seeders, second cutover, provider requests, production access,
  price changes or feature-gate changes were performed. Test seeding was confined
  to explicitly isolated SQLite memory databases.

These results accept the checked local post-cutover behavior; they are not a full
production billing sign-off, native MySQL acceptance, or live FIB collection evidence.
