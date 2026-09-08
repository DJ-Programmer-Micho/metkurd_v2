# Infrastructure relationships

## Current local upgrade scope — 2026-09-07

The operator cancelled R2A's separate Docker environment. Use the existing local
application database for the V2 migration rehearsal; do not replace MariaDB,
create another environment or import another snapshot. The prepared Docker tooling
was removed; no container was created. Normal migration remains operator-controlled
after a local export backup. Status/preview and before evidence are recorded in
[the current import/upgrade task](PRODUCTION-DB-IMPORT.md). Earlier environment
decisions below are historical and no longer instructions for this task.

## R2A environment separation — 2026-09-07

The operator's current decision is to retain Laravel → existing MariaDB for
development, add Laravel → separate native MySQL for release acceptance, and
target Laravel → Amazon RDS for MySQL in production. Do not replace XAMPP/MariaDB
or copy its mutated database as the acceptance baseline. The designated original
snapshot has been located and hash-verified. Operator-provided server-version
evidence is 8.4.8; the prepared Docker image is pinned accordingly, with a separate
loopback port 3307, schema, credentials and volume. No container/import/migration
was executed. Docker Desktop is installed but was stopped. See
[R2A procedure and remaining runtime checks](RELEASE-R2A-MYSQL.md).

## Intended database platform and detected local engine — 2026-09-07

The operator identifies **MySQL for local development and Amazon RDS for MySQL
for production** as the intended platform. Production engine/version has not
been independently queried. A direct local `SELECT VERSION()` through Laravel's
active connection returned `10.4.28-MariaDB` during R2, despite the connection
name and driver being `mysql`. Neither phpMyAdmin nor the driver name establishes
the server engine. R2 native MySQL migration acceptance is stopped until the
operator resolves this mismatch; historical MariaDB observations are not RDS
MySQL acceptance. See [R2 prerequisite evidence](PRODUCTION-DB-IMPORT.md).

## Admin P0 rollout — 2026-09-06

This is a deployment runbook, not evidence of deployment. No application DB
migration or account provisioning was executed during implementation.

For the designated `eu-metkurd-v1-260906.sql` local import, use the
[snapshot-specific migration plan](PRODUCTION-DB-IMPORT.md): eight migrations
are pending and their data migrations supply the new V2 catalog. Do not run the
general development/billing seeders over that baseline. The plan also records
unresolved billing findings. The P0 deletion-guard customer-column defect is now
fixed in source, but native MySQL execution remains unverified; applying schema
alone is not deployment acceptance. The
[routing review](ADMIN-ROUTING-REVIEW.md) separates missing schema from route boot.

1. Deploy the additive migration
   `2026_09_06_000002_add_admin_operation_safety.php` before serving the new Admin
   code. Coordinate application maintenance/release ordering because the new
   code expects operation/audit tables and the capability column. Review the
   usual backup and migration plan for the target environment.
2. Apply migrations using the established deployment procedure. This migration
   adds `users.admin_capabilities`, `admin_operations`, `admin_audit_events`, and
   `users.status` only where absent. It does not infer mutation permissions or
   backfill financial history.
3. Provision existing Admin IDs explicitly through the trusted deployment
   console. The command **replaces** the list; specify every capability to retain:

   ```text
   php artisan admin:capabilities ADMIN_USER_ID admin.finance admin.reconcile --reason="Approved finance and payment reconciliation access"
   ```

   Replace `ADMIN_USER_ID` with the verified numeric ID. Supported capabilities:

   | Capability | Scope |
   | --- | --- |
   | `admin.read` | Read/support; implicit for active Admin accounts |
   | `admin.customers` | Customer access/status and verification support actions |
   | `admin.catalog` | Tool/action, voice, add-on, storage, coupon and method management |
   | `admin.pricing` | Service plans, pricing, entitlements, plan voice access and currency changes |
   | `admin.finance` | Manual grants, plan/credit corrections and revenue correction |
   | `admin.reconcile` | Provider reconciliation/reference correction and eligible invalidation |

   Verified-paid grants require both finance and reconciliation. Use
   `admin.read` alone to revoke mutation capabilities. The command records a
   console-origin audit event and does not activate inactive accounts; deployment
   access controls establish the human actor for this trusted console operation.
