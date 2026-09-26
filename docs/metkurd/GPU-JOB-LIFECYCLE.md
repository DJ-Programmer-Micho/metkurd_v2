# GPU job lifecycle

## Process Queue observation — 2026-09-26

The global V2 queue reads MlJob only. Queued/running/saving remain active even
after an execution lock expires; only local done becomes Ready. Provider success
cannot bypass persistence through this UI. Reconciliation still belongs to the
existing scheduler, queued jobs and workspace synchronizers; no shell read calls
them. See [Process Queue](PROCESS-QUEUE.md) for polling and result navigation.

## Harakat — 2026-09-21

Harakat uses DurableUploadSubmission with a content/channel hash and one character
quote/debit. An attempt marker precedes text-only dispatch to `tashkeel_v1`.
Same-key replay cannot create another job; changed input cannot reuse the key.
HarakatJobSyncService requires success=true, matching identity/mode and valid
text/chunks, then persists private TXT and local output before done. No raw provider
payload/error is retained. JobPollCoordinator handles page/scheduler coordination
and the existing terminal App refund policy. Missing endpoint configuration or
storage failure preserves accepted work for reconciliation; ambiguous dispatch
never automatically replays/refunds. See SERVICES for operator/acceptance details.

## Multi-Speaker project lifecycle — 2026-09-20

Zeta/Theta use one DurableUploadSubmission intent and one RunPodV2Adapter batch
call through `omni_v2`. All segment inference and assembly remain inside that
worker job. Local model/mode are explicit (`model_2` plus builtin_ref_batch or
audio_url_batch). Submission metadata contains one ordered segment list and
owned reference IDs; job-local signed URLs are prepared only for dispatch.
The attempt marker is committed immediately before dispatch, after local URL and
endpoint validation. Known rejection/preparation failure uses the existing durable
refund path; timeout/missing provider ID/unknown acceptance is never replayed or
automatically refunded. Same-key submissions cannot create additional work, and
a changed project/financial context cannot reuse that identity.

ReconcileMlJob and XttsJobSyncService process the new tool codes. Batch completion
requires explicit success=true, matching model/mode and full segment_count, a WAV
mime and valid RIFF/WAVE envelope, followed by successful CustomerOutputStorage
persistence. A partial result or success=false never becomes customer output.
Worker failures retain bounded completed_segments, a whitelisted stage, and the
failed index/ID resolved from the local request. Raw provider text, paths, URLs
and untrusted segment IDs are excluded. Storage failures remain retryable polling
of the same accepted job; they do not create a second generation or premature
success/refund. Terminal polling is inert, and known failure refunds remain
idempotent. Deleting final output does not delete reusable Theta references.

## Admin P2 operational inspection — 2026-09-06

Admin histories read local MlJob and owned-file evidence only. Local lifecycle,
provider evidence, App charges/refunds and API reservations have separate labels.
Provider completion alone is not successful local persistence. Provider terminal
status is not universally retained; the review view detects recorded
output.provider_success where present and otherwise reports missing evidence.
Active age over two hours is a review filter, not an SLA or state transition.
No Admin read triggers polling, finalization, refund, replay or other recovery.

## API V2 lifecycle — 2026-09-06

Paid POST claims a unique customer + hashed Idempotency-Key before dispatch.
A core SubmissionContext reserves API credits and links ApiJob/MlJob atomically.
The same accepted request returns the existing job before probing/uploading again.
A different validated payload/file hash returns 409. Submission waits only for
dispatch acknowledgement, not GPU completion. Browser-free reconciliation uses
the existing ReconcileMlJob and database poll coordination. GET jobs only reads
local state and invokes idempotent API settlement. Known submission failures
release API reservations; unknown dispatch outcomes keep them reserved for review.
The app refunder continues rejecting API jobs. Unlinked API claims older than
15 minutes are failed by ml-jobs:reconcile; SubmissionContext refuses a failed
claim, so a late request cannot charge after cleanup. No automatic paid resubmit
is introduced. Full state/recovery contract: [API-V2.md](API-V2.md).


## Previous core-review scope — 2026-09-06

V2 core lifecycle is the release target: Apollo, Vector, Leo, Caption, OCR and
STEM 2/4. Translation and other legacy synchronization branches may still exist
but are not V2 readiness criteria. Preserve the implemented durable debit/refund
and ambiguity protections; broader billing changes and old API hardening wait.
Browser polling is presentation only; the reusable core owns durable completion.


GPU workers compute; MetKurd owns the persisted result. Source state below reflects
2026-09-05 hardening, not verified production deployment.

## Submission and billing

Routes/components authenticate, validate, check access and price existing
ToolActions. Apollo/Vector keep their established submission services. OCR,
STEM 2/4, Leo, Caption and the existing Translation component now use
`DurableUploadSubmission::begin`: lock customer, look up customer/submission key,
create MlJob and debit the app wallet in the same database transaction. References
are `ml-job:{id}:charge` and `ml-job:{id}:refund`. Split wallet buckets remain
separate ledger entries for one financial operation. API reservations keep their
existing API-wallet settlement; the app refunder rejects API jobs and requires
an actual app debit and failed local state.

