# Infrastructure relationships

## API V2 rollout requirements — 2026-09-06

API V2 adds no GPU/queue/storage architecture. Deploy the API idempotency migration
2026_09_06_000001_add_v2_idempotency_to_api_jobs and the existing poll coordination
migration before enabling FEATURE_API_V2. FEATURE_API_V2 defaults false, independent
of FEATURE_APP_V2. Use shared rate-limit cache and the existing scheduler/queue
workers. Confirm ffprobe and OCR_PDFINFO_BINARY, private object permissions, upload
limits, native endpoints, API-channel pricing/entitlements and plan scopes.
CUSTOMER_API_V2_AUTH_FAILURES_PER_MINUTE configures the failure-only IP bucket
(default 60/minute); authenticated request limits remain plan-controlled and
shared with V1. No live configuration, migrations or rollout were performed here.
See [API-V2.md](API-V2.md) for recovery and acceptance requirements.


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
| Relational database | Customers, wallets/ledgers, subscriptions, tool actions, jobs, file metadata, quotas and payment/API state. `config/database.php`, `database/migrations/`, `app/Models/`. Default source driver is SQLite; MySQL/MariaDB/PostgreSQL/SQL Server connections are also defined. Deployment choice is not proven. |
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
