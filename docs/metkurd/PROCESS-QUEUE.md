# V2 customer Process Queue — 2026-09-26

The V2 topbar owns `app::v2.components.shared.process-queue`, keyed by customer
and locale. V1 retains its existing `partials.process-slots`. The old component
used cached AppShellData plus execution-lock freshness to decide which jobs were
active; lock expiry or a lockless job older than 15 seconds hid active work. It
then removed its own polling directive at zero jobs. It had no terminal history
or acknowledgement and listened to a different refresh event from newer tools.

## Read boundary

`CustomerProcessQueue` reads persisted MlJob status, never provider status. A
refresh uses one customer-scoped query joining ToolAction only for its full code.
The projection is UUID, status, created/updated/finished timestamps and action;
input/output/error JSON, provider identifiers, files and prices are not hydrated.
JSON predicates exclude API job/wallet markers and distinguish current V2 App
jobs using endpoint/workspace metadata. Product labels and routes come from the
existing V2 catalog's action identities, not translated names.

The list includes queued/running/saving plus done/failed/cancelled from the last
24 hours (updated_at), active first then newest update/UUID. The query fetches at
most 13 rows, returns at most 12, and uses the extra row only for a truncation
notice. This is a bounded activity overview, not complete job history. With more
than 12 active jobs, terminal notifications can be delayed until list space is
available; full history remains in each workspace. Existing customer/status
indexes are retained; no schema change is required. Production MySQL query-plan
and high-volume latency acceptance remain separate from SQLite query-count tests.

Only local `done` maps to Ready: existing synchronizers set it after required
output persistence. Provider completion while local state is saving stays Saving.
The queue cannot call sync, JobPollCoordinator, reconciliation, storage probes,
refunds or financial mutations. Existing scheduler/workspace reconciliation must
continue running; this UI cannot compensate for stopped background workers.

## Browser ownership and acknowledgement

`v2-process-queue.js` registers once with MetKurdV2Navigation. A root morph keeps
the controller. Navigation disposes its timeout/listeners before the history
snapshot; the next shell immediately reads fresh state. A shared in-flight guard
serializes outgoing/incoming queue reads, ignores disposed owners, and coalesces
submission bursts. No additional global navigation handler or Bootstrap instance
is installed. The existing shell owns the dropdown. Renderless reads and a
wire:ignore panel avoid repeatedly morphing the global queue.

- Active: one read 8 seconds after the previous request completes.
- Idle or failed read: 60 seconds; failures retain the last display with a safe notice.
- Hidden: no scheduled reads (an already sent request may finish).
- Visible, new shell, opening the panel or `metkurd:job-submitted`: immediate read.

All eight App submission entry points (the two single Omni forms and batch,
Leo, Caption, OCR, STEM, Harakat) dispatch the same payload-free event after a
submission returns its MlJob. Existing submission payloads/events remain intact.

Yellow takes precedence while any listed job is active; a count still indicates
new terminal results. Otherwise an unacknowledged failure is red, an unacknowledged
success green, or no new result gray. Per-customer sessionStorage retains only
terminal UUID/status/time tokens (bounded to 512) and their visual acknowledgment,
with an in-memory fallback. Opening the dropdown acknowledges notifications;
results arriving while it is open are already seen. Zero active jobs, navigation,
locale changes and removal from the bounded list do not clear a pending notice.
First visit may show unseen recent terminal jobs. This is per-tab visual state,
not durable delivery, cross-device notifications or a database `seen` field.

## Existing results and plans

All twelve current products link to their localized V2 workspace with `queue_job`.
`OpensProcessQueueJob` validates the UUID, customer ownership, App markers,
current action entitlement and exact ToolAction before initial selection. Audio
workspaces select the relevant existing history page; Leo/Caption/OCR/Harakat
reuse their current result/editor; STEM uses its existing selected render and
owned-file availability rules. Stale/deleted or mismatched links never grant
access. Normal workspace preview/download authorization remains authoritative.
History ordering has a UUID tiebreaker for deterministic page selection.

The displayed allowance comes from PlanConcurrencyService at shell mount and
customerPlanUpdated. Polls do not repeatedly resolve billing/plan state. The
queue does not filter existing jobs to that allowance or enforce concurrency;
Free/paid submission policies are unchanged. No worker, endpoint, API V2,
billing, refund, storage ownership or scheduler behavior changes.

## Verification

`ProcessQueueTest.php` covers the one-query projection, bounds, ownership/API
exclusion, all products, expired locks, persisted completion, renderless reads,
translations and exact workspace/history selection. `process-queue.test.mjs`
exercises the actual navigation registry across repeated morph/Back/Forward/locale
remounts, in-flight serialization, visibility, cadence, acknowledgment, failures
and the generic submission event. Existing workspace and concurrency suites are
also required. Interactive desktop/mobile/RTL and real Network-tab acceptance
remain separate: browser control could not initialize in this session (kernel
assets path error). No deployment, application migration or production job was run.

Verification result: 148 selected PHP tests passed across the regression run and
focused rerun (the final affected set was 46 tests / 351 assertions), including
full-page URLs for all twelve products and existing Free/paid concurrency tests.
All 65 frontend tests passed; the seven queue tests were repeated after the compact
mobile badge adjustment. Vite build, scoped PHP syntax/Pint and diff checks passed.
The route tests caught a Livewire trait-mount parameter-unpacking error; selection
now runs explicitly at the end of each workspace's existing mount method.
