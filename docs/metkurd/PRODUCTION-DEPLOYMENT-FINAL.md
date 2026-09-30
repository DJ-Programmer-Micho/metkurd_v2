# FINAL MetKurd V2 production deployment runbook

## 1. Release freeze — [LOCAL WORKSTATION]

**2026-09-30 provider-disposition follow-up:** follow
[PROVIDER-OBLIGATION-REVIEW.md](PROVIDER-OBLIGATION-REVIEW.md) before the final cutover
review. The release adds one retained-coverage migration and an audited, explicit
approval workflow. The subsequent [batch review](PROVIDER-OBLIGATION-BATCH-REVIEW.md)
adds a second review-evidence migration, private manifests, bounded GET-first remote
retirement and existing paid-coverage approvals. Cancellation alone does not certify
November coverage. Fake/manual provenance survives in orders and cutover audit;
all five old processing tables are deleted. DRAFT 159/160 remain unresolved.
No migration, production connection, disposition or cutover was executed by this task.

**Status: operator procedure prepared; production execution is NOT accepted yet.**
Reviewed 2026-09-30 against source base `2814d4fb032c136416986fd91be570793ff0b1d5`.
That base does not include this new document. Commit and review the complete final
release, including this runbook and any acceptance fixes, before choosing its SHA.
This document supersedes the execution sequences and migration counts in
[V1-TO-V2-PRODUCTION-RUNBOOK.md](V1-TO-V2-PRODUCTION-RUNBOOK.md). Linked engineering
documents explain contracts; they are not additional commands to execute in parallel.

- [ ] All intended source, lockfiles, migrations and documentation committed and pushed to GitHub.
- [ ] Reviewer-approved immutable commit SHA or tag; record its resolved SHA and artifact digest.
- [ ] Clean deployment working trees; no local production edits or untracked executable files.
- [ ] VM2 and VM3 deploy exactly the same resolved SHA and compatible built assets.
- [ ] Final isolated tests/security review and native MySQL rehearsal refer to that release.
- [ ] Record old release SHA/artifact and protected configuration reference for rollback.

Do not deploy an unknown moving `main`. Do not deploy the local rehearsal database.
Do not treat previous source-test results as production acceptance. Existing Admin
evidence includes three customer V1 Profile/Billing redirect-test failures; classify
them against the intentional V1 retirement, and test actual V2 account entry.

**PRECONDITION:** release reviewer has approved the immutable ref; local checkout is clean.
**COMMAND — local repository writes only:**

```sh
git fetch --tags --prune origin
git checkout --detach <RELEASE_TAG_OR_SHA>
```

**EXPECTED RESULT:** checkout resolves to the approved immutable source.
**STOP IF:** ref is unreviewed, checkout would overwrite changes, or expected SHA differs.

**READ-ONLY verification:**

```sh
git rev-parse HEAD
git status --porcelain
```

Record both outputs privately; `git status --porcelain` must be empty. The commands
below are instructions for a later authorized deployment, not an executable script.
Run blocks separately, check exit status immediately, and stop at each failed gate.
Shell blocks target the operator-confirmed Linux deployment shell, not PowerShell.
Never put passwords, tokens, keys, signed URLs or private manifests in Git or tickets.

## 2. Release scope and topology — [BOTH VM2 + VM3]

This is the real V1 → V2 release: App V2, gated V1 retirement, database/catalog
upgrade, twelve current products including Zeta/Theta/Harakat, API V2, Process
Queue, public discovery, consolidated Admin and production billing cutover. MCP
schema/source ships, but MCP stays disabled for the initial release unless its
separate controlled acceptance is explicitly approved. No new MCP/Omni endpoint.

| Execution label | Responsibility |
|---|---|
| [VM2 — BOTH NODES] and [VM3 — BOTH NODES] | Identical code/dependencies/assets, effective config, maintenance markers, cache compilation and verified local process control |
| [ONE VM ONLY — SHARED DATABASE OPERATION] / [ONE APP NODE ONLY] | One nominated node performs the shared migration run, conditional capability provisioning and single production cutover |
| [EXTERNAL / AWS / RUNPOD / CLOUDFLARE / FIB] | Backups/restore, edge quarantine, worker images/capacity, provider disposition and callback delivery |
| [POST-DEPLOY] | Node-specific and public acceptance, controlled traffic opening and observation |

Fill this private deployment worksheet **before the window**. Missing entries mean
NO-GO, not permission to guess:

| Required item | Operator entry |
|---|---|
| VM2 release directory, public document root, deploy user/group | `<OPERATOR-CONFIRMED VALUE>` |
| VM3 release directory, public document root, deploy user/group | `<OPERATOR-CONFIRMED VALUE>` |
| In-place vs versioned release switch; persistent storage mapping | `<OPERATOR-CONFIRMED VALUE>` |
| Nominated shared-DB execution node, operator and reviewer | `<OPERATOR-CONFIRMED VALUE>` |
| PHP CLI/FPM binaries/version, extensions, FPM reload command | `OPERATOR MUST INSERT VERIFIED COMMAND` |
| Queue manager, connection/queue names, workers, stop/start/status commands | `OPERATOR MUST INSERT VERIFIED COMMAND` |
| Scheduler cron/unit/launcher and separate reconcilers: stop/start/status | `OPERATOR MUST INSERT VERIFIED COMMAND` |
| Load balancer/Cloudflare quarantine, trusted test access, node targeting, restoration | `OPERATOR MUST INSERT VERIFIED COMMAND` |
| RDS writer identifier/endpoint/schema/port, current engine/version, backup/restore owners | `<OPERATOR-CONFIRMED VALUE>` |
| Protected Redis/DB/provider/key configuration references, not values | `<OPERATOR-CONFIRMED VALUE>` |
| S3 bucket/prefix/versioning/KMS ownership and protected backup locations | `<OPERATOR-CONFIRMED VALUE>` |
| Private smoke-test customer, approved spend/credits and result retention | `<OPERATOR-CONFIRMED VALUE>` |

There is no source-backed Supervisor/systemd unit name or production filesystem
ownership command. Do not invent one. Do not use `sudo chown -R root:root .` or
`chmod -R 777`. Document root must be the release's `public/`, never repository root.

## 3. Pre-write READ-ONLY gate — [BOTH VM2 + VM3] [AWS RDS]

Before maintenance, migrations, cutover or configuration changes, inventory the
running deployment and the reviewed candidate. If a new command is unavailable in
the old release, use a separately prepared candidate CLI with the verified production
connection for **only these read-only checks**. Do not switch serving code early.
Do not boot old/new releases against an unverified connection or silently use a
stale candidate `bootstrap/cache/config.php`.

**READ-ONLY commands, from the appropriate verified release root on each node:**

```sh
git rev-parse HEAD
git status --porcelain
php --version
composer check-platform-reqs --no-dev
node --version
npm --version
php artisan migrate:status
php artisan admin:capability-audit 1
php artisan billing:cutover-inventory
php artisan metkurd:diagnose-billing-master-data
php artisan metkurd:production-preflight --production
php artisan schedule:list
```

`diagnose-billing-master-data` is read-only **without `--seed-missing`**. Never add
that option or `--force` to turn diagnostics into a seed. Preflight may report
expected missing migrations/schema/catalog before upgrade; save that exact gap list.
Missing-schema commands may be unavailable: record that and repeat after migration.
Connection/identity errors are not expected migration gaps. No failed check is waived
for the final pre-cutover gate. Do **not** use `--require-epoch` before the epoch exists.

`production-preflight` checks source/applied migration agreement, active catalog
actions/parents, Admin/allocation/agreement schema, production/debug/native-MySQL,
FIB profiles/HTTPS callback and an asynchronous queue. It does **not** verify prices,
endpoint contents, callbacks, storage, actual processes, backups or all feature flags.

The `production_environment` check combines two conditions exactly:
`app()->environment('production') && ! config('app.debug')`. If the same command
reports environment=production but this check=false, effective debug configuration
is truthy. APP_ENV alone is insufficient; cached configuration/process overrides
may differ from the edited environment file. Inspect only the effective nonsecret
values; this diagnostic is not an instruction to alter production configuration:

```sh
php artisan tinker --execute='dump(["environment" => app()->environment(), "debug" => config("app.debug"), "configuration_cached" => app()->configurationIsCached()]);'
```

