# MetKurd V2 shared submission plan

## Implemented Phase A boundary

`MlJob` remains the one customer job record for V1 and V2. V2 does not have a
second history, wallet, output store, or authorization model. The first native
path is Apollo 1.5v and Apollo 2.0v; Vector, QASR, OCR, and STEM stay on their
V1 workspace fallback until their distinct upload/result contracts are moved.

The implemented layers are deliberately narrow:

```text
V2 Apollo leaf -> OmniSubmissionService -> MlJob + CreditService -> RunPodV2Adapter
                                              |
                                           MlJobRefundService
```

`RunPodV2Adapter` only owns the OMNI/QASR/KOCR wire contracts. `XttsJobSyncService`
continues to own OMNI polling and storage, now preferring `MlJob.endpoint_key`
for native jobs before falling back to the legacy tool endpoint.

## Audit findings

- The canonical active states are `queued`, `running`, and `saving`; current
  terminal states include `done`, `failed`, `deleted`, `deleting`, and
  `delete_failed`. The stale command also recognizes historical `queue` and
  `processing` records.
- `provider_job_id`, tool/action IDs, JSON input/output/error, customer output
  storage, render authorization, and browser polling were already shared by
  V1. Provider IDs were indexed but not unique.
- `JobExecutionLockService` uses expiring `MlJob` rows. It protects tool/file
  concurrency; it is not an idempotency mechanism and does not replace a
  durable submission identity.
- The existing ledger serializes wallet mutations with a wallet-row lock, but
  `credit_ledgers.reference_code` is only indexed. A logical debit can produce
  two ledger rows when subscription and add-on buckets are both spent.
- V1 Apollo and Vector charged before their `MlJob` existed. V1 QASR and
  Caption did the same and their provider-start exception branch did not
  refund. The stale command marks records failed but does not financially
  reconcile them. Provider terminal pollers likewise need a later, per-tool
  refund policy before automatic terminal refunds are enabled globally.

## Additive schema

Migration `2026_08_12_000000_add_submission_lifecycle_to_ml_jobs_table` adds
nullable lifecycle information without changing historical rows:

- `submission_key`, unique per customer;
- `endpoint_key` and `model_key` for the provider contract used by a job;
- unique `charge_reference` and `refund_reference`;
- `submission_attempted_at`, `refunded_at`, and `failure_stage`.

The new unique columns accept multiple `NULL` values, so legacy jobs do not
need a backfill. Job-level unique references are the database-enforced
financial identities. Within a wallet lock, `CreditService` additionally
checks a supplied debit/refund reference before writing ledger rows, which
preserves legitimate split-bucket debit entries while making a repeated
logical operation a no-op.

## Financial lifecycle

For native Apollo, the local transaction creates `MlJob` in `queued` state,
stores a deterministic charge reference, and debits the wallet. It commits
before the RunPod request. A repeated browser/Livewire request with the same
submission key returns the same job and never makes a second provider call.

`MlJobRefundService` locks the job, applies the deterministic
`ml-job:{job-id}:refund` correction through `CreditService`, and persists the
refund reference/time in the same local transaction. Calling it again leaves
the wallet unchanged. V1 Apollo, Vector, QASR, and Caption now create their
job before charging, carry a durable charge reference, and use this refunder
for local lock/provider-start failure paths.

No SQL transaction spans a RunPod HTTP request.

## Provider uncertainty and reconciliation

RunPod has no verified idempotency key in the deployed `run` contract. If the
remote provider accepts a request but its HTTP response is lost, Laravel cannot
prove whether a remote job exists. `submission_attempted_at` makes that state
visible and the native service intentionally does not blindly retry it.
Current start failures are marked `failed` and corrected once; before production
rollout, operators must verify the endpoint's timeout/acceptance semantics and
decide whether response-less network failures should enter a manual
`provider_submission` reconciliation queue instead of an automatic refund.

## Migration order

1. Validate Apollo model_1/model_2 against the V2 OMNI endpoint, including
   audio storage and browser-close polling behavior.
2. Define the V2 worker's secure customer-upload ingestion contract for
   Vector. The provided OMNI V2 payload specifies `ref_audio`, but does not
   establish how a customer-uploaded object becomes an application-owned
   worker-readable reference; keeping Vector on V1 avoids guessing this.
3. Extract QASR/Caption upload and result handling before enabling V2 speech.
4. Add KOCR archive/result handling, then STEM multi-artifact handling.

Cancellation, automatic stale-job refunds, and provider-terminal refunds are
not changed in this phase because V1 currently has inconsistent semantics and
RunPod cancellation support has not been established. They are a separate,
tested reconciliation phase.