4. Verify intended read/denied/allowed behavior, audit writes, and a controlled
   replay scenario on the deployment database engine before operational use.
   Capabilities and active status are refetched at execution, including existing
   Livewire sessions. No feature flag is enabled by this migration or command.

Retain operation/audit rows for financial traceability and replay protection.
An identical pending intent may be retried after resolving its safe failure;
changing the payload requires an explicitly new intent. Provider replacement
references fail closed if fresh responses lack the local merchant reference
needed to establish ownership. Do not bypass this check with an arbitrary ID.
SQL atomicity is not a guarantee for external cancellation/notifications in
existing provider fulfillment; investigate ambiguous provider outcomes before
issuing a new correction. A schema rollback drops replay/audit evidence and
must not be used as routine data cleanup.

## API V2 rollout requirements — 2026-09-06

API V2 adds no GPU/queue/storage architecture. Deploy the API idempotency migration
2026_09_06_000001_add_v2_idempotency_to_api_jobs and the existing poll coordination
migration before enabling FEATURE_API_V2. FEATURE_API_V2 defaults false, independent
of FEATURE_APP_V2. Use shared rate-limit cache and the existing scheduler/queue
workers. Confirm ffprobe and OCR_PDFINFO_BINARY, private object permissions, upload
limits, native endpoints, API-channel pricing/entitlements and plan scopes.
CUSTOMER_API_V2_AUTH_FAILURES_PER_MINUTE configures the failure-only IP bucket
(default 60/minute); authenticated request limits remain plan-controlled and
shared with V1. No production configuration, migrations or rollout were performed.
See [API-V2.md](API-V2.md#activation-runbook) for exact enable/disable steps,
configuration refresh, migrations and read-only activation checks. Localhost is
supported; the API-disabled notice means the machine feature gate is false.

### Local troubleshooting evidence (2026-09-06)

The existing poll-coordination migration was applied to the confirmed local
database after completed-provider jobs could not be synchronized. That is not
evidence of its deployment elsewhere; the API idempotency migration is a separate
requirement. API V2 is still disabled as reported by the user.

A local application process could not find pdfinfo through its normal PATH.
Configuring `OCR_PDFINFO_BINARY` to the existing executable allowed the real probe
to count a generated two-page image-only PDF. Scanned PDFs need no selectable
text for page counting. On Windows or managed services, configure the executable
for the actual web/worker process; a utility available only in an interactive
shell may be unavailable to that process. Keep machine-specific paths out of
committed files and verify deployment dependencies independently.


## Previous core-review scope — 2026-09-06

Infrastructure review is limited to what the seven V2 core services need. Old
API infrastructure and billing/account/subscription modernization are deferred.
That earlier API deferral is superseded by the API V2 phase above.
Shared queues, database, storage and existing job financial safeguards remain
required. Product direction does not establish feature rollout or deployment.


Source-verified responsibilities as of 2026-09-05; no deployment secrets or
runtime values belong here. Actual production hosts, counts, database engine,
cache driver and worker revision are **unverified**.

```text
Browser -> Laravel/Livewire web application -> relational database
                         |                 -> configured cache/session store
                         |                 -> Laravel queue + scheduler
                         |                 -> private S3-compatible object storage
                         -> GPU processing HTTP API
                            -> signed object reads / STEM output writes
```

| Layer | Responsibility and source |
|---|---|
| Web application | Auth, locale, entitlement, pricing, input validation, submission, polling, result ownership, preview/download. `routes/web.php`, `app/Http/`, view-based Livewire pages. |
| Relational database | Customers, wallets/ledgers, subscriptions, tool actions, jobs, file metadata, quotas and payment/API state. `config/database.php`, `database/migrations/`, `app/Models/`. Default source driver is SQLite; MySQL/MariaDB/PostgreSQL/SQL Server connections are also defined. Intended platform is MySQL / Amazon RDS for MySQL (operator-confirmed); actual production engine/version remains independently unverified. The local R2 query detected MariaDB and blocked native MySQL acceptance. |
| Redis (when configured) | Cache/session/queue backend, not customer result authority. `config/database.php` defines default/cache Redis connections and phpredis default client; cache and queue configs select their connection. Do not assert Redis is enabled from its presence in config. |
| Cache/session | `config/cache.php` defaults cache to database, `config/session.php` defaults session to database. Shared state/locks are needed when multiple app nodes serve requests. |
| Laravel queue | Background application jobs, including payment processing and YouTube download orchestration. `config/queue.php` defaults to database, also supports Redis; default retry_after is 10800 seconds for these connections. Match worker timeout to visibility/retry time before deployment changes. Submissions remain synchronous; ReconcileMlJob queues server-side status checks and finalization. |
| Scheduler | `routes/console.php`: GPU completion reconciliation every minute, stale ML cleanup every 10 minutes, daily credit refill, payment/subscription reconciliation and YouTube/API retention cleanup. Shared cache drivers get onOneServer plus overlap guards; file/array stores cannot coordinate across nodes. Running cron/workers are unverified. |
| Object storage | Private customer sources/results, reusable references and registered worker artifacts via `CustomerOutputStorage`; `filesystems.disks.s3` uses AWS_* configuration for an S3-compatible backend. Local/public disks and bundled assets have separate purposes. |
| GPU API | `RunPodProvider` submits input at `/v2/{endpoint}/run`, reads `/status/{job}` and supports root-level policy for OCR. OCR uses the POST cancel operation; no provider submission-idempotency API is assumed. Computation belongs here; durable customer lifecycle belongs to MetKurd. |
| Auxiliary services | Payment adapters under `Services/Payments` and `Domain/Payments`, notifications/auth integrations, media probing and YouTube services. These have separate configuration and failure modes; they are not GPU workers. |

## Naming and configuration boundaries

- `FEATURE_APP_V2` controls the preview route gate; routes stay registered.
- `runpod.endpoints.omni_v2`, `qasr_v2`, `kocr_v2` are separate from V1 endpoint
  keys. STEM uses `stem`; Translation uses `tran`. Endpoint IDs and keys must
  not be copied into documentation. Tool metadata can override legacy selection;
  job endpoint keys preserve native V2 routing in supporting synchronizers.
- `RUNPOD_V2_INPUT_HOSTS` plus configured S3 origins feed the HTTPS input allowlist.
  Verify actual signed-URL host compatibility in the deployment environment.
- `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` / `LIVEWIRE_TEMP_DISK` configure temporary
  uploads; a shared disk is explicitly anticipated for multi-node operation.
- `STORAGE_ALLOW_DESTRUCTIVE_OPERATIONS` is opt-in, false by default, because a
  copied database can reference another environment's objects. Keep DB and disk
  ownership aligned; never enable it just to make a test or UI action succeed.
- The legacy `cloudfront` binding in `AppServiceProvider` points to object-storage
  assets; its name is not proof of an AWS CloudFront deployment.

Review deployment with sanitized evidence: applied migrations, feature flags,
shared cache/locks, scheduler and worker operation, storage ACL/CORS/retention,
worker contract acceptance, network timeout semantics and backup recovery.
None of these live checks was established merely by documenting the repository.

## Hardening rollout requirements

Apply the additive poll-coordination migration before deploying callers using
its columns. Run the Laravel scheduler each minute and queue workers with the
reconciliation timeout (240 seconds) below retry_after. Database row/token claims
coordinate GPU checks even when a cache driver cannot provide distributed locks.
Validate this on the deployed database and multiple workers; isolated SQLite
tests do not prove cross-node locking. Check OCR 100-MB multipart uploads against
Livewire overrides, PHP upload_max_filesize/post_max_size and ingress limits.
No production infrastructure limits, migration status or worker images were
verified by this repository task.

PDF OCR now requires a maintained Poppler pdfinfo executable on each application
server, selectable with OCR_PDFINFO_BINARY. Its process is argument-array invoked
with a 30-second timeout; unavailable/unreadable page counts reject the upload
before debit. No binary was installed on production. Verify PDF probing on the
actual temporary-upload disk. Shared-disk uploads are streamed into a bounded
local temporary file for probing and removed afterward.