For the payment-event source-width incident, see the dated
[billing acceptance follow-up](BILLING-AUDIT.md#production-source-width-acceptance-follow-up--2026-09-30).
No schema migration or automatic provider-batch replay is needed for that source fix.

Record actual RDS `SELECT VERSION(), DATABASE();` through the approved DBA read-only
console and compare it with Laravel's inventory on **both nodes**. Historical 8.4.8
reports are not current evidence. XAMPP/phpMyAdmin/MariaDB does not certify RDS MySQL.
The production cutover further requires an asserted RDS hostname, native MySQL UUID,
no replica channels, writable server, foreign keys enabled, InnoDB tables, no split
read/write connection, prefix or socket. Do not bypass missing inspection privileges.

**READ-ONLY sanitized configuration inspection** (prints booleans/nonsecret flags,
not credentials; review the candidate bootstrap first):

```sh
php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$safe = [];
foreach (['app.env','app.debug','app.url','app.maintenance.driver',
    'customer_app.v1_enabled','metkurd_v2.enabled','customer_api.v2_enabled','mcp.enabled',
    'cache.default','session.driver','queue.default','mcp.session_store',
    'fib.environment','fib.enabled','fib.reconciliation.enabled','payments.fake_enabled',
    'filesystems.customer_outputs.allow_destructive_operations','billing_cutover.enabled'] as $key) {
    $safe[$key] = config($key);
}
foreach (['app.key','services.turnstile.site_key','services.turnstile.secret_key',
    'runpod.api_key','runpod.endpoints.omni_v2','runpod.endpoints.qasr_v2',
    'runpod.endpoints.kocr_v2','runpod.endpoints.stem','runpod.endpoints.tashkeel_v1',
    'fib.profiles.payment.client_id','fib.profiles.payment.client_secret',
    'fib.profiles.subscription.client_id','fib.profiles.subscription.client_secret'] as $key) {
    $safe[$key.'.present'] = filled(config($key));
}
echo json_encode($safe, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
PHP
```

Presence is not validity. Do not dump `.env`, `config:show`, the whole configuration,
provider debug output or process environments. Obtain read-only process-manager
status, configured launch arguments and recent successful scheduler/job timestamps
using `OPERATOR MUST INSERT VERIFIED COMMAND`; redact secrets. `schedule:list`
proves registrations only. Inspect storage connectivity by approved existing-object
metadata/read access on each node, without delete/upload or exposing signed URLs.

## 4. Environment and infrastructure worksheet — [BOTH VM2 + VM3]

Source names are listed in Appendix B. Set only reviewed production values through
the protected deployment mechanism in section 8, not by pasting secrets here.

| Area | Required production review |
|---|---|
| Runtime | PHP ^8.2 plus locked platform requirements; Composer; Vite 7 requires Node ^20.19.0 or >=22.12.0. Verify PHP CLI **and** FPM parity, cURL/OpenSSL, PDO MySQL, mbstring/DOM/XML/ZipArchive and the Redis client used. Check `ffprobe`, `ffmpeg`, Poppler `pdfinfo` and allowed process execution. |
| App | `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://metkurd.ai`; stable shared `APP_KEY` and existing previous-key policy. Never generate a new APP_KEY. Review locale/timezone rather than changing financial boundaries. |
| Maintenance | Effective `APP_MAINTENANCE_DRIVER=file`; local markers must cover both nodes and survive release switches. Do not change driver while down. |
| DB | Same approved RDS writer/schema on VM2/VM3; protected credentials, TLS CA and effective connection including possible `DB_URL` override. No development DB, tunnel or replica shortcut. |
| Redis | Shared production cache, sessions, locks and rate limits. `CACHE_STORE=redis`, `SESSION_DRIVER=redis` are the reviewed target; explicitly verify connection/store mappings. Same application `CACHE_PREFIX`/`REDIS_PREFIX` on both nodes, isolated from development/other apps; separate logical databases/connections as configured. Never include node-specific prefixes for shared locks. |
| Queue | Real persistent asynchronous backend; no sync/null/deferred/background production substitute. Verify actual queue names and `retry_after`/visibility timeout exceeds longest job timeout and shutdown grace. Source DB/Redis retry-after defaults 10,800s; ReconcileMlJob timeout 240s/tries 1. Audit any retained long-running legacy jobs separately. |
| Sessions | Same session store/cookie/domain/path and APP_KEY on both nodes; secure/HTTP-only cookies and reviewed SameSite behavior. OAuth login/consent must survive switching nodes. |
| Ingress | HTTPS and verified `TRUSTED_PROXIES`; source defaults to broad `**`, which is not proof of safe production ingress. Do not trust arbitrary forwarded host/proto. Cloudflare/LB rules must protect private auth/API/MCP responses from public caching. |
| Turnstile | `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY` for production domain; browser widget and server verification on customer and Admin sign-in. Missing/invalid/expired/provider-failed challenges block login. Do not copy localhost/test-key behavior into production. |
| Uploads/storage | Shared `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` (or existing `LIVEWIRE_TEMP_DISK`), directory, PHP/ingress limits and private permanent storage. Default Livewire bound 102,400 KiB; verify product bounds too. `OCR_PDFINFO_BINARY` must name the actual deployed executable, never a local Windows path. |
| GPU transport | `RUNPOD_API_KEY`, five endpoints in section 11, `RUNPOD_V2_INPUT_HOSTS` plus configured S3 origins; trusted signed HTTPS inputs. `RUNPOD_V2_TIMEOUT` is HTTP transport timeout, not OCR execution duration. |
| FIB | `FIB_ENV=production`, `FIB_ENABLED=true`, `PAYMENTS_FAKE_ENABLED=false` (source fake default is true), both payment/subscription credential profiles, `FIB_CALLBACK_BASE_URL=https://metkurd.ai`, reviewed `FIB_RECONCILIATION_ENABLED`. Disable hourly-testing/diagnostic behavior unless separately approved. |
| Other retained integrations | Verify actual mail/SMS/social auth/notifications and any enabled payment provider. Do not activate unused Areeba or legacy integrations just because config exists. Review redaction and log retention. |
| MCP preparation | Schema included even when disabled. Later shared signing keys, public URL/issuer, origins, HTTPS/proxies and shared Redis acceptance in section 19. |

**Credential hygiene finding:** `config/services.php` contains a credential-shaped
Telegram token fallback. Do not copy it or rely on it. The release security owner
must determine exposure, revoke/rotate if live, and approve removal of the embedded
fallback through a separately reviewed source change before release freeze. This
documentation task does not alter credentials/source. Providing a production env
override alone does not resolve a potentially exposed credential.

Writable paths for the approved PHP/worker user: `storage/framework/` (including
views, sessions/cache where used, maintenance marker/response), `storage/logs/`,
`storage/app/private/`, required private temporary probe/export directories and
`bootstrap/cache/`. Local uploads/results still needed by history must survive a
release switch. Private storage and OAuth keys must never be linked into public/.
Only existing public assets may use the approved public storage link.

## 5. Backup readiness before maintenance — [AWS RDS] [EXTERNAL]

Before authorizing the write window, record a tested restore procedure, permitted
DB backup method, named snapshot convention, old release artifact, protected env
backup/reference and S3 preservation plan. Final quiescent backups follow section 7.
An untested restore or unavailable backup owner is NO-GO.

S3/object storage is **not** included in an RDS snapshot. Review private bucket/prefix
identity, versioning/lifecycle rules, KMS access, retention, replication/backups and
every external deletion policy. Preserve customer ownership and keys. Keep
`STORAGE_ALLOW_DESTRUCTIVE_OPERATIONS=false` through deployment/cutover acceptance;
it is not a universal stop switch for every legacy storage routine (section 16).

## 6. Maintenance and stop writers — [CLOUDFLARE] [BOTH VM2 + VM3]

**PRECONDITION:** freeze/read-only review complete, backup/restore mechanism proven,
window approved, exact edge and process controls filled in. The branded maintenance
view/entry-point support must be available in the release used to generate the page;
if the old release lacks it, prepare the new candidate offline under edge quarantine
and verify maintenance markers before routing any request to it.

**COMMAND / ACTION — ingress write:** `OPERATOR MUST INSERT VERIFIED COMMAND` to
quarantine public intake at the actual LB/Cloudflare layer, keeping only authorized
test access. Include direct origin/alternate ingress paths and callback routing.

**EXPECTED RESULT:** no new public submissions/checkouts; both origins covered.
**STOP IF:** any origin remains reachable for writes or provider delivery can be lost.

**PRECONDITION:** both nodes boot, file driver confirmed, private operator access
strategy and existing requests accounted for.
**COMMAND — [VM2 — BOTH NODES], then [VM3 — BOTH NODES]:**

```sh
php artisan down --retry=60 --render="errors.503"
```

**EXPECTED RESULT:** both nodes serve branded 503; HTML EN/AR/KU and API/JSON
`service_unavailable` preserve 503/Retry-After. Pre-rendered response needs no DB,
Redis, Vite or authenticated layout. Verify each origin, not just a sticky LB session.
**STOP IF:** one node remains up, cached public 200 appears, or maintenance files do
not persist across the chosen release mechanism. Never use `--redirect`, `--refresh`
or HTTP 200 for this procedure. See [MAINTENANCE.md](MAINTENANCE.md).

If required for private acceptance, Laravel's existing secret bypass may be added
to the same down command with `--secret="<PRIVATE_OPERATOR_BYPASS>"` **only** after
approval under the same preconditions. Keep its actual value/cookie out of docs,
logs, public links and screenshots; distribute privately and verify both nodes.
It does not bypass customer/Admin authorization or stop workers.

**STOP-WRITERS checklist — all are required:**

- [ ] Stop scheduler launcher(s) on both VMs using verified process-manager/cron controls.
- [ ] Stop/drain Laravel queue consumers; prevent manager automatic restart. Record
  in-flight and queued MlJob/payment work and any already accepted GPU IDs.
- [ ] Stop separate/manual `ml-jobs:reconcile`, stale handlers, payment/subscription/
  cancellation reconciliation, service-agreement and monthly-credit commands.
- [ ] Stop deploy hooks, maintenance scripts, retention tasks, imports, Admin writes,
  health checks that write, and database-session/cache activity affecting the hash.
- [ ] Block callbacks/intake using the provider-approved retry/retention procedure.
  Repository source establishes no durable external callback buffer. Never return
  a success acknowledgment for discarded payment notifications.
- [ ] Check no other app node, console operator or integration still writes shared DB.
- [ ] Record stopped process state and in-flight completion before final snapshot/hash.

**PRECONDITION:** exact launchers and in-flight stop/drain behavior verified.
**COMMAND — both nodes/external writers:** `OPERATOR MUST INSERT VERIFIED COMMAND`
for each stop, disable-auto-restart and status check from the worksheet.
**EXPECTED RESULT:** all writers quiescent; queue contents preserved.
**STOP IF:** unknown writer/active transaction, ambiguous paid submission or unhandled
callback window. `queue:restart` requests graceful worker recycling; it does **not**
stop the queue manager. Maintenance does not stop existing consumers, manual commands,
proxy-exempt callbacks, accepted GPU processing or external FIB billing.

## 7. Final quiescent backup — [AWS RDS] [ONE APP NODE ONLY]

**PRECONDITION:** all section 6 writers stopped; no concurrent DDL; DBA verified
writer/schema and restore permissions. Snapshot/dump destinations are private.
**COMMAND / ACTION:** create a **named manual RDS snapshot** of the verified writer
through the approved AWS console/automation procedure; record identifier/time and
wait until snapshot status is successful/available. `OPERATOR MUST INSERT VERIFIED COMMAND`
if using automation; no RDS identifier is supplied by this repository.
**EXPECTED RESULT:** completed snapshot plus reviewed restore-to-new-instance steps,
endpoint/config switch, KMS/network access, measured recovery time and restore evidence.
**STOP IF:** pending/failed snapshot, wrong target or unproven restore.

**PRECONDITION:** `$MYSQL_BACKUP_OPTIONS` is an existing protected MySQL option file
with approved host/user/TLS settings; `$MYSQL_DATABASE` matches Laravel's verified
schema; `$BACKUP_SQL` is a new private backup path. DBA has approved client/server
compatibility, privileges and GTID handling. Do not pass passwords on the command line.
**COMMAND — backup artifact write only:**

```sh
mysqldump --defaults-extra-file="$MYSQL_BACKUP_OPTIONS" --single-transaction --routines --triggers --events --hex-blob --no-tablespaces --set-gtid-purged=OFF --databases "$MYSQL_DATABASE" --result-file="$BACKUP_SQL"
```

**EXPECTED RESULT:** exit 0, nonempty logical backup, safely stored with snapshot and
old release/config references; successful rehearsal restore on an approved isolated target.
**STOP IF:** warnings/errors imply missing objects, unexpected engine, insufficient
privilege, changed target, failed checksum or no restore evidence.

**READ-ONLY backup verification:**

```sh
test -s "$BACKUP_SQL"
sha256sum "$BACKUP_SQL"
```

Record checksum in protected release evidence. **No production DB migration or
cutover begins until both backup forms and restore evidence are accepted.**

## 8. Deploy code/config/assets — [VM2 — BOTH NODES] [VM3 — BOTH NODES]

Execute the following independently on **both** nodes, in the operator-confirmed
release root. This is not permission to replace a live checkout before maintenance.

**PRECONDITION:** maintenance/edge quarantine, stopped writers, quiescent backups;
approved immutable ref and clean checkout. Protected production config/shared
storage already attached through the verified deployment mechanism. Preserve file
maintenance state across any release switch.
**COMMAND — filesystem writes:**

```sh
git fetch --tags --prune origin
git checkout --detach <RELEASE_TAG_OR_SHA>
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
npm ci
npm run build
```

**EXPECTED RESULT:** locked dependencies installed, successful build, same SHA and
matching assets on VM2/VM3. Composer package discovery is a build step, not a migration.
An approved immutable prebuilt artifact may supply these outputs instead; verify its
manifest/digests. Do not share precompiled config caches between environments.
**STOP IF:** dirty tree, dependency/platform/build failure, SHA mismatch, missing
manifest, production credentials would be embedded in frontend output, or maintenance lost.

**READ-ONLY checks:**

```sh
git rev-parse HEAD
git status --porcelain
test -s public/build/manifest.json
test ! -f public/hot
test ! -f public/sitemap.xml
test ! -f public/llms.txt
```

Check every manifest asset exists. A stale `public/hot` redirects assets to a dev
server. Old physical sitemap/llms files shadow Laravel dynamic routes: if any check
fails, stop and correct the release packaging through the reviewed mechanism; do not
delete unrelated public/customer assets. Do not run `composer setup`, `migrate:fresh`,
`migrate:refresh`, `migrate:reset`, `db:seed`, `migrate --seed`, `passport:install`,
`key:generate` or blanket vendor migration publication.

**PRECONDITION:** protected production values reviewed on both nodes, APP_KEY retained,
both apps/new external surfaces held at the approved maintenance state. Cutover remains
disabled until section 13; destructive customer storage remains false.
**COMMAND / ACTION — configuration write:** `OPERATOR MUST INSERT VERIFIED COMMAND`
to install/update the protected configuration and attach persistent paths. Initial
candidate gates can be `FEATURE_APP_V1=false`, `FEATURE_APP_V2=false`,
`FEATURE_API_V2=false`, `FEATURE_MCP_V2=false` behind quarantine; do not turn off the
live old App before section 6 just to prepare the candidate.

```sh
php artisan config:cache
```

**EXPECTED RESULT:** both CLI processes read the intended production config; repeat
the sanitized inspection from section 3. **STOP IF:** environment/process overrides
win unexpectedly or nodes disagree. Do not call `optimize:clear` or `cache:clear`.
They can clear shared application coordination, and Redis flush is never routine
deployment cleanup. Config/route/view cache compilation does not require flushing
business caches or releasing live locks. Review compiled caches per release.

## 9. Shared migration run — [ONE VM ONLY — SHARED DATABASE OPERATION]

Appendix A inventories **88 source migrations: 65 original-snapshot baseline + 23
later files**. This is not a production pending count. **Actual production
`php artisan migrate:status` wins**, including unknown or partially applied migrations.
The legacy snapshot already contains the original API tables/wallet channels;
the later API V2 idempotency migration extends them. A blank DB is not that snapshot.

**PRECONDITION:** sections 1–8 accepted; nominated node only; production connection
confirmed; no writers or concurrent migration launcher; native MySQL rehearsal and
exact pending source files reviewed.
**COMMAND — database-read/SQL preview, no application writes:**

```sh
php artisan migrate:status
php artisan migrate --pretend
```

**EXPECTED RESULT:** exact ordered pending list/DDL captured privately for review.
**STOP IF:** unexpected pending/unknown names, missing prerequisites, SQL errors,
existing objects with unrecorded migration history, or unreviewed data effects.
Pretend is **not** a complete data preview: pricing normalization, Zeta/Theta and
Harakat explicitly skip pretend; other data-dependent SELECTs may produce no rows
or artificial IDs. Review their source and native rehearsal results too.

**PRECONDITION:** operator/DBA approve that exact pending list/SQL and data impact,
especially launch pricing, OAuth indexed identity widening and Landing correction.
**COMMAND — shared DB write, once only:**

```sh
php artisan migrate --force
```

**EXPECTED RESULT:** every reviewed pending migration commits and is recorded.
**STOP IF:** any failure. MySQL DDL may commit independently; a failed run is not
necessarily rolled back as a unit. Keep both VMs offline, inspect actual schema and
history, and approve a recovery plan; do not blindly rerun, drop tables or seed.

**READ-ONLY verification on nominated node; status check is allowed on VM3:**

```sh
php artisan migrate:status
```

No second `migrate --force` on VM3. No partial-route rollout before the complete
ordered run. Keep MCP migrations even when MCP starts disabled; production cutover
readiness requires the release's entire migration inventory with no unknown rows.

## 10. Admin authority and catalog gate — [ONE APP NODE ONLY]

**READ-ONLY first:**

```sh
php artisan admin:capability-audit 1
php artisan metkurd:diagnose-billing-master-data
php artisan mcp:readiness
```

Verify Admin 1 is the approved **production** existing active operator. Local Admin 1
is not evidence. The capability audit and cutover readiness require all six listed
capabilities, not only finance/reconcile. No capability is provisioned automatically.

**PRECONDITION:** audit proves production Admin 1 lacks a specifically approved full
list; authorized security owner approved replacement and reason; Admin schema exists;
this change precedes the final backup/hash review.
**COMMAND — conditional audited permission write, NOT an ensure step:**

```sh
php artisan admin:capabilities 1 \
  admin.read \
  admin.customers \
  admin.catalog \
  admin.pricing \
  admin.finance \
  admin.reconcile \
  --reason="<APPROVED PRODUCTION REASON>"
```

**EXPECTED RESULT:** exact explicit list replaces the old list and an audit is
recorded; active status is unchanged. Re-run the read-only audit.
**STOP IF:** wrong/inactive account, approval missing or capability result differs.
Do not rerun this just because deployment was retried.

### Current catalog — verify actual data, not labels

| Product | Tool / ToolAction | API route and family scope | Native endpoint / model / mode |
|---|---|---|---|
| Apollo 1.5 | `xomni` / `xomni.generate` | `/api/v2/speech`, model `1.5`; `v2:speech` | `omni_v2`, `model_1`, `builtin_ref` |
| Apollo 2.0 | `xomni-v2` / `xomni-v2.generate` | `/api/v2/speech`, model `2.0`; `v2:speech` | `omni_v2`, `model_2`, `builtin_ref` |
| Vector 1.5 | `clone_xomni` / `clone_xomni.generate` | `/api/v2/voice-clone`, model `1.5`; `v2:voice-clone` | `omni_v2`, `model_1`, `audio_url` |
| Vector 2.0 | `vector-v2` / `vector-v2.generate` | `/api/v2/voice-clone`, model `2.0`; `v2:voice-clone` | `omni_v2`, `model_2`, `audio_url` |
| Zeta 1.0 | `zeta` / `zeta.generate` | `/api/v2/zeta`; `v2:speech` | `omni_v2`, `model_2`, `builtin_ref_batch` |
| Theta 1.0 | `theta` / `theta.generate` | `/api/v2/theta`; `v2:voice-clone` | `omni_v2`, `model_2`, `audio_url_batch` |
| Leo | `leo` / `leo.transcribe` | `/api/v2/transcriptions`; `v2:transcriptions` | `qasr_v2`, ASR fine-tuned |
| Caption | `caption` / `caption.standard` | `/api/v2/captions`; `v2:captions` | `qasr_v2`, caption fine-tuned/SRT |
| OCR Scanner | `ocr` / `ocr.standard` | `/api/v2/ocr`; `v2:ocr` | `kocr_v2`, layout_text |
| Harakat | `harakat` / `harakat.diacritize` | `/api/v2/harakat`; `v2:harakat` | `tashkeel_v1`, source_mode=text |
| STEM 2 | `stem` / `stem.sep2` | `/api/v2/stem`, mode `2`; `v2:stem` | `stem`, two stems |
| STEM 4 | `stem` / `stem.sep4` | `/api/v2/stem`, mode `4`; `v2:stem` | `stem`, four stems |

For **each** row, record active Tool + action, correct binding, approved effective
App and API quote (metric/unit/minimum/rounding), channel entitlements/denies, customer
overrides, and intended Free/Student/Pro/Premium access. Verify voice codes/reference
mapping, available plan voices and character/concurrency limits. API scopes and
API wallet are separate from App entitlement and retained wallet balance.

`mcp:readiness` reports plan configuration, not a customer's current authority or
proof every intended tool is enabled; inspect its rows, not just exit code. Expected
family scope names: `v2:speech`, `v2:voice-clone`, `v2:transcriptions`, `v2:captions`,
`v2:ocr`, `v2:stem`, `v2:harakat`; job/file permissions remain separately checked.
Copied entitlements do not automatically add API scopes. Any required catalog/access
correction needs existing audited Admin controls and approved economics; no seeders,
historical migration replay or ad-hoc SQL. Complete approved corrections before the
final cutover hash. No changes to prices/access are authorized by this document alone.

## 11. RunPod/service gate — [RUNPOD] [BOTH VM2 + VM3]

Only names, never endpoint IDs/keys:

| Production config name | Required acceptance |
|---|---|
| `RUNPOD_ENDPOINT_ID_OMNI_V2` | Apollo/Vector both models and model_2 Zeta/Theta; same endpoint, multiple workers for concurrency |
| `RUNPOD_ENDPOINT_ID_QASR_V2` | Leo/Caption ckb/ar/en, expected text/SRT/segments |
| `RUNPOD_ENDPOINT_ID_KOCR_V2` | Signed input, page selection, compact HTML text source, required DOCX/output envelope and runtime policy |
| `RUNPOD_ENDPOINT_ID_STEM` | 2/4 separation, htdemucs_ft/CUDA and approved model files, signed uploads for original WAV/result JSON/stems, MP3/192k contract |
| `RUNPOD_ENDPOINT_ID_TASHKEEL_V1` | Harakat text-mode envelope; matching job_id, bounded UTF-8 text, positive chunks and final TXT persistence |

Confirm deployed image digest, model/reference mounts, GPU memory, warm cleanup,
concurrency/queue limits, signed URL lifetime, result retention and service acceptance
on the actual endpoint. Legacy endpoint settings may still be needed to finish
retained jobs; they are not substitutes for missing native V2 settings.

Zeta/Theta require **`OMNI_BATCH_ENABLED=true` on the deployed Omni worker**; worker
source defaults false. One Laravel request → one provider job → one model session
→ ordered inference calls → one final WAV. Verify different built-in voices, Theta
job-local repeated-reference reuse, 0/500/1000/2000ms pauses, no trailing pause,
all-or-nothing failure and no accumulating temporary files. Laravel's current envelope
is 25 segments, 500 chars/segment, 5,000 total, 100 MiB reference bound; match deployed
worker limits rather than assuming these certify GPU capacity. Harakat defaults to
5,000 chars via `HARAKAT_MAX_TEXT_CHARS`; align with Tashkeel.

### OCR release blocker — do not advertise untested large-document capacity

**User-reported acceptance failure:** a real **174-page OCR job exceeded the current
request execution timeout**. Source `RunPodV2Adapter::ocr()` hardcodes
`executionTimeout=900000` ms (15 minutes) and `ttl=1200000` ms (20 minutes), separately
from the HTTP transport timeout. Changing an env transport timeout does not change
these request policy values. There is no chunk orchestration supplied by this release.

Before unrestricted public OCR launch, approve and prove **one**:

1. Reviewed source/request policy and endpoint support for increased executionTimeout
   **and** TTL, tested at the advertised page size with correction/export settings.
2. Implemented, tested chunk orchestration with final assembly/billing correctness.
3. Implemented server-enforced temporary page limit, tested across App/API/MCP and
   reflected in public copy. A marketing sentence or operator promise is not a limit.

Also align input URL validity, queue delay, endpoint result retention and Laravel stale
job thresholds with the selected runtime budget. Until then unrestricted OCR is NO-GO;
keep Scanner unavailable through separately approved controls if releasing other services.
Preserve Harakat's separate identity. Do not change inference settings as an unreviewed
deployment workaround. This runbook does not implement any of these alternatives.

## 12. Pre-cutover preflight and provider disposition — [ONE APP NODE ONLY] [FIB]

Use [the batch operator procedure](PROVIDER-OBLIGATION-BATCH-REVIEW.md) for the full
dataset, keeping single-payment approval for exceptions. Apply both additive evidence
migrations under the one-node migration controls. The batch commands reuse the exact
protected identity assertions in section 13: after identity/backup/maintenance review,
configure those assertions for review before this phase. Unresolved provider evidence
still prevents cutover execution; enabling the identity gate is not reset approval.
Export/review on one node with writers quiesced, select bounded actions, preview their
exact worksheet hash, then apply only the separately approved set. Re-export after
each batch. Use --remote-limit=25 for preselected exact objects. Authenticated GET
precedes a committed single-POST fence for ACTIVE/TRIAL and a confirming GET; closed
objects get no POST. DRAFT/failed/ambiguous results remain blocked. The reason
"No Service Available" stays local. Replays retain the exact UUID and never blindly
repeat POST. Merchant imports are exceptional API-unresolved reviews with explicit
per-object evidence. Preserve review rows, paid dispositions and Admin history.
No blanket SQL correction or unreviewed provider action.

Before this phase can pass, complete the separately reviewed
[provider coverage disposition procedure](PROVIDER-OBLIGATION-REVIEW.md#exact-operator-sequence-after-separately-approved-deployment).
The new table must be migrated under this runbook's one-node migration controls. Explicitly
review Payment 161's full interval, preview and execute its approval on ONE node with
all other writers stopped, then fingerprint-check the allowed disposition/operation/
audit append only. Source Payments/events/subscriptions/wallets/ledgers stay unchanged
at this step. Independently resolve DRAFT and remaining evidence blockers. Cutover
activates retained coverage only transactionally; the existing final hash, backup,
maintenance, identity and zero-blocker requirements are unchanged. Do not revert to
code without retained-coverage authority after activation.


After complete migrations/catalog/Admin review, still offline:

```sh
php artisan metkurd:production-preflight --production
php artisan billing:cutover-inventory
php artisan metkurd:diagnose-billing-master-data
php artisan admin:capability-audit 1
```

All required results must pass. `--require-epoch` is reserved for after cutover.
Inventory output contains internal IDs/identity; retain privately. If an existing
epoch is reported, verify its approved provenance and **skip cutover execution**;
never repeat the local rehearsal or production reset.

Review outstanding recurring FIB objects, pending payments, paid/unfulfilled or
refund/review cases, authenticated cancellation evidence, valid paid coverage,
legacy recurring intents, contradictory references and late callbacks/collections.
Production cannot inherit the disposable local-copy policy. A renewal cancellation
does not terminate already-paid access; valid/ambiguous paid-through coverage blocks
this cutover's retirement of provider authority.

The cutover reads persisted provider evidence; it does not call FIB, cancel a
subscription, poll, fulfill, refund or repair anything. A pending payment is not
resolved by deleting it. A retained provider reference without owned verified
evidence remains unresolved. Any unresolved production obligation is **NO-GO**.

Resolve obligations through a **separately approved** finance/provider process using
existing audited lifecycle controls and authenticated evidence. Do not fabricate
cancel-confirmed fields or erase valid coverage to get a green dry-run. If current
source cannot safely represent an approved disposition, stop for a reviewed change.
After any disposition/coverage change, re-quiesce all writers and take fresh backups
or approved supplemental recovery evidence before the final hash. Record provider
retry/late-event strategy; no automatic provider cancellation command belongs here.

## 13. Enable only the cutover procedure — [BOTH VM2 + VM3]

**NEVER run `--target=local-rehearsal` against production. NEVER use
`RESET-V2-BILLING-DOMAIN` as the production confirmation.** Do not spoof APP_ENV,
use loopback tunnels to bypass identity, or disable foreign keys.

**PRECONDITION for cutover execution:** production preflight/identity, native MySQL
rehearsal, provider disposition, backups/restore and approved Admin 1 all accepted.
No prior epoch. The identity configuration below may be installed earlier for the
separately approved section 12 batch review; provider disposition must be complete
before any cutover execute, and all execution guards remain enforced.
**COMMAND / ACTION — protected configuration write:** install these assertions with
the verified secret/config mechanism, then rebuild configuration on both nodes:

```dotenv
BILLING_CUTOVER_ENABLED=true
BILLING_CUTOVER_TARGET=production
BILLING_CUTOVER_EXPECTED_DATABASE=<OPERATOR-CONFIRMED VALUE>
BILLING_CUTOVER_EXPECTED_HOST=<OPERATOR-CONFIRMED VALUE>
BILLING_CUTOVER_EXPECTED_PORT=<OPERATOR-CONFIRMED VALUE>
BILLING_CUTOVER_ADMIN_ID=1
BILLING_CUTOVER_BACKUP_REFERENCE=<OPERATOR-CONFIRMED VALUE>
BILLING_CUTOVER_RESTORE_REFERENCE=<OPERATOR-CONFIRMED VALUE>
```

```sh
php artisan config:cache
```

**EXPECTED RESULT:** configuration asserts the **existing** production connection;
it does not redirect Laravel to another DB. Cutover remains a single-node operation.
**STOP IF:** configured and actual identity disagree or references do not name accepted
backup/restore evidence. An env edit without effective config refresh is insufficient.

### Production review — [ONE APP NODE ONLY], READ-ONLY

```sh
php artisan billing:cutover-reset-payment-domain \
  --target=production \
  --dry-run \
  --admin=1
```

Review identity, migrations, every Admin capability, wallet/ledger fingerprints,
all preservation/nullable-link mappings, effective-plan projection, provider obligations,
delete IDs/counts, blockers and review hash. The five processing tables are
`payment_events`, `payment_webhook_events`, `payment_transactions`, `payments`,
`payment_intents`; deletion ordering is derived safely, not a manual TRUNCATE list.

The operation intentionally removes those payment-processing histories. It retains
CreditOrders and approved historical relationships through reviewed nullable-link
detachments, original ledger/wallet/job/file/usage evidence, local/manual/external
access satisfying current authority, and immutable prior Admin history. Provider
subscription rows are retained but ended locally where reviewed. Retained credits
alone never confer a paid plan. It does not manufacture a new Free allocation.

### Fresh final hash — last action before execution

After migrations, approved Admin/catalog/provider work, backups, config compilation,
maintenance and **all** writers stopped, run the same production dry-run again.
Have the reviewer approve this final private manifest. Use **only that hash**.
Whole-database fingerprints mean session/audit/cache/queue/manual DB activity can
invalidate older reviews. Avoid browser operations between final dry-run and execution.
Changed hash means re-review, not bypass. No `--execute` combined with `--dry-run`.

### Actual production cutover — [ONE VM ONLY — SHARED DATABASE OPERATION]

**PRECONDITION:** final manifest has zero blockers, reviewer signed off exact hash,
all writers stopped, both nodes in maintenance, current backups/restore confirmed,
approved active Admin 1, no existing cutover epoch.
**COMMAND — DESTRUCTIVE FINANCIAL CUTOVER, execute once only:**

```sh
php artisan billing:cutover-reset-payment-domain \
  --target=production \
  --execute \
  --review-hash="<FINAL_PRODUCTION_REVIEW_HASH>" \
  --confirm="RESET-V2-PRODUCTION-BILLING-DOMAIN" \
  --admin=1 \
  --reason="MetKurd V1 to V2 production billing cutover" \
  --workers-stopped \
  --backup-confirmed \
  --restore-confirmed
```

**EXPECTED RESULT:** single transaction commits one audit/epoch, exact expected
fingerprints match, effective plans match review and current revenue/payment totals
start at zero. Prior Admin audit is preserved. Save returned audit ID/boundary privately.
**STOP IF:** any refusal, hash drift, partial/ambiguous terminal output, connection
loss or failed preservation. Inspect whether the epoch committed before any retry;
do not rerun blindly. No simultaneous execution from VM3.

## 14. Immediate post-cutover validation — [ONE APP NODE ONLY]

Before allowing new jobs/orders or starting writers:

```sh
php artisan metkurd:production-preflight --production --require-epoch
php artisan billing:cutover-inventory
```

Verify one `billing.cutover_reset_payment_domain` audit/epoch, the expected five
processing tables cleared, unchanged protected wallets and ledger/history fingerprints,
reviewed CreditOrder/reference preservation, correct effective plans and zero current
revenue boundary. Jobs/files/usage remain continuous. Old retained financial history
belongs under Legacy / Pre-V2, not current revenue. Preserve the cutover audit as
reporting authority; never reset auto-increment watermarks. Take approved post-cutover
evidence before paid smoke tests, since those legitimately create new processing rows.

**PRECONDITION:** cutover outcome established; whether successful or aborted, further
execution is no longer authorized without a new review.
**COMMAND / ACTION — both nodes:** set `BILLING_CUTOVER_ENABLED=false` through the
protected configuration mechanism, then:

```sh
php artisan config:cache
```

**EXPECTED RESULT:** both nodes refuse further cutover attempts. **STOP IF:** effective
config remains enabled or post-cutover validation fails. Keep ingress closed.

## 15. Rollout gates and final caches — [BOTH VM2 + VM3]

Gates are independent. Stage activation behind private ingress for acceptance;
enabling a gate for controlled tests is not public launch approval.

| Stage | FEATURE_APP_V1 | FEATURE_APP_V2 | FEATURE_API_V2 | FEATURE_MCP_V2 |
|---|---|---|---|---|
| Initial candidate under maintenance | false | false | false | false |
| Controlled App acceptance / accepted App release | false | true | false until API acceptance | false |
| Controlled API acceptance / accepted App + API release | false | true | true | false |
| Later explicitly approved MCP acceptance/release | false | true for portal/upload handoff | independently approved | true |

Once tested, public App target is `FEATURE_APP_V1=false`, `FEATURE_APP_V2=true`.
Valid intended destinations still take priority. Disabled V1 GETs map to explicit
V2 equivalents or V2 home; both App gates false fallback is localized landing.
Shared authentication/media/payment callbacks remain protected and available as
designed. V1 retirement does not delete historical jobs or programmatic V1 routes.

**PRECONDITION:** DB/cutover validation accepted, controlled stage approved, same
source/config on both nodes. Only the approved next-stage flags are changed.
**COMMAND / ACTION:** apply protected flag values, then on **VM2 and VM3**:

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**EXPECTED RESULT:** all three commands succeed and application/Livewire pages
actually render with correct locale and authorization. **STOP IF:** cache/Blade
compilation errors, missing assets, routes or node disagreement. `route:list` alone
is not acceptance. If one build fails, keep both nodes quarantined and repair/rebuild
the specific compiled artifact; never flush shared Redis as a remedy.

Reload/restart any long-lived PHP/FPM/application processes using
`OPERATOR MUST INSERT VERIFIED COMMAND` under the same preconditions. Verify they
use the new SHA/config; do not assume a successful CLI cache build refreshes them.

## 16. Queue/scheduler startup gate — [BOTH VM2 + VM3]

Actual current registrations in `routes/console.php`:

| Command | Cadence / condition |
|---|---|
| `ml-jobs:reconcile --limit=200` | Every minute; queues ReconcileMlJob and can mark abandoned unlinked API claims failed |
| `ml-jobs:mark-stale-failed --queued-minutes=30 --processing-minutes=60` | Every ten minutes; writes lifecycle state |
| `billing:process-service-agreements` | Every minute; independent of FIB gate |
| `credits:refill-monthly` | Daily 00:15 application timezone; financial writes |
| `payments:reconcile-fib-cancellations --limit=100` | Every five minutes when FIB_RECONCILIATION_ENABLED |
| `payments:reconcile-fib-payments --chunk=<configured> --stale-minutes=<configured>` | Every five minutes under same gate; defaults 100 / 5 |
| `subscriptions:reconcile --chunk=<configured> --stale-minutes=<configured> --grace-minutes=<configured>` | Every ten minutes under same gate; defaults 100 / 5 / 0 |
| `youtube:cleanup-expired --limit=200` | Every ten minutes, **not gated by App V1** |
| `api:cleanup-expired-files --limit=200` | Every ten minutes; temporary-file deletion attempts |

All use named overlap locks; `onOneServer()` applies only with a recognized shared
cache driver. Shared Redis/prefixes are essential if both scheduler launchers run.
Do not start duplicate launchers blindly. Source defines no dedicated queue names
for the current ReconcileMlJob/payment jobs: inspect effective queue connection and
any additional deployed consumers/legacy jobs; preserve queue contents and retry identity.

**Important storage exception:** `YoutubeOutputStorage::deleteStoredPath()` directly
deletes stored objects; its scheduled legacy cleanup does **not** consult
`STORAGE_ALLOW_DESTRUCTIVE_OPERATIONS`. Before starting the normal scheduler, review
existing/future expiring YouTube records and target bucket ownership. Require explicit
retention approval, or a separately reviewed mechanism that prevents that task from
running during acceptance. Source has no env switch to selectively disable it. Do
not claim the customer-file flag prevents all deletion. If safe startup cannot be
established, scheduler startup and release are **NO-GO** until resolved. API cleanup
uses StorageFileDeletionService and may remain blocked by the false destructive gate;
monitor this intentionally held cleanup rather than silently enabling deletion.

**PRECONDITION:** migration/cutover/config caches accepted; new release loaded;
retention exception resolved; FIB intake/reconciliation strategy approved; shared
locks/timeouts/queue backlog reviewed. Final cutover hash is no longer being used.
**COMMAND — process writes:** `OPERATOR MUST INSERT VERIFIED COMMAND` to start the
actual approved queue consumers/scheduler topology and inspect their status.
**EXPECTED RESULT:** correct processes/revision, no duplicate consumers, retained
queue IDs respected. **STOP IF:** crashes, wrong connection, unexpectedly replayed
submissions, lock loss or unauthorized cleanup/provider work.

Maintenance normally prevents scheduler tasks and ordinary queue workers from
processing. A running process while down is **not** lifecycle acceptance. For private
smoke tests under maintenance, authorize an explicitly bounded maintenance-mode
test consumer through the verified manager (`--force` only if reviewed), and run the
following existing reconciliation entry point only after the above startup gate:

**PRECONDITION:** controlled test jobs approved, existing due backlog reviewed (the
command is not scoped to one smoke job), all post-cutover checks saved.
**COMMAND — DB/queue writes, no blind retry:**

```sh
php artisan ml-jobs:reconcile --limit=200
```

**EXPECTED RESULT:** due existing jobs reconcile/persist through the approved worker;
API reservations settle once. **STOP IF:** unrelated unsafe backlog, ambiguous provider
outcome or duplicate debit. Do not manually run monthly credits/payment commands as
read-only probes. Real scheduled heartbeat acceptance occurs after `up` while public
edge quarantine is still held (section 18); then retire any temporary test consumer.

## 17. Controlled smoke tests — [BOTH VM2 + VM3] [RUNPOD] [FIB]

**PRECONDITION for every write/paid smoke operation:** sections 1–16 accepted,
private ingress and authorized maintenance-bypass access work on both nodes,
approved existing customer/plan/API wallet and explicit spend/retention budget;
no production fake payments or arbitrary wallet edits. Scope activation is controlled
by section 15. API requests under maintenance need the private framework bypass
cookie through an approved client; never disable auth/CSRF globally to test.

**COMMAND / ACTION:** perform the checklist below through real application/client
flows. Use current generated developer-portal payloads and discovered voice/reference
IDs; do not paste credentials or result URLs into this document.
**EXPECTED RESULT:** owned persisted final result, correct wallet/action/price, no
duplicate charge on documented same-intent replay, safe failures and node interchange.
**STOP IF:** private data leaks, wrong customer/channel/price, ambiguous paid submission,
missing persistence, duplicate debit, unsafe OCR size or failed authentication.
Do not blindly resubmit a job whose provider acceptance is unknown.

| Surface | Required PASS evidence |
|---|---|
| Auth | Customer `/app/signin` and Admin `/adm/signin`: valid challenge/login; missing/invalid/expired/provider-failed challenge denied; wrong password denied; CSRF/throttling/session regeneration retained. V2 default redirect, valid intended link, V1 disabled GET/stale Livewire action behavior. |
| Account | V2 Profile, Billing, Plans, Storage and add-ons render; effective plan/allowances match post-cutover authority; App/API balances separate; no coupon input newly exposed. Test actual V2 destination, not only old redirect assertions. |
| Apollo 1.5 / 2 | One short ckb text per model with discovered built-in voice; final private WAV, correct history, preview/download after completion without repeated GPU polling. |
| Vector 1.5 / 2 | Owned authorized reference per model; valid transcript/language; one persisted result and correct ownership/storage. |
| Zeta / Theta | Ordered different voices/references; repeated Theta reference; 0/500/1000/2000ms pauses and no trailing pause; one final audio, one job/reservation; controlled failure cannot succeed partially. |
| Leo / Caption | Small owned audio, language, text/TXT and Caption SRT; measured duration and correct billable units. |
| OCR | Small image/PDF, All Pages and selected pages, TXT/DOCX; correct progress and new-input editor reset; approved maximum safe page-size test. Section 11 blocker must be resolved before unrestricted availability. |
| Harakat | Arabic/mixed UTF-8 text, diacritized output, bounded characters, owned TXT/history; no unsupported file-mode promise. |
| STEM 2 / 4 | Correct tracks and original audio, individual playback/seek/Range, authenticated stream/download and ZIP; private presigned upload targets not returned to another customer. |
| Process Queue | Submit then navigate away/Back/Forward/locale change; background completion visible globally; Saving is not Ready; hidden-tab pause; terminal color/acknowledgment; browser closed still reaches persisted completion via workers. |
| Storage | Owned preview/download, quota and active/unexpired metadata; other-customer/expired requests denied. Check private bucket access and signed URL expiry without recording URL values. Do not enable destructive operations to satisfy a delete button test. |
| Admin | Dashboard/current vs Legacy, customer detail, plan/pricing/access, payment review and Developer job/reservation/file/audit chain. No private payload/token exposure. Disposable-Admin stale-permission test on an explicitly approved account; never revoke the main operator casually. |
| API V2 | Services + voices discovery; one approved paid submission → job → authenticated download. API wallet reservation/settlement, idempotency, denied scope/ownership and expired file negatives. Exercise a populated API chain across nodes; a green plan-readiness report is insufficient. |
| Locales/devices | EN LTR; AR/KU RTL and technical terms; desktop/tablet/mobile, keyboard navigation/modal actions and no page overflow. |

### FIB/callback acceptance — [FIB] [CLOUDFLARE]

Review effective payment and subscription credentials independently; profile-specific
variables can override legacy FIB variables. Ensure `PAYMENTS_FAKE_ENABLED=false`
and intended real payment methods only. Registered callbacks are POST
`https://metkurd.ai/payments/webhooks/fib` and
`https://metkurd.ai/payments/webhooks/fib/subscription`. Compatibility Areeba path is
`/payments/webhooks/areeba`; do not enable it without its own acceptance.

Verify actual reachability/delivery, TLS/proxy host/scheme, route CSRF exclusion and
300/minute per-IP throttle through the deployed middleware, with provider agreement
on retry handling. Do not disable CSRF application-wide. FIB notifications are not
payment proof: authenticated status evidence and idempotent fulfillment remain required.
`FIB_CALLBACK_SECRET` is optional local delivery filtering, not a guaranteed FIB
signature; configuring a header the provider cannot send blocks callbacks.

While Laravel is down, verify configuration and the deliberate maintenance response,
not successful real callback application. FIB does not receive an operator's browser
bypass cookie. Exercise actual approved provider delivery after both nodes are up in
section 18, while the edge still blocks general customer intake and permits only the
reviewed provider/test paths. If the edge cannot support that isolation, stop and
agree an alternative controlled acceptance window; do not fake a callback PASS.

Any real checkout/renewal/cancel/refund acceptance needs separately approved customer,
amount and provider procedure. Verify initial purchase, replay, renewal allocation,
expiry/cancellation and late collection/review behavior using existing authority;
local synthetic Payment 176 is not revenue and its reference must never be polled.
Never fabricate provider success or invoke an automatic cancellation from this runbook.

## 18. Restore service, then open traffic — [POST-DEPLOY] [BOTH VM2 + VM3]

**PRECONDITION:** required controlled App/API/storage/billing/worker smoke tests PASS,
MCP disabled unless separately accepted, cutover disabled, remaining blockers resolved
or explicitly scoped out via approved server-enforced availability controls. Public
edge quarantine remains closed for the final ordinary scheduler/worker and actual
provider callback checks, which cannot be certified while Laravel maintenance blocks them.
**COMMAND — [VM2 — BOTH NODES], then [VM3 — BOTH NODES]:**

```sh
php artisan up
```

**EXPECTED RESULT:** both nodes out of Laravel maintenance; approved testers can use
ordinary application requests while public intake remains held at the edge.
**STOP IF:** one node remains down, health/rendering differs or background processing
fails. Do not leave a mixed up/down deployment or open the load balancer prematurely.

Verify real scheduled executions/heartbeats and queue consumption over the relevant
intervals, not merely `schedule:list`; inspect persisted result and reservation state,
safe logs, failure queue and shared lock behavior. For a daily task use reviewed
prior rehearsal/normal scheduled evidence, not an unapproved manual refill. Remove
temporary forced test consumers through the verified manager. Confirm callback intake
restoration and replay handling with FIB before allowing new checkouts.

**READ-ONLY discovery checks, through protected test access first and again publicly:**

```sh
curl --fail --silent --show-error https://metkurd.ai/sitemap.xml
curl --fail --silent --show-error https://metkurd.ai/llms.txt
```

Dynamic Laravel responses must use production APP_URL, no localhost URLs, current
public families `tts`, `ctts`, `asr`, `ocr`, `stem`, no current Translation/Delta/NEO/
Apollo 1.0/Vector 1.0 promotion, and no API/MCP/auth/Admin machine routes in sitemap.
Check each node via approved LB targeting without disabling TLS verification. MCP
FAQ/FAQPage/AEO must be absent while FEATURE_MCP_V2=false, and match visible copy
when later enabled. Check Cloudflare cache behavior and stale discovery responses.

**PRECONDITION:** both nodes healthy at identical SHA/config; ordinary background
execution, public discovery, provider intake and every required PASS item signed off.
**COMMAND / ACTION — public ingress write:** `OPERATOR MUST INSERT VERIFIED COMMAND`
to release LB/Cloudflare quarantine to both healthy nodes and remove private acceptance
exceptions/cookies as appropriate.
**EXPECTED RESULT:** public traffic reaches both accepted nodes; observe real request
errors, failed/saving jobs, reserved credits, callbacks, storage and billing epoch totals.
**STOP IF:** any material regression; re-quarantine both nodes and follow section 20.

## 19. Later MCP production acceptance — [BOTH VM2 + VM3] [ONE APP NODE ONLY]

Keep `FEATURE_MCP_V2=false` for initial App/API launch unless this separate phase is
approved. Six MCP/OAuth migrations were already included in the one shared migration
run. Do not rerun/package-publish them or run `passport:install`. Passport routes are
registered through McpServiceProvider with automatic routes disabled.

Prerequisites: shared stable APP_KEY; same protected Passport signing keys on both
nodes; `MCP_PUBLIC_URL=https://metkurd.ai/mcp`,
`MCP_OAUTH_ISSUER=https://metkurd.ai`; exact reviewed comma-separated
`MCP_ALLOWED_ORIGINS` (no wildcard); `MCP_SESSION_STORE=redis`; shared sessions/cache,
HTTPS, trusted proxies, native MySQL indexed CIMD identity acceptance and two-node
Redis tests. Default access TTL 10 minutes/refresh 30 days; keep approved settings.
Validate cURL public DNS/HTTPS metadata discovery and bounded resolver timeouts.

Use existing protected `MCP_OAUTH_PRIVATE_KEY`/`MCP_OAUTH_PUBLIC_KEY` PEM values or
Passport key files; never generate a separate key pair on each VM.

**PRECONDITION:** signing keys truly do not exist, no grants depend on another pair,
security owner authorizes generation and secure distribution. Otherwise skip creation.
**COMMAND — [ONE APP NODE ONLY], key-file write:**

```sh
php artisan passport:keys
```

**EXPECTED RESULT:** one protected pair under storage, securely distributed/readable
by the approved PHP processes on both nodes (or approved secret-manager PEM injection).
**STOP IF:** existing keys, unexpected destination/permissions or node mismatch.
Never use `--force`, never regenerate APP_KEY, never expose private keys.

**READ-ONLY plan review:**

```sh
php artisan mcp:readiness
```

Require intended Student/Pro/Premium capabilities, current active eligible **non-Free
effective plan**, tool scopes, API action entitlement/pricing and sufficient API wallet.
Valid bounded Admin/manual/cash grants follow current effective-plan business policy;
do not reintroduce an online-payment-only rule or treat a retained balance as a plan.
Customer-specific overrides and runtime eligibility remain separate from this report.

CIMD and preregistered public clients are supported; DCR is not promised. Confirm each
client's remote authenticated MCP/PKCE/resource support individually; no blanket promise
for every ChatGPT, Claude or Codex plan/configuration. If CIMD cannot be used, register
only an operator-reviewed exact callback with the existing command:

**PRECONDITION:** specific public client identity, callback, web/native policy and scopes
reviewed; registration approved. **COMMAND — shared OAuth client DB write, optional:**

```sh
php artisan mcp:register-client "<REVIEWED CLIENT NAME>" "<EXACT REVIEWED CALLBACK URI>"
```

**EXPECTED RESULT:** reviewed public client ID recorded, no client secret. For approved
native fallback use the source-supported `--native` option and exact loopback policy.
**STOP IF:** callback/client configuration differs, or confidential-client authentication
is required but unsupported. Do not invent a callback URI or broaden origin rules.

**PRECONDITION:** all prerequisites accepted and private controlled MCP tests approved.
**COMMAND / ACTION — both nodes:** set `FEATURE_MCP_V2=true` through protected config;
follow section 15 config compilation/process refresh. Keep public MCP exposure under
the approved acceptance control until tests pass.
**EXPECTED RESULT:** discovery/resource/issuer agree; real clients authenticate with
state, PKCE S256 and explicit consent across nodes. **STOP IF:** any authority, key,
origin, redirect, revocation, session or client acceptance failure; set flag false and
rebuild config through the same reviewed sequence, retaining schema/history.

Test **Codex, Claude Code, Claude web/Desktop and ChatGPT individually**: OAuth,
list_services → list_voices → one controlled `speak` → get_job → final authenticated
audio; then create_upload_session → owned browser upload → OCR/ASR/STEM within
accepted bounds. Verify file ownership, insufficient scopes/credits, revocation,
expired tokens, replay and two-node/shared-Redis behavior. Long jobs remain asynchronous
and charge API credits. No localhost callback workaround proves public client support.
MCP machine tools can operate independently of FEATURE_API_V2; App V2 is needed for
portal/browser file handoff. Do not implicitly change other feature gates.

## 20. Rollback decision tree — [BOTH VM2 + VM3] [AWS RDS] [FIB]

1. **Failure before any DB changes:** keep/quarantine both nodes; restore approved old
   release/config/assets through verified release procedure, preserving maintenance and
   APP_KEY. Verify old processes/config and node parity before reopening.
2. **Migration failure, no cutover epoch:** stop. Native MySQL DDL may already persist.
   Inspect schema/history/backups; old-code rollback requires explicit compatibility
   review. Prefer a reviewed forward repair when safe. Do not use blanket down/reset
   commands; OAuth/CIMD and historical catalog down paths are not a financial undo.
3. **Cutover command failure or lost terminal output:** read the audit/epoch and actual
   state with writers stopped. Do not assume either commit or rollback. If committed,
   follow the next case; if not, resolve blockers and obtain a new final review hash
   before any separately approved attempt.
4. **Committed financial cutover, traffic still closed:** reverting code alone is unsafe.
   A full rollback requires coordinated DB restore, matching old release/config on
   both nodes and provider-event/coverage reconciliation for the whole outage window.
   `migrate:rollback` cannot restore deleted payment rows or undo provider events.
5. **New jobs/payments/uploads accepted after cutover:** restoring a pre-cutover DB can
   erase legitimate orders, reservations, credits, jobs and file ownership while FIB
   collections and S3 objects remain. Stop writes, preserve post-cutover evidence and
   obtain a finance/DBA recovery plan. No automatic restore or repeat GPU/payment request.
6. **Only MCP rollout failed:** disable MCP/config-refresh both nodes; retain OAuth/
   connection history and existing keys. App/API may remain available only if independently
   accepted. Revocation/security response is separately audited, not table deletion.

**PRECONDITION for any restore/release reversal:** incident owner/DBA/finance approval,
both nodes quarantined and writers stopped, exact restore point plus retained later
provider/object evidence, tested compatibility and replay plan.
**COMMAND / ACTION:** `OPERATOR MUST INSERT VERIFIED COMMAND` for RDS restore to the
approved target, protected endpoint/config switch and matching release/process restoration
on both nodes. No invented RDS identifier, unit name or universal SQL restore command.
**EXPECTED RESULT:** both nodes share one accepted restored DB/release/config; externally
collected money and stored objects reconcile without duplicate fulfillment or data loss.
**STOP IF:** unmatched new events, unknown commit state, missing object/backup evidence,
key mismatch or inability to stop writers. Escalate rather than deleting history.

## 21. Final PASS / NO-GO sign-off — [POST-DEPLOY]

Record operator, reviewer, time, SHA, both node identities and private evidence reference
for every row. Unchecked required rows mean **NO-GO**; documentation readiness is not
production acceptance. MCP rows may be deferred only while its gate remains false.

- [ ] Immutable reviewed release, clean trees, same SHA/assets/config on VM2 and VM3.
- [ ] Native MySQL/RDS version, DDL/index/locking/query-plan acceptance with representative
  data; no substitution of SQLite tests or local MariaDB.
- [ ] Credential-shaped source fallback reviewed/resolved; protected production secrets,
  APP_KEY, Turnstile, TLS/proxies and intended integration profiles verified.
- [ ] Named completed RDS snapshot, logical dump/checksum, tested restore and S3/old
  release/protected configuration preservation accepted before DB writes.
- [ ] Both-node maintenance/edge quarantine and all-writer stop evidence recorded.
- [ ] Exact pending inventory/SQL/data effects approved; one migration run completed;
  no pending/unknown migration rows, no seeders.
- [ ] Admin 1 identity/authority verified; any replacement grant explicitly approved/audited.
- [ ] Twelve-product catalog, effective App/API prices, limits, entitlements, scopes and
  voices approved; no generic migration replay repairs.
- [ ] FIB obligations and paid coverage disposition accepted; no unresolved remote
  subscriptions/pending payments or fabricated cancellation evidence.
- [ ] Fresh production dry-run reviewed; one cutover epoch/protected fingerprints correct;
  cutover disabled afterward on both nodes. Existing accepted epoch is never reset.
- [ ] OCR 174-page/runtime-policy blocker resolved with tested policy/chunks/enforced
  page bound, or unavailable Scanner explicitly scoped out before traffic.
- [ ] Worker images/contracts/GPU capacity and private storage/persistence accepted;
  no partial batch success or ambiguous paid retries.
- [ ] Scheduler YouTube-retention exception resolved; destruction flag kept false as
  required; shared Redis locks/session/cache and actual process heartbeats verified.
- [ ] Real populated App/API job → reservation → final owned result chain across nodes;
  Process Queue completion without browser; remaining Admin production browser chain.
- [ ] Disposable-Admin permission revocation accepted on an explicitly identified account.
- [ ] EN/AR/KU account/services/Admin desktop/mobile/tablet and actual Livewire rendering.
- [ ] Dynamic sitemap/llms served from Laravel; stale physical files/hot marker absent;
  correct production URLs/public families; disabled MCP claims omitted.
- [ ] Final production environment/configuration/process-manager verification completed.
- [ ] Both nodes up and healthy before edge opening; callback delivery/replay and monitoring
  assigned; rollback owner ready. No one-node-only maintenance state.
- [ ] MCP remains false initially, or separate keys/origins/paid-account/client/two-node
  acceptance complete and explicitly approved for activation.

## Appendix A. Exact migration inventory from source

All names below are under `database/migrations/`. Source review found **88 files**.
The documented original `eu-metkurd-v1-260906.sql` baseline has 65 migration records,
ending with channel separation; the already-mutated local R1 copy had a different
inventory. The **21-file delta below is relative to that original snapshot only**.
Every production pending file, including any unexpected baseline gap, must be reviewed.
Unknown/missing migration history blocks cutover; never insert history rows to fake readiness.

“Additive” describes intent, **not** zero-lock/zero-downtime safety on RDS. All DDL
requires native rehearsal. Catalog/data migrations require economic/data review.

| Filename | Purpose / type | Prerequisite | Additive/safety and production note |
|---|---|---|---|
| `2026_08_12_000000_add_submission_lifecycle_to_ml_jobs_table.php` | Schema: submission/endpoint/model/debit/refund identities and timestamps | Existing MlJobs/customers | Additive nullable columns + unique/index builds; verify native DDL and historical nullable uniqueness. |
| `2026_08_15_000000_register_xomni_v2_tool.php` | Catalog/data: Apollo 2 tool/action, grants, initial pricing | Tools/actions/plans/channel pricing | Not insert-only: updateOrInsert can rewrite target metadata/access. Initial placeholder price must be followed by Sep7 normalization; never replay to repair. |
| `2026_08_15_000100_register_vector_v2_tool.php` | Catalog/data: Vector 2 and initial pricing/access | Same baseline | Same updateOrInsert/replay risk; later normalization required. Historical metadata references are not V2 adapter endpoint authority. |
| `2026_08_16_000100_register_leo_v2_tool.php` | Catalog/data: Leo, grants and QASR-derived/fallback price | Baseline pricing/plans; QASR optional fallback | Writes target rows, data-dependent price; keep traffic stopped through normalization. |
| `2026_08_16_000200_register_caption_v2_access.php` | Catalog/data: Caption action/grants and missing-price fallback | Baseline catalog/plans | Existing Tool retained; action/grants updated; price added only if absent. Down retains historical identity. |
| `2026_09_05_000001_add_poll_coordination_to_ml_jobs.php` | Schema: next poll/lease/token/attempts and due index | MlJobs | Additive; required for shared reconciliation before workers restart. |
| `2026_09_06_000001_add_v2_idempotency_to_api_jobs.php` | Schema: nullable customer/idempotency hash unique constraint | Baseline API jobs | Additive; native uniqueness/index acceptance. No new API wallet. |
| `2026_09_06_000002_add_admin_operation_safety.php` | Schema: capabilities/status, operation/audit tables | Users/customers | Adds status only if absent; no automatic privilege grants. Historical audit retained. |
| `2026_09_07_000001_normalize_v2_launch_pricing.php` | Data: approved three-action global channel prices | Matching Apollo2/Vector2/Leo registrations | Intentional overwrite/deactivation of competing global rules; NOT purely additive. App/API rates 20/15, 24/18 per character; Leo 1100/825 per minute (minimum 1100). Scoped overrides retained. Skips pretend; down does not restore old rates. Review actual approved production impact. |
| `2026_09_09_000001_create_subscription_credit_allocations_table.php` | Schema: durable cycle/allocation evidence | Customers/subscriptions/payments | Additive unique/FKs; no historical allocation backfill. Populated down is refused. |
| `2026_09_13_000001_create_service_plan_agreements_table.php` | Schema: dated external agreement/snapshots | Plans/customers/subscriptions/users/Admin operations | Additive, restricted FKs; not receipts/automatic activation. Populated down refused. |
| `2026_09_20_000001_register_multi_speaker_tools.php` | Catalog/data: independent Zeta/Theta rows | Apollo2/Vector2 actions with active pricing | Insert targets/copy all source pricing and plan grants including channels/denies only when action created. Existing target economics retained; no scope/voice/customer-override copy. Skips pretend; down retains identity. |
| `2026_09_21_000001_register_harakat_tool.php` | Catalog/data: Harakat | Apollo2 action + active pricing | Same independent-copy rule; existing action skips copy and must be checked for incomplete config. Skips pretend; retains identity on down. |
| `2026_09_26_000100_create_oauth_auth_codes_table.php` | Schema: Passport auth codes | Default reviewed Passport DB connection | Additive table; UUID client identity initially, widened later. No device grant tables. |
| `2026_09_26_000200_create_oauth_access_tokens_table.php` | Schema: Passport tokens | Same connection | Additive; private token/scopes evidence; do not replay if table already exists. |
| `2026_09_26_000300_create_oauth_refresh_tokens_table.php` | Schema: Passport refresh records | Auth/token schema sequence | Additive; protect replay/rotation history. |
| `2026_09_26_000400_create_oauth_clients_table.php` | Schema: Passport public clients | Same connection | Additive UUID clients; no `passport:install`/automatic client creation. |
| `2026_09_26_000500_create_customer_mcp_connections.php` | Schema: connections/uploads + nullable API job key | Customers/files/API jobs/OAuth sequence | Adds tables and **alters** existing api_key_id nullability; native FK acceptance. Down deliberately leaves nullable API key. |
| `2026_09_26_000600_support_mcp_client_metadata.php` | Schema: CIMD URL identity/scopes/type/hash | Four client-ID columns from previous steps | Widens indexed IDs to 512 ASCII/ascii_bin on MySQL; requires native index/collation/retained-row acceptance. Down never truncates identities. |
| `2026_09_27_120000_retire_translation_landing_publication.php` | Data: inactive Translation landing flag + system audit | Landing pages and Admin audit | Narrow conditional update; preserves editorial/media/timestamps/history. Down cannot republish or erase audit. |
| `2026_09_27_140000_add_service_agreement_concurrency.php` | Schema: nullable agreement concurrent_jobs_limit | Service agreements | Additive; null preserves plan concurrency; populated override down refused. No customer credit/access rewrite. |
| `2026_09_30_000001_create_provider_coverage_dispositions.php` | Schema: immutable reviewed legacy-provider coverage + restricted customer/Admin/operation FKs | Existing subscriptions and Admin operation schema | Additive; no approvals/backfill, HTTP or credits. Historical Payment/event IDs survive processing retirement. Populated rollback refused. Native MySQL JSON/date/transaction acceptance required. |
| `2026_09_30_000002_create_provider_obligation_reviews.php` | Schema: exact GET/merchant review provenance with restricted customer/Admin/operation FKs | Admin operations and provider coverage release | Additive, no backfill/HTTP/financial mutation. Logical Payment/event IDs survive processing retirement; populated rollback refused. Native MySQL JSON/hash/locking acceptance required. |

No separate migration for Process Queue, dynamic discovery, Admin layout, Harakat API
route or V1 retirement flag. Their dependencies are in the inventory above/baseline.
Six MCP/OAuth files are included exactly once. Do not add vendor package migrations
outside the reviewed inventory. **Actual production migrate:status is authoritative.**

### Original 65-file baseline (not a second migration queue)

The following exact names were inventoried from source as the older baseline.
They include original API schema, App/API wallet split and entitlement/pricing channels.
If production unexpectedly reports one pending, stop for its full schema/data review;
do not assume it is harmless or solve the discrepancy with seeding.

```text
0001_01_01_000000_create_users_table.php
0001_01_01_000001_create_cache_table.php
0001_01_01_000002_create_jobs_table.php
2026_03_01_161532_create_customers_table.php
2026_03_01_161554_create_customer_profiles_table.php
2026_03_04_174918_create_profiles_table.php
2026_03_05_103616_create_customer_usages_table.php
2026_03_05_103640_create_service_plans_table.php
2026_03_05_103659_create_storage_plans_table.php
2026_03_05_103700_create_customer_payment_methods_table.php
2026_03_05_103733_create_customer_service_subscriptions_table.php
2026_03_05_103826_create_customer_storage_subscriptions_table.php
2026_03_05_103842_create_tools_table.php
2026_03_05_103857_create_tool_actions_table.php
2026_03_05_103928_create_customer_entitlements_table.php
2026_03_05_104015_create_customer_pricing_rules_table.php
2026_03_05_104033_create_voices_table.php
2026_03_05_104054_create_plan_voice_accesses_table.php
2026_03_05_104126_create_customer_voices_table.php
2026_03_05_104141_create_credit_wallets_table.php
2026_03_05_104203_create_credit_ledgers_table.php
2026_03_05_104259_create_credit_monthly_grants_table.php
2026_03_05_104320_create_credit_products_table.php
2026_03_05_104321_create_payment_intents_table.php
2026_03_05_104322_create_payment_transactions_table.php
2026_03_05_104323_create_payment_webhook_events_table.php
2026_03_05_104324_create_payment_methods_table.php
2026_03_05_104327_create_credit_orders_table.php
2026_03_05_104347_create_customer_files_table.php
2026_03_05_104429_create_ml_jobs_table.php
2026_03_05_104937_create_plan_entitlements_table.php
2026_03_07_140700_create_pricing_rules_table.php
2026_03_07_141000_create_usage_events_table.php
2026_03_28_120000_create_registration_phone_countries_table.php
2026_03_30_090000_create_currencies_table.php
2026_03_30_090100_create_currency_exchange_rates_table.php
2026_03_30_090200_create_country_currency_maps_table.php
2026_04_14_100000_create_landing_tool_pages_table.php
2026_04_14_100100_create_landing_settings_table.php
2026_04_14_100200_create_landing_social_links_table.php
2026_04_14_100300_create_site_meta_settings_table.php
2026_04_14_100400_add_square_image_to_landing_tool_pages_table.php
2026_04_16_100000_create_payments_table.php
2026_04_16_100100_create_payment_events_table.php
2026_04_16_100200_add_payment_id_to_billing_tables.php
2026_04_16_120000_update_fib_fee_config_for_pass_through_pricing.php
2026_04_18_100000_add_concurrent_jobs_limit_to_service_plans_table.php
2026_04_18_112033_create_personal_access_tokens_table.php
2026_04_19_090000_add_fib_subscription_fields_to_payments_and_payment_events.php
2026_04_22_120000_create_coupons_table.php
2026_04_22_120100_create_coupon_redemptions_table.php
2026_04_22_120200_add_coupon_fields_to_billing_tables.php
2026_04_23_000100_add_supported_payment_methods_to_coupons_table.php
2026_04_23_120000_normalize_landing_media_paths_for_shared_disk.php
2026_04_26_000001_add_telegram_register_notification_columns_to_customers_table.php
2026_04_30_120000_add_payment_mode_to_service_and_storage_plans.php
2026_04_30_140000_add_billing_intervals_to_service_and_storage_plans.php
2026_05_08_120000_create_ad_conversion_events_table.php
2026_05_10_120500_add_demo_fields_to_landing_tool_pages_table.php
2026_06_10_140000_add_application_review_fields_to_payments_table.php
2026_06_14_100000_add_public_api_fields_to_service_plans_table.php
2026_06_14_100100_add_public_api_fields_to_customer_files_table.php
2026_06_14_100200_create_public_customer_api_tables.php
2026_06_15_000000_split_credit_wallets_and_add_api_credit_allowances.php
2026_06_15_120000_add_channels_to_pricing_and_entitlements.php
```

## Appendix B. Configuration-name inventory

This is a source-name checklist, **not** instructions to set every option. Existing
approved values remain unless this release explicitly requires review above. Many
names are alternative backend/legacy options. Values and credentials are intentionally
omitted. Dynamic FIB environment suffixes and `$envBool` settings are included. Compare
effective process/config values privately, never a whole-config dump.

| Source file | Environment names (no values) |
|---|---|
| `config/app.php` | `APP_DEBUG`, `APP_ENV`, `APP_FAKER_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_KEY`, `APP_LOCALE`, `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE`, `APP_NAME`, `APP_PREVIOUS_KEYS`, `APP_URL` |
| `config/areeba.php` | `APP_ENV`, `AREEBA_API_KEY`, `AREEBA_API_KEY_{$modeSuffix}`, `AREEBA_BASE_URL`, `AREEBA_BASE_URL_{$modeSuffix}`, `AREEBA_MODE`, `AREEBA_PASSWORD`, `AREEBA_PASSWORD_{$modeSuffix}`, `AREEBA_SCHEDULE_ENABLED`, `AREEBA_USERNAME`, `AREEBA_USERNAME_{$modeSuffix}`, `AREEBA_WEBHOOK_SECRET`, `AREEBA_WEBHOOK_SECRET_HEADER`, `AREEBA_WITH_REGISTER_ENABLED` |
| `config/auth.php` | `AUTH_GUARD`, `AUTH_PASSWORD_BROKER`, `AUTH_PASSWORD_TIMEOUT` |
| `config/billing_cutover.php` | `BILLING_CUTOVER_ADMIN_ID`, `BILLING_CUTOVER_BACKUP_REFERENCE`, `BILLING_CUTOVER_ENABLED`, `BILLING_CUTOVER_EXPECTED_DATABASE`, `BILLING_CUTOVER_EXPECTED_HOST`, `BILLING_CUTOVER_EXPECTED_PORT`, `BILLING_CUTOVER_RESTORE_REFERENCE`, `BILLING_CUTOVER_TARGET` |
| `config/cache.php` | `APP_NAME`, `AWS_ACCESS_KEY_ID`, `AWS_DEFAULT_REGION`, `AWS_SECRET_ACCESS_KEY`, `CACHE_PREFIX`, `CACHE_STORE`, `DB_CACHE_CONNECTION`, `DB_CACHE_LOCK_CONNECTION`, `DB_CACHE_LOCK_TABLE`, `DB_CACHE_TABLE`, `DYNAMODB_CACHE_TABLE`, `DYNAMODB_ENDPOINT`, `MEMCACHED_HOST`, `MEMCACHED_PASSWORD`, `MEMCACHED_PERSISTENT_ID`, `MEMCACHED_PORT`, `MEMCACHED_USERNAME`, `REDIS_CACHE_CONNECTION`, `REDIS_CACHE_LOCK_CONNECTION` |
| `config/customer_api.php` | `CUSTOMER_API_DOWNLOAD_URL_TTL_MINUTES`, `CUSTOMER_API_KEY_PREFIX`, `CUSTOMER_API_MAX_KEYS`, `CUSTOMER_API_TEMP_FILE_TTL_DAYS`, `CUSTOMER_API_V2_AUTH_FAILURES_PER_MINUTE`, `FEATURE_API_V2` |
| `config/customer_app.php` | `FEATURE_APP_V1` |
| `config/database.php` | `APP_NAME`, `DB_CHARSET`, `DB_COLLATION`, `DB_CONNECTION`, `DB_DATABASE`, `DB_ENCRYPT`, `DB_FOREIGN_KEYS`, `DB_HOST`, `DB_PASSWORD`, `DB_PORT`, `DB_SOCKET`, `DB_SSLMODE`, `DB_TRUST_SERVER_CERTIFICATE`, `DB_URL`, `DB_USERNAME`, `MYSQL_ATTR_SSL_CA`, `REDIS_BACKOFF_ALGORITHM`, `REDIS_BACKOFF_BASE`, `REDIS_BACKOFF_CAP`, `REDIS_CACHE_DB`, `REDIS_CLIENT`, `REDIS_CLUSTER`, `REDIS_DB`, `REDIS_HOST`, `REDIS_MAX_RETRIES`, `REDIS_PASSWORD`, `REDIS_PERSISTENT`, `REDIS_PORT`, `REDIS_PREFIX`, `REDIS_URL`, `REDIS_USERNAME` |
| `config/fib.php` | `APP_URL`, `FIB_BASE_URL`, `FIB_BASE_URL_{$envSuffix}`, `FIB_CALLBACK_BASE_URL`, `FIB_CALLBACK_SECRET`, `FIB_CALLBACK_SECRET_HEADER`, `FIB_CLIENT_ID`, `FIB_CLIENT_ID_{$envSuffix}`, `FIB_CLIENT_SECRET`, `FIB_CLIENT_SECRET_{$envSuffix}`, `FIB_DIAGNOSTICS_ENABLED`, `FIB_ENABLED`, `FIB_ENV`, `FIB_HTTP_RETRIES`, `FIB_HTTP_RETRY_SLEEP_MS`, `FIB_HTTP_TIMEOUT`, `FIB_PAYMENTS_PATH`, `FIB_PAYMENT_BASE_URL`, `FIB_PAYMENT_BASE_URL_{$envSuffix}`, `FIB_PAYMENT_CANCEL_PATH`, `FIB_PAYMENT_CATEGORY`, `FIB_PAYMENT_CLIENT_ID`, `FIB_PAYMENT_CLIENT_SECRET`, `FIB_PAYMENT_EXPIRES_IN`, `FIB_PAYMENT_REFUNDABLE_FOR`, `FIB_PAYMENT_REFUND_PATH`, `FIB_PAYMENT_STATUS_PATH`, `FIB_REALM`, `FIB_RECONCILIATION_CHUNK_SIZE`, `FIB_RECONCILIATION_ENABLED`, `FIB_RECONCILIATION_LOCAL_EXPIRY_GRACE_MINUTES`, `FIB_RECONCILIATION_STALE_MINUTES`, `FIB_STATUS_SYNC_DELAY_SECONDS`, `FIB_STATUS_SYNC_MAX_ATTEMPTS`, `FIB_SUBSCRIPTIONS_PATH`, `FIB_SUBSCRIPTION_BASE_URL`, `FIB_SUBSCRIPTION_BASE_URL_{$envSuffix}`, `FIB_SUBSCRIPTION_CANCEL_PATH`, `FIB_SUBSCRIPTION_CLIENT_ID`, `FIB_SUBSCRIPTION_CLIENT_SECRET`, `FIB_SUBSCRIPTION_EXPIRES_IN`, `FIB_SUBSCRIPTION_HOURLY_TESTING_ENABLED`, `FIB_SUBSCRIPTION_INTERVAL_HOURLY`, `FIB_SUBSCRIPTION_INTERVAL_MONTHLY`, `FIB_SUBSCRIPTION_INTERVAL_YEARLY`, `FIB_SUBSCRIPTION_STATUS_PATH`, `FIB_SUBSCRIPTION_TRIAL_PERIOD`, `FIB_TOKEN_PATH`, `FIB_TOKEN_TTL_SECONDS` |
| `config/filesystems.php` | `APP_URL`, `AWS_ACCESS_KEY_ID`, `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `AWS_ENDPOINT`, `AWS_SECRET_ACCESS_KEY`, `AWS_URL`, `AWS_USE_PATH_STYLE_ENDPOINT`, `FILESYSTEM_DISK`, `STORAGE_ALLOW_DESTRUCTIVE_OPERATIONS`, `STORAGE_BULK_DOWNLOAD_MAX_BYTES`, `STORAGE_BULK_DOWNLOAD_MAX_FILES` |
| `config/landing.php` | `AWS_BUCKET`, `LANDING_MEDIA_DISK`, `LANDING_MEDIA_PROXY_MAX_AGE`, `LANDING_MEDIA_URL_STRATEGY` |
| `config/livewire.php` | `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK`, `LIVEWIRE_TEMP_DIRECTORY`, `LIVEWIRE_TEMP_DISK`, `LIVEWIRE_TEMP_UPLOAD_MAX_KB`, `LIVEWIRE_TEMP_UPLOAD_MAX_MINUTES`, `STEM_MAX_UPLOAD_KB` |
| `config/logging.php` | `LOG_CHANNEL`, `LOG_DAILY_DAYS`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_DEPRECATIONS_TRACE`, `LOG_LEVEL`, `LOG_PAPERTRAIL_HANDLER`, `LOG_SLACK_EMOJI`, `LOG_SLACK_USERNAME`, `LOG_SLACK_WEBHOOK_URL`, `LOG_STACK`, `LOG_STDERR_FORMATTER`, `LOG_SYSLOG_FACILITY`, `PAPERTRAIL_PORT`, `PAPERTRAIL_URL` |
| `config/mail.php` | `APP_URL`, `MAIL_EHLO_DOMAIN`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_HOST`, `MAIL_LOG_CHANNEL`, `MAIL_MAILER`, `MAIL_PASSWORD`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_SENDMAIL_PATH`, `MAIL_URL`, `MAIL_USERNAME`, `POSTMARK_MESSAGE_STREAM_ID` |
| `config/mcp.php` | `FEATURE_MCP_V2`, `MCP_ALLOWED_ORIGINS`, `MCP_OAUTH_ISSUER`, `MCP_PUBLIC_URL`, `MCP_REFRESH_TOKEN_TTL`, `MCP_SESSION_STORE`, `MCP_TOKEN_TTL` |
| `config/metkurd_v2.php` | `FEATURE_APP_V2`, `HARAKAT_MAX_TEXT_CHARS`, `METKURD_V2_CAPTION_RENDER_TTL`, `METKURD_V2_CTTS_REFERENCE_TTL`, `METKURD_V2_CTTS_RENDER_TTL`, `METKURD_V2_LEO_RENDER_TTL`, `METKURD_V2_SPEAKER_CATALOG_TTL`, `OCR_PDFINFO_BINARY` |
| `config/mobile_api.php` | `MOBILE_API_DOWNLOAD_URL_TTL_MINUTES`, `MOBILE_API_ONBOARDING_TOKEN_EXPIRATION_MINUTES`, `MOBILE_API_TOKEN_EXPIRATION_DAYS`, `MOBILE_PHONE_OTP_COOLDOWN_SECONDS`, `MOBILE_PHONE_OTP_LOCK_SECONDS`, `MOBILE_PHONE_OTP_MAX_ATTEMPTS`, `MOBILE_PHONE_OTP_TTL_SECONDS` |
| `config/passport.php` | `MCP_OAUTH_PRIVATE_KEY`, `MCP_OAUTH_PUBLIC_KEY` |
| `config/payments.php` | `AREEBA_ENABLED`, `AREEBA_SCHEDULE_ENABLED`, `FIB_ENABLED`, `PAYMENTS_DEFAULT_PROVIDER`, `PAYMENTS_FAKE_ENABLED` |
| `config/queue.php` | `AWS_ACCESS_KEY_ID`, `AWS_DEFAULT_REGION`, `AWS_SECRET_ACCESS_KEY`, `BEANSTALKD_QUEUE`, `BEANSTALKD_QUEUE_HOST`, `BEANSTALKD_QUEUE_RETRY_AFTER`, `DB_CONNECTION`, `DB_QUEUE`, `DB_QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER`, `DB_QUEUE_TABLE`, `QUEUE_CONNECTION`, `QUEUE_FAILED_DRIVER`, `REDIS_QUEUE`, `REDIS_QUEUE_CONNECTION`, `REDIS_QUEUE_RETRY_AFTER`, `SQS_PREFIX`, `SQS_QUEUE`, `SQS_SUFFIX` |
| `config/runpod.php` | `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `AWS_ENDPOINT`, `AWS_URL`, `RUNPOD_API_KEY`, `RUNPOD_BASE_URL`, `RUNPOD_ENDPOINT_ID_FTTS`, `RUNPOD_ENDPOINT_ID_KOCR`, `RUNPOD_ENDPOINT_ID_KOCR_V2`, `RUNPOD_ENDPOINT_ID_OMNI`, `RUNPOD_ENDPOINT_ID_OMNI_V2`, `RUNPOD_ENDPOINT_ID_QASR`, `RUNPOD_ENDPOINT_ID_QASR_V2`, `RUNPOD_ENDPOINT_ID_STEM`, `RUNPOD_ENDPOINT_ID_TASHKEEL_V1`, `RUNPOD_ENDPOINT_ID_TRAN`, `RUNPOD_ENDPOINT_ID_WASR`, `RUNPOD_ENDPOINT_ID_XTTS`, `RUNPOD_TIMEOUT`, `RUNPOD_V2_INPUT_HOSTS`, `RUNPOD_V2_TIMEOUT` |
| `config/sanctum.php` | `SANCTUM_STATEFUL_DOMAINS`, `SANCTUM_TOKEN_PREFIX` |
| `config/services.php` | `APP_ENV`, `AWS_ACCESS_KEY_ID`, `AWS_DEFAULT_REGION`, `AWS_SECRET_ACCESS_KEY`, `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `GITHUB_REDIRECT_URI`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GTM_CONTAINER_ID`, `GTM_DEBUG`, `GTM_ENABLED`, `POSTMARK_API_KEY`, `RESEND_API_KEY`, `RUNPOD_API_KEY`, `RUNPOD_BASE_URL`, `RUNPOD_ENDPOINT_ID_FTTS`, `RUNPOD_ENDPOINT_ID_KOCR`, `RUNPOD_ENDPOINT_ID_QASR`, `RUNPOD_ENDPOINT_ID_STEM`, `RUNPOD_ENDPOINT_ID_TRAN`, `RUNPOD_ENDPOINT_ID_WASR`, `RUNPOD_ENDPOINT_ID_XTTS`, `RUNPOD_TIMEOUT`, `SLACK_BOT_USER_DEFAULT_CHANNEL`, `SLACK_BOT_USER_OAUTH_TOKEN`, `STANDINGTECH_BASE_URL`, `STANDINGTECH_SENDER_ID`, `STANDINGTECH_TOKEN`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `TELEGRAM_GROUP`, `TELEGRAM_GROUP_CHK`, `TELEGRAM_GROUP_CON`, `TELEGRAM_GROUP_PAY`, `TELEGRAM_GROUP_REG`, `TURNSTILE_SECRET_KEY`, `TURNSTILE_SITE_KEY`, `YOUTUBE_COOKIES_BROWSER`, `YOUTUBE_COOKIES_BROWSERS`, `YOUTUBE_COOKIES_BROWSER_PROFILE`, `YOUTUBE_COOKIES_FILE`, `YOUTUBE_DOWNLOAD_URL_TTL_MINUTES`, `YOUTUBE_OUTPUT_DISK`, `YOUTUBE_OUTPUT_PREFIX`, `YOUTUBE_OUTPUT_TTL_MINUTES`, `YOUTUBE_PYTHON_BIN`, `YOUTUBE_PYTHON_BIN_LINUX_AWS_EC2`, `YOUTUBE_PYTHON_BIN_WINDOWS_LOCAL`, `YOUTUBE_PYTHON_TARGET`, `YOUTUBE_TEMP_DIR` |
| `config/session.php` | `APP_NAME`, `SESSION_CONNECTION`, `SESSION_COOKIE`, `SESSION_DOMAIN`, `SESSION_DRIVER`, `SESSION_ENCRYPT`, `SESSION_EXPIRE_ON_CLOSE`, `SESSION_HTTP_ONLY`, `SESSION_LIFETIME`, `SESSION_PARTITIONED_COOKIE`, `SESSION_PATH`, `SESSION_SAME_SITE`, `SESSION_SECURE_COOKIE`, `SESSION_STORE`, `SESSION_TABLE` |
| `bootstrap/app.php` | `TRUSTED_PROXIES` |

`{$envSuffix}` means the source-selected `PRODUCTION` or `STAGING`; `{$modeSuffix}` is the Areeba source-selected mode suffix. Verify profile precedence; do not set staging credentials on production. `config/service_agreements.php` has no env switch: its default concurrency is a source constant. No invented env option can change OCR request executionTimeout/TTL or disable the scheduled YouTube cleanup.


## Appendix C. Source/evidence map and verification scope

Primary source reviewed: every repository migration; `config/*.php`, `bootstrap/app.php`,
`routes/{web,api,mcp,console}.php`, `composer.json`/lock and Vite runtime requirements;
ProductionPreflight, CutoverInventory, DiagnoseBillingMasterData, AuditAdminCapabilities,
SetAdminCapabilities, CutoverResetPaymentDomain; PaymentDomainCutover and Cutover
identity/readiness/provider policies; BillingReportingBoundary; native V2 catalog/adapter,
ReconcileMlJobs/ReconcileMlJob, cleanup commands and storage deletion paths;
McpServiceProvider/config/readiness; Turnstile verification and maintenance entry.

Engineering references: [Architecture](ARCHITECTURE.md), [Infrastructure](INFRASTRUCTURE.md),
[Services](SERVICES.md), [API V2](API-V2.md), [MCP](MCP.md), [Process Queue](PROCESS-QUEUE.md),
[Public Website](PUBLIC-WEBSITE.md), [Maintenance](MAINTENANCE.md),
[Billing Cutover](BILLING-DOMAIN-CUTOVER.md), [Admin Final Consolidation](ADMIN-FINAL-CONSOLIDATION.md),
[Current-state Review](CURRENT-STATE-PRODUCTION-REVIEW.md), [Changelog](CHANGELOG.md)
and [snapshot evidence](PRODUCTION-DB-IMPORT.md). Dated local execution statements,
counts and old commands in those documents are historical, not instructions to repeat.

This documentation-only review performed no application/production commands, migrations,
cutover, Admin provisioning, config changes, deployment, remote provider requests or
database reads. Verification is static source/command-name, migration/config inventory,
local-link and document consistency checking. Production PASS rows above remain unsigned.