Uploads happen after that transaction. The same key returns the existing job
without uploading, charging or submitting again. Livewire retains a locked
submission identity for retries; a new upload/new completed request can establish
a new intent. Execution locks enforce work scopes, not submission idempotency.

The attempt marker is persisted before dispatch. A confirmed HTTP rejection or
local preparation failure records failed/refund_pending before trying a refund.
Failed refunds retain that marker for scheduled recovery. Timeouts, 5xx, missing
response IDs and database failures after dispatch can hide remote acceptance:
they remain queued with provider_submission_unknown and are never automatically
resubmitted or refunded. Customer wording asks the customer not to resubmit.

Unknown submissions without a remote ID and interrupted preparation require an
operator to inspect the durable job and provider records. Do not attach an ID or
correct a charge without proving its ownership and acceptance state. This is a
recovery boundary, not permission to replay an ambiguous paid request. Existing
historical jobs/debits are not retroactively repaired by this code change.

## Server and browser coordination

`ml-jobs:reconcile --limit=200`, scheduled each minute, queues `ReconcileMlJob`
for due queued/running/saving GPU jobs with a remote ID, and pending refunds.
The job selects the existing XTTS, QASR, WASR, OCR, STEM or Translation synchronizer.
API results/reservations are reconciled through CustomerApiJobSyncService as well,
including terminal MlJobs whose API settlement previously failed.

Every shared synchronizer uses `JobPollCoordinator`, including browser/API callers:

- Refetch the database row; terminal jobs and missing remote IDs never query GPU.
- Claim a database token with a five-minute expiry in a short row-locked transaction.
- Share a 10-second interval, increasing to 60 seconds for long-running jobs.
- Release only the owned token; an interrupted process recovers after lease expiry.
- Preserve active state after transport/storage exceptions; a later due check retries.
- Finalizers retain their row locks and recheck active state, preventing revival of
  a deleted/cancelled job while a status request was in flight.

V2 browser polling is five seconds for responsive local state. It does not imply
one GPU request per browser tick. Completed history/preview/download is local
MetKurd state. Scheduler overlap guards are retained; queue workers must actually
run. Reconciliation jobs have timeout 240 seconds and one queue attempt; later
scheduler passes recover unfinished work. Queue retry_after must exceed timeout.

Stale cleanup now excludes jobs with a provider ID, charge reference or attempted
submission. Age alone does not prove remote failure and cannot replace completion
reconciliation. Long-running/unavailable provider jobs still need operational
monitoring; there is no invented terminal outcome from a network timeout.

## Results, failure and cancellation

Terminal provider success is required before persisting text/audio. FAILED,
ERROR, CANCELLED and TIMED_OUT cannot be overridden by a partial output payload;
explicit output success=false/ok=false is failure. Required write false returns
and exceptions prevent done. CustomerFile/quota registration is retry-idempotent.
SQL rollback does not remove objects, so retried registration must stay idempotent.
STEM requires its expected stem tracks; OCR constrains all returned keys to the
owned job namespace and verifies direct export writes.

A known failed charged app job can be corrected idempotently; pending corrections
remain recoverable. Customer-requested cancellation/elimination is excluded from
automatic refund. OCR requests remote cancellation and records cancelled only
when acknowledged; an uncertain response leaves monitoring active and preserves
its input. Translation retains V1's explicitly non-refundable local elimination
semantics. Cancellation transport follows the provider's documented POST cancel
operation; no live cancellation was issued during verification.

Sources: `app/Services/MetKurd/Jobs/*`, shared synchronizers,
`app/Jobs/ReconcileMlJob.php`, `app/Console/Commands/ReconcileMlJobs.php`,
`routes/console.php`, and migration
`2026_09_05_000001_add_poll_coordination_to_ml_jobs.php`.

## Endpoint configuration failures — 2026-09-06

An accepted V2 job with missing endpoint configuration remains active/retryable.
Its explicit endpoint is authoritative; synchronizers do not try a legacy worker
or turn missing local configuration into a refundable GPU failure. Tests restore
configuration and resume the same provider ID without resubmission. Browser polls
are five seconds; OCR has one active polling element. Leo/Caption refresh local
state before returning a completion response.

## Workspace identity follow-up — 2026-09-06

OCR invalidates request-cached current job, presentation, stage, text and history
after submission/synchronization. The status card and its poll therefore refer
to the newly accepted job in the same response; an old completed result cannot
stop polling or label new work as done. New uploads clear terminal editor state
while active jobs remain monitored. STEM similarly invalidates computed state
when switching or reconciling jobs and identifies the active source in its card.
These are presentation-state fixes; durable job/refund/reconciliation contracts
remain unchanged. Local repair of missing poll columns and its verification scope
are recorded in [PRODUCTION-AUDIT.md](PRODUCTION-AUDIT.md).
