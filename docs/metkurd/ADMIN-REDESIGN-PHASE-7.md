# Admin Phase 7 — Developer and ML operations

Date: 2026-09-28. Implemented in source; local acceptance is separate from deployment approval.

## 1. Developer overview

Existing localized `admin.operations` and customer-detail pages now share
`AdminDeveloperWorkspace`. API activity and MCP connections show nine persisted
counts: active processing, active external jobs, MCP-originated jobs, the existing
failed/attention group, active keys, active connection records, and three reservation
states. Counts follow customer/creation dates, deliberately not table search/status.
Attention groups retain the existing P2 predicates; supplemental row warnings are
not a new aggregate health measure. Active connections does not prove token validity,
client eligibility or access. API V2/MCP V2 gates are informational only.

## 2. API keys

Metadata includes customer, name, prefix, scopes, creation, last use and revocation.
Selected columns exclude hashes and key material. Effective displayed revocation
honors `revoked_at`; active/revoked filters agree with it. No revocation or privilege
controls were added.

## 3. MCP connections

The Developer menu and existing section switcher include MCP connections. Names,
public client identity, consent scopes, timestamps and revocation are shown. OAuth
token/code/client-secret tables are not queried. A public HTTPS client identity can
be shown as plain text only without credentials, query or fragment; unsafe values
are suppressed. There is no execute-as-customer action.

## 4. Processing and channel

Rows link MlJob and ApiJob while keeping their states separate. MCP origin comes
from persisted `ApiJob.meta.mcp_connection_id`, never a missing API key. Other
ApiJobs are API. An orphan MlJob with recorded API wallet remains API; ordinary
App jobs remain App. Product/action labels use ApiCatalog; historical actions retain
their action code when outside the current catalog. No legacy records are deleted.
Processing filters include running/saving, completed maps to done, and existing
attention/review groups remain available. Customer/date/service/channel filters
and per-row creation/update/completion details are preserved.

## 5. Reservations

Reserved, held, settled and released amounts are displayed from persisted reservation
evidence. Partial settlement uses recorded final amount and shows the remainder
separately. App charge metadata is labeled separately. MlJob stays processing authority;
ApiCreditReservation stays financial authority. Browsing never settles/releases/refunds.

## 6. Files/results

The projection exposes purpose, MIME, size, retention/storage mode, creation/expiry,
deletion, owned source job and API result identity/kind. Links resolve through direct
source metadata or ApiResultFile, including a result with no CustomerFile source ID.
Counts include active, undeleted, unexpired result-file metadata, not object probes.
Storage presence is explicitly not checked. No object key, filename, path, signed URL
or body is selected. Customer-authenticated downloads are not converted into Admin
impersonation links; this page offers metadata/evidence navigation only.

## 7. Review cases

Safe markers identify uncertain submission, missing remote identity, existing stale
processing threshold (two hours), saved-result evidence needing review, retained
reservation, missing owned MlJob and refund pending. Two hours is a review marker,
not an SLA claim. Each warning directs the operator to linked evidence and existing
authorized support/reconciliation workflows. There are no generic retry/refund buttons.

## 8. Evidence navigation

Typed query parameters (`job`, `apiJob`, `keyId`, `connectionId`) validate customer
ownership before filtering, including empty sections. Links retain customer scope
through keys/connections, API activity, processing, reservations, files, ledger and
audit. Corrupt cross-customer links do not become links to another customer's record.
Audit scope includes supported job/reservation/key/connection targets; absence of an
audit row means no persisted evidence, not proof that an operation never occurred.

## 9. Privacy and authority

No text, transcription, OCR content, voice reference, prompt, MCP argument, raw
provider body, stack trace or credentials are rendered. Narrow projections and the
existing Admin redactor form the boundary. Fresh `AdminAccess::authorize('admin.read')`
remains mandatory. Existing policy gives active Admins read access regardless of the
deeper capability array; this phase does not change that policy. Inactive Admins are
rejected; finance/reconcile controls retain their separate authority.

## 10. Query behavior

Tables paginate at 25. Related metadata is batched, selected and customer checked;
file counts are grouped instead of hydrating files per row. Tests compare one versus
25 rows to detect query-count growth. Nine overview counts are fixed, not per-row.
JSON scalars are projected without loading full input/output/meta. Reads perform no
provider calls, polling, storage probes, reconciliation or financial writes. No index,
schema, provider, wallet or lifecycle changes were made. Native MySQL query plans and
production-volume latency still require acceptance.

## 11. Verification

- Broad Admin regression: 95 tests / 1,499 assertions passed (Developer, P2, shell,
  customer, billing and acceptance suites).
- Final Developer/P2 rerun: 50 tests / 856 assertions passed after the final file-link
  correction. The new Developer suite contains 14 cases including locale datasets.
- All 41 Admin frontend tests passed, including translation key/token parity.
- Vite build passed; focused Pint, PHP syntax and whitespace checks passed.
- Fixtures use SQLite `:memory:`, array cache/session, fake storage and mocked HTTP.
  Assertions cover isolation, origin, linked results, reservation states, revoked
  metadata, secret exclusion, safe client identity, no external calls/writes,
  pagination/query growth, EN/AR/KU and fresh active Admin authority.
- Local real browser: EN desktop overview, existing App job rows and owned job-to-file
  link; two existing revoked MCP connection rows. AR/KU mobile overview renders RTL
  without document horizontal overflow; KU tablet (768×1024) was also visually checked.
  Table regions retain keyboard focus and horizontal overflow. Viewport override was
  reset and the browser was left on the Arabic Developer overview.
  API job activity is empty locally, so populated external-job/reservation chains are
  fixture-tested rather than claimed as live browser acceptance.
- Browser screenshots and local test logs are in ignored
  `storage/app/private/admin-phase7/`; no runtime/customer records are copied into docs.

## 12. Remaining acceptance

Use an approved non-production native MySQL instance matching AWS RDS to inspect
JSON/subquery plans and latency with representative volume. XAMPP MariaDB does not
satisfy that check; production MySQL version is still unspecified. Complete a safe
populated API/MCP chain in the browser, including filters, later pagination, mixed
technical IDs and keyboard table scrolling at EN/AR/KU breakpoints. Phase 6's live
stale-permission test still awaits an identified disposable local Admin account;
the current signed-in account was not altered. No deployment, migration, rollout
toggle, production connection or live financial/provider operation was performed.

## Files in this phase

New: `app/Support/Admin/AdminDeveloperWorkspace.php`, two
`resources/views/components/admin-developer-*.blade.php` components, EN/AR/KU
`admin_developer.php` catalogs, `tests/Feature/Admin/AdminDeveloperWorkspaceTest.php`
and this report. Updated: `AdminOperations`, `ReadsOperations`, `AdminNavigation`,
Operations Blade, scoped Admin CSS, three `admin_p2`/`admin_shell` catalogs,
frontend localization tests and the architecture/audit/changelog documentation.
Earlier dirty work from Phases 1–6 and other tasks was retained.
