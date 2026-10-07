# MetKurd MCP V2

## Descriptive public tool names — 2026-10-07

MCP advertises `metkurd_generate_multi_speaker_speech` for Zeta and
`metkurd_generate_multi_speaker_cloned_speech` for Theta. These replace
`metkurd_zeta` and `metkurd_theta`; no old-name aliases are registered. Clients
must refresh tool discovery after deployment. The catalog still has 14 tools.
Internal `zeta`/`theta` dispatch keys, Tool/ToolAction identities, `/api/v2/zeta`
and `/api/v2/theta`, scopes, schemas, billing and processing remain unchanged.
`metkurd_clone_voice` is unchanged. An OpenAI further-review hold is not itself
evidence of a metadata/schema defect, and this source change does not resolve
or bypass that review or authorize deployment.

Both descriptions identify paid asynchronous generation of one final speech audio
output. Zeta uses public MetKurd voices; Theta uses owned, active, unexpired saved
references. The descriptions expose existing configured limits: currently 1–25
segments, 500 characters per segment and 5,000 total, permitted pauses with no
trailing pause; Theta references are at most 20 MiB each and 100 MiB across distinct
objects, with optional reference text limited to 4,000 characters per segment.

## Production acceptance lessons — 2026-10-05

**CURRENT production acceptance — 2026-10-05 (operator-reported):** ChatGPT
acceptance now includes public OAuth discovery, CIMD OAuth connection, shared
Passport signing keys across both app nodes, corrected Cloudflare machine
ingress, MCP `initialize`, `tools/list`, `metkurd_list_voices`, one controlled
Apollo 2 paid speech generation, API-channel credit consumption, a persisted
completed result, and the OAuth-protected MCP file result.

**Native inline media rendering: SOURCE/LOCAL VERIFIED only.** The new native
`AudioContent` result-delivery patch awaits deployment and real ChatGPT acceptance;
the production evidence above does not establish acceptance of that patch.

The operator reports real production OAuth acceptance after correcting two
deployment prerequisites. With FEATURE_MCP_V2 enabled, both MCP OAuth PEM settings
were empty and `storage/oauth-private.key` / `storage/oauth-public.key` were absent;
the real OAuth flow threw `LogicException`. One Passport pair was generated and
the exact same pair installed on both load-balanced app nodes. Separately,
Cloudflare Free Bot Fight Mode returned HTTP 403, `cf-mitigated: challenge` and
Managed Challenge / "Just a moment" HTML for `/mcp`, preventing machine requests
from reaching the healthy origin. Standard Bot Fight Mode was disabled.

This is operator-reported production evidence, not a production inspection by this
source update. It supersedes earlier statements that real ChatGPT OAuth was
untested; it does not establish acceptance for every paid tool or other client.
The mandatory [signing prerequisites](#mandatory-passport-signing-prerequisites)
and [machine ingress probes](#machine-ingress-and-cloudflare-acceptance) below now
form part of each deployment review. No production operation is authorized here.

## OCR page selection — 2026-10-01

MCP OCR uses the same Laravel InputBoundary/native submission limit as App/API:
1–20 unique requested pages. `all`/omitted pages means the first 20 or fewer when
the document total is known; custom ranges such as 21-40 remain supported. Upload
handoff accepts digital and scanned PDFs. Poppler and the PHP structural fallback
never require extractable text; unknown totals remain unknown. The tool schema
now describes this limit. Existing ownership, API credits and job lifecycle stay
in force; see SERVICES.md for unknown-count acceptance limits.


Source implementation: 2026-09-26. **Disabled by default; no deployment or external
client acceptance was implied by that historical source snapshot.** Current
production evidence is recorded in the October 5 acceptance section above.
This document is the engineering contract for the
remote external-client interface. See [API V2](API-V2.md) for the shared native
submission, financial and result contracts.

## Architecture and dependencies

`mcp/sdk` **0.8.1**, the official PHP SDK, runs in the Laravel application through
PSR-7/PSR-17 bridges. It supports this PHP 8.2 runtime and owns JSON-RPC parsing,
protocol negotiation, tool validation, response envelopes, HTTP transport and
session mechanics. There is no sidecar and no HTTP loopback into `/api/v2`.
`laravel/passport` **13.7.6** / League OAuth2 Server **9.4.1** owns authorization
codes, PKCE, signing, encrypted grant tokens, rotation and replay rejection.
`nyholm/psr7` **1.8.2** supplies PSR messages. Passport 13.8 requires phpseclib 4,
which conflicts with an existing Socialite dependency requiring phpseclib 3;
13.7.6 is pinned without upgrading/removing existing dependencies.

The official SDK client has exercised both **2026-07-28** stateless Streamable
HTTP and **2025-11-25** initialization/session operation against Laravel's HTTP
kernel. Each request builds a fresh server. Durable authority comes from the DB,
not an initialized PHP process. Legacy SDK sessions use shared Redis by default,
expire after one hour and are partitioned by authenticated connection UUID.
Production rejects an array/file MCP session store. No sampling, elicitation,
prompts, resource subscription, background MCP tasks or legacy SSE endpoint is
advertised. GPU work is an ordinary existing asynchronous MetKurd job.

```
OAuth / SDK bearer validation → McpConnectionPrincipal
  → current active non-Free effective plan + granted scopes + current API entitlements
  → Tools → ApiSubmission → SubmissionContext (API)
  → existing native service → API reservation + MlJob → existing worker
  → existing scheduler/persistence → ApiJobResult → protected MCP result
```

REST keeps `ApiKeyPrincipal`, the existing payloads and error behavior.
`ApiSubmission` accepts either principal. `ApiJobResult::payload` retains REST's
local synchronization; `persistedPayload` is the shared pure projection used by
all MCP acknowledgements, replay responses, job tools and job resources. No MlJob,
CustomerFile, wallet, pricing or worker schema/contract was changed.

## Endpoint, discovery and OAuth

| Endpoint | Purpose |
| --- | --- |
| `/mcp` | SDK Streamable HTTP GET/POST/DELETE/OPTIONS |
| `/.well-known/oauth-protected-resource/mcp` | SDK protected resource metadata |
| `/.well-known/oauth-authorization-server` | Authorization server metadata |
| `/oauth/authorize` GET/POST | Existing customer login, explicit consent, browser CSRF |
| `/oauth/token` POST | Public authorization-code / refresh exchange |
| `/mcp/files/{file_id}` GET | Authenticated streamed result download |
| `/{locale}/app-v2/mcp` | Customer documentation and connection management |
| `/{locale}/app-v2/mcp/uploads/{uuid}` GET/POST | Same-customer browser upload |

The public resource must be HTTPS with path `/mcp`; issuer is its HTTPS origin.
There is no locale prefix on the machine endpoints. Bearer endpoints do not use
the browser session or CSRF cookie. OAuth consent uses the existing `app` guard,
active/verified customer middleware, Laravel CSRF, and Passport's session-bound
authorization token. Re-consent revokes older grants for the customer/client.

**Supported: CIMD and operator-preregistered public clients. DCR is deferred.**
Discovery advertises `client_id_metadata_document_supported: true`, `none`,
authorization-code/refresh grants and PKCE S256. The metadata-document HTTPS URL
is the exact OAuth client ID in Passport, authorization codes, connections,
JWT `client_id` and refresh grants. It is never replaced by a generated UUID.
Existing registered UUID clients remain valid. A dedicated additive migration
widens identity columns to 512 ASCII characters with binary comparison on MySQL.
ClientRepository avoids stale Passport `once()` snapshots when metadata changes.

Declared CIMD scopes also bound requested permissions through Passport's client
scope field; absent scope metadata grants nothing beyond current customer consent.
Clients must send exact `resource=MCP_PUBLIC_URL` at authorization and token
exchange and preserve their own anti-CSRF state. S256 is mandatory for every
public client. Consent never grants wildcard or unavailable customer scopes.
We support public `none` authentication only. A singular `private_key_jwt` client
is rejected, never silently downgraded. For the documented SEP-3149 transition,
an explicit `token_endpoint_auth_methods_supported` list may include `none`;
we select only `none`. No shared secrets or embedded JWK material are accepted.

### CIMD discovery boundary

`ClientMetadata` fetches only the client ID document. It requires an ASCII HTTPS
URL with a path, exact document `client_id`, no query, userinfo, fragment or dot
path segments. All A/AAAA answers must be public; special-use, private, mapped,
loopback and link-local addresses fail closed. Validated DNS is pinned to cURL
with TLS verification, proxies disabled, 3-second connect / 5-second HTTP limits,
5 KiB response limit and no redirects, including HTTPS redirects. The deployment
resolver also needs bounded DNS timeouts. cURL is required; no unpinned fallback.
Only HTTP 200, uncompressed `application/json`, valid bounded-depth object JSON
and validated OAuth fields are accepted. Duplicate keys, including escaped and
nested duplicates, fail. Logos, JWKS URLs and other linked content are never fetched.
The consent page displays the actual URL alongside the untrusted client name.
Only authenticated eligible authorization requests discover new clients;
unauthenticated token requests cannot populate the client registry.

Successful validated metadata is cached only with explicit `max-age`, capped at
300 seconds and reduced by Age. No-store, no-cache, private, absent freshness and
errors are not cached. No stale-on-error fallback. Authorization, approval and
token exchange re-resolve metadata through this cache. Changed effective name,
redirects, grants, declared scope or application type revokes existing connections,
codes, access and refresh tokens, and requires fresh consent. A changed document
between consent display and approval aborts approval. Unused metadata is inert.
Existing access tokens do not trigger network fetches: before the next resolution,
their normal expiry bounds access (10 minutes by default). Cache/metadata policy
is part of staging acceptance, not a promise of instantaneous remote revocation.

### Redirect policy

Web clients retain exact public HTTPS callbacks. Native clients are explicitly
registered with `--native`, declare standard `application_type: native`, or have
an exclusively loopback CIMD callback set when application_type is absent.
An explicit `web` type never permits HTTP. Native IPv4 `127.0.0.1` and IPv6 `::1`
callbacks allow an ephemeral port while matching every other component exactly;
League rechecks the original redirect during code exchange. Exact
`http://localhost:PORT/path` is also accepted for native compatibility, with a
fixed matching port, never wildcard/subdomain localhost. RFC 8252 prefers IP
literals; localhost is a compatibility exception it discusses, not a recommended
new registration. HTTP LAN/private/public IPs and arbitrary HTTP domains are
rejected, as are wildcard, userinfo, fragment and backslash callbacks. No callback
is fetched by the server. RFC 9207 response support is not advertised: retain the
client-specific callback suffix supplied by the client.

The current [MCP authorization specification](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization),
[CIMD draft-02](https://datatracker.ietf.org/doc/html/draft-ietf-oauth-client-id-metadata-document-02)
and [RFC 8252](https://www.rfc-editor.org/rfc/rfc8252.html) were reviewed 2026-09-26.
CIMD plus preregistered public clients provides a documented setup route for the
target hosts; none requires adding DCR now. No registration endpoint is advertised.
**Historical snapshot — 2026-09-26:** this review did not establish that any real
external client had connected successfully. See the October 5 production
acceptance section above for subsequent ChatGPT evidence.

Access JWTs use the package's Lcobucci RS256 builder with a standard resource
audience, issuer, subject, client ID, jti, scopes and expiry. The official SDK JWT
validator verifies the signature and audience using the locally held public key;
no remote JWK lookup occurs. Runtime checks also require an unrevoked DB token,
active client and active owned connection. DB tables contain token identifiers,
scopes, expiry and revocation, not plaintext bearer/refresh tokens. League encrypts
the grant/refresh token values sent to the client. Default access lifetime is ten
minutes, refresh lifetime thirty days; limits clamp to 1–30 minutes / 1–90 days.
Client-row locks serialize exchange, refresh, revoke and re-consent across nodes.
Native MySQL lock/parallel refresh acceptance remains required.

## Effective-plan authority, scopes and financial configuration

`CustomerMcpAccessService` starts from `CustomerBillingStateService`, whose
effective subscription scopes use `BillingSubscriptionAuthority`. MCP requires an
active customer and a current active non-Free effective plan with configured API
capability. Plan origin/payment channel is not an additional MCP authorization
condition. Valid Admin manual grants, complimentary non-Free access, internal
accounts, external agreements and future custom plans qualify when the authoritative
resolver recognizes their current access. There is no plan-name allowlist.

The underlying billing authority remains unchanged; MCP consumes its result instead
of independently querying payment provenance. Free remains denied even with carried
API credits. Expired/inactive plans and suspended customers are denied. Every bearer
request, tool, resource and upload rechecks current authority. An access token does
not freeze a previous plan's privileges.

Paid processing still requires current API action entitlement, an applicable price
and sufficient API credits. Discovery does not require a positive credit balance
once plan/capability authorization passes. MCP uses the API wallet; it has no separate
wallet and never falls back to App credits or manufactures payment/credit evidence.

Allowed capabilities are the intersection of token scopes, connection consent,
current plan V2 family scopes and current API action entitlements:
`v2:speech`, `v2:voice-clone`, `v2:transcriptions`, `v2:captions`, `v2:ocr`,
`v2:stem`, `v2:harakat`, `v2:jobs:read`, `v2:files:download`.
`mcp:uploads` is the only added capability; each upload also requires its purpose's
family permission. No separate MCP service scopes, wallet, prices or concurrency
system exists. Missing API configuration fails as `api_access_unavailable`.

**Initial read-only local configuration audit, 2026-09-26 (before operator scope configuration):**

| Plan | API enabled | Monthly API allowance | RPM | Concurrent | Recognized V2 scopes |
| --- | --- | ---: | ---: | ---: | --- |
| Student | Yes | 150,000 | 60 | 2 | None |
| Pro | Yes | 300,000 | 300 | 10 | None |
| Premium | Yes | 800,000 | 1,000 | 50 | None |

These were local DB observations through `configForPlan` and
`scopesForConfiguration`, not production assertions or promises of usable API
access. They differ from an older dated local Pro configuration note. No scopes,
prices, allowances or credits were changed. Before rollout, an authorized Admin
must review V2 scope and API action/pricing configuration using existing audited
controls. Legacy exact V1 scopes are not upgraded automatically. Source tests
configure isolated Student/Pro/Premium fixtures to verify each eligible case.

The focused hardening audit repeated these reads inside a **local read-only DB
transaction**. All 12 V2 action entitlements and active applicable API pricing
rows exist for each of the three plans; **all seven recognized V2 family scopes
were missing**. At that point Student, Pro and Premium were **not MCP-ready**,
despite their allowances. The later local runtime acceptance below supersedes
that plan-configuration finding; it does not establish live OAuth acceptance.
`php artisan mcp:readiness` reports local signing readiness and plan configuration,
never customer identities, wallet balances, PEM contents, credentials or tokens.
`--signing-only` skips plan/database reads. Pricing rows are configuration
evidence, not a guaranteed quote: conditional rule selection, current effective-plan
authority, customer overrides and sufficient API credits remain runtime checks.
The read-only customer-action diagnostic uses the same Customer pricing resolver.
Missing price or insufficient API credits cannot fall back to the App wallet.

Operators configure included tools through existing audited Admin scope,
entitlement and pricing controls. Customers only approve requested permissions;
they should not configure plan scopes themselves. No tier is promised a tool that
its actual current configuration does not grant.

## Tool contract

### Bounded native result delivery — 2026-10-05

**SOURCE/LOCAL VERIFIED; awaiting deployment and real ChatGPT acceptance of
native inline media rendering.** This patch is not yet production-accepted.

Completed job reads and idempotent submission replays prioritize actual customer
content. Apollo/Vector/Zeta/Theta return native MCP `AudioContent` for safe small
WAV/MP3 results, with authoritative MIME and unchanged bytes. Leo and Harakat
return readable text plus structured results. Caption SRT/VTT and suitable OCR
textual exports use embedded text resources. PDF, DOCX and other binary documents
remain protected downloads. No custom MCP App/player is included, and rendering
inline media depends on the host; universal ChatGPT playback is not promised.
Inline file types are WAV/MP3, plain UTF-8 text, SRT and VTT. JSON export/worker
manifest files remain authenticated downloads too; arbitrary manifest contents
are not promoted into model-visible resource bodies. JSON metadata descriptors
contain only the existing safe public fields.

These are **MetKurd application safety limits**, not ChatGPT limits:

| Configuration / environment | Default |
| --- | --- |
| `mcp.inline_file_bytes` / `MCP_INLINE_FILE_BYTES` | 1,048,576 raw bytes per file (1 MiB) |
| `mcp.inline_total_bytes` / `MCP_INLINE_TOTAL_BYTES` | 1,048,576 raw bytes per tool response (1 MiB) |
| `mcp.response_bytes` / `MCP_RESPONSE_BYTES` | 2,097,152 serialized bytes (2 MiB) |

Raw limits may be zero to disable embedding. The serialized budget has a 4 KiB
minimum and reserves 1 KiB for framing. It measures SDK-default JSON escaping,
base64, structured content, links and the actual RPC ID. Readable text duplicated
in structured content is accounted for too. Very small configured response
ceilings also reduce the transport request-body limit so an echoed RPC ID cannot
itself exceed the response budget; default request limits are unchanged. Text
and media exactly at 1 MiB are eligible only when
aggregate and serialized budgets also pass; base64 escaping can push a raw file
that fits over the wire budget. Never truncate, transcode or lower media quality
to fit. Oversized media uses authenticated fallbacks. A pathological oversized
manifest fails with `response_too_large` rather than returning an arbitrary subset.

Every STEM track keeps its label, file ID, MIME and download. All two/four required
stems must be present, authorized, readable and fit the aggregate/serialized
budgets to inline any of them. `result.stem_count` comes from persisted submission
input; never infer the requested set from whichever files remain. Unknown mode
also prevents embedding. Otherwise no stems inline, and the complete
available authorized fallback set is retained. Deleted or missing artifacts are
not invented. Additional stored artifacts remain separate protected resources.

Before each stream read, recheck current MCP connection, `v2:files:download`,
same-customer ApiResultFile/CustomerFile/MlJob relationships, completed API job,
done MlJob, active/undeleted file and link, all expiries, result purpose, and valid
configured disk/path. Read only the remaining per-file/aggregate budget plus one
sentinel byte and close in every case. Do not trust database `size_bytes` to prove
the stream fits. MCP input never selects storage paths. Only allowlisted audio
and text MIME types can inline, and detected bytes must agree; binary text,
invalid UTF-8, HTML/executable types and MIME mismatches use fallbacks. Downloads
retain attachment disposition and `nosniff`.

Metadata and actual artifact resources are separate:

| URI | Meaning |
| --- | --- |
| `metkurd://jobs/{job_id}` | Bounded JSON job metadata, `application/json` |
| `metkurd://files/{file_id}` | Existing bounded JSON descriptor and authenticated download |
| `metkurd://artifacts/{file_id}` | Bounded audio blob or textual resource using actual artifact MIME |

The existing `resource_uri` field retains its JSON meaning. `artifact_uri` is
additive. Tool resource links target artifacts, using their authoritative MIME
(such as `audio/wav`, `application/x-subrip`, `application/pdf` or DOCX), not generic
JSON. An artifact resource that cannot safely inline returns a bounded resource
error directing the caller to its metadata/authenticated download. It never
labels descriptor JSON as audio. Neither resource nor tool results expose object
keys, S3 paths, public storage URLs or unauthenticated result links.

All 14 names and safety annotations remain stable. Human-readable titles, clearer
intent descriptions and object-root output schemas cover success and sanitized
error envelopes in both negotiated protocols. Instructions preserve `request_id`
after polling timeouts, require `metkurd_get_job` continuation, surface final
artifacts, and forbid invented voice IDs, file IDs or URLs. Delivery does not
reconcile, link files, settle/release reservations, change financial/job state or
dispatch workers. Existing scheduler authority is unchanged.

The [serialized fixture examples](MCP-RESULT-DELIVERY-EXAMPLES.md) include a small
WAV, oversized WAV fallback, SRT and four-stem fallback. IDs/URLs are synthetic;
the downloads still require OAuth bearer authorization. Tests use fake storage
and mocked submissions; no production acceptance is claimed by this source patch.

All names below have the `metkurd_` prefix. `request_id` is a required UUID for
every processing/upload-session call. Strict SDK schemas reject extra fields,
raw URLs, paths, worker settings and internal action names. `language` is
`ckb|ar|en` (default `ckb`); speech `model` is `1.5|2.0` (default `2.0`).
The portal renders the actual schemas, localized descriptions and examples.

| Tool | Arguments besides request_id | Scope | Native service / billing |
| --- | --- | --- | --- |
| list_services | none; no request_id | jobs:read | Current permitted service names; no processing charge |
| list_voices | none; no request_id | speech | OmniSpeakerCatalog public `id,name`; no charge |
| speak | text, voice; optional language, model | speech | OmniSubmissionService / Apollo; characters |
| clone_voice | text, reference_id; optional reference_text, language, model | voice-clone | CloneOmniSubmissionService / Vector; characters |
| generate_multi_speaker_speech | ordered segments: text, voice, language, pause_after_ms | speech | MultiSpeakerSubmissionService / Zeta 1.0; total characters |
| generate_multi_speaker_cloned_speech | ordered segments: text, reference_id, language, pause_after_ms; optional reference_text | voice-clone | MultiSpeakerSubmissionService / Theta 1.0; total characters |
| transcribe | file_id; optional language, intelligent | transcriptions | LeoSubmissionService; inspected audio minutes |
| caption | file_id; optional language, intelligent | captions | CaptionSubmissionService; inspected audio minutes |
| ocr | file_id; optional pages, exports, intelligent | ocr | OcrV2SubmissionService; inspected selected pages |
| harakat | text | harakat | HarakatSubmissionService; characters |
| stem | file_id, mode (2 or 4) | stem | StemV2SubmissionService; inspected audio minutes |
| get_job | job_id; no request_id | jobs:read | Local persisted status/results; no processing charge |
| list_recent_files | none; no request_id | files:download | Up to 20 owned active inputs/references; no charge |
| create_upload_session | purpose | mcp:uploads + purpose family | Short-lived browser upload; no processing charge |

Scope suffixes in the table mean `v2:` except the explicit `mcp:uploads` scope.
`voice` is a public catalog ID, never `ref_audio`. Discover voices before selecting
an unknown ID. Reference IDs use the existing owned reference boundary.
Zeta/Theta remain model_2 batch features: one project, one native job and final
audio, pauses `0|500|1000|2000`, no trailing pause. No new worker or endpoint.

Limits come from `InputBoundary`, `MultiSpeakerInput`, `HarakatInput`, current plan
entitlements and `metkurd_v2` configuration. Single speech text uses the existing
action character limit (default 400 when absent); reference text is at most 4,000
characters. Batch segment/count/total/reference-byte limits are unchanged. Harakat
uses its configured limit. Audio references are at most 20 MiB; ordinary audio and
OCR documents at most 100 MiB. Server inspection verifies actual MIME/duration/page
count before billing. OCR accepts the existing document/image formats and exports
`txt|docx|markdown|html|zip`, default `txt,docx`; pages defaults to all. Intelligent
processing defaults false and Caption's native defaults are preserved. API pricing
and action limits remain authoritative even where schema bounds are more general.

Read tools are annotated read-only. Processing is explicitly billable creation,
not read-only or destructive deletion. Write tools are idempotent with request_id;
metadata is an aid to hosts, not a replacement for server authorization.

### Example calls and asynchronous results

```json
{"request_id":"9283ec23-c24c-493f-9890-408bbf784342","text":"بەخێربێن بۆ MetKurd AI","voice":"VOICE_ID_FROM_DISCOVERY","language":"ckb","model":"2.0"}
```

The initial processing result is structured content plus MCP text/resource links:

```json
{"job_id":"job_EXAMPLE","status":"processing","service":"speech","credits_reserved":30,"created_at":"2026-09-26T00:00:00Z","completed_at":null,"expires_at":null,"result":null,"resource_uri":"metkurd://jobs/job_EXAMPLE"}
```

The credits/status above are illustrative, never a fixed quote. The actual state
can be queued, processing, completed or failed. `metkurd_get_job` reads local
state without settling or releasing reservations, changing wallets/ledgers,
ApiJob/MlJob, result links or stored objects, polling providers or dispatching
work. Existing scheduler/reconciliation persists terminal API state and all
artifact links in the same transaction as settlement. A completed MlJob whose
API reconciliation is pending still appears queued/processing until reconciled;
a read never repairs it. Existing primary result links are reused for older jobs;
missing links are not fabricated by a read. Transport rate counters and connection last-used time
are operational metadata, separate from job and financial state. Text outputs are
bounded to 64,000 characters per text/srt field with a truncation flag. Complete
files remain available subject to ownership/expiry. Caption segment arrays are
omitted from MCP responses to bound size.

`metkurd://jobs/{job_id}` returns JSON job metadata. `metkurd://files/{file_id}`
returns safe MIME/size and an OAuth-protected `/mcp/files/{file_id}` URL. Actual
bounded content uses the separate artifact URI described above. Result IDs are
API result-link IDs, distinct from integer upload
CustomerFile IDs. Binary downloads stream through MetKurd and require the same
bearer authority and file scope. A bare browser click without authorization does
not work; a host must support authenticated fetching or the user can use normal
MetKurd result/history UI. No signed storage URL/object key is exposed.

### Upload handoff

Chat attachments do **not** automatically transfer. Call create_upload_session
with purpose `ocr|transcription|caption|stem|voice_reference`; receive a UUID,
15-minute URL/expiry and nullable file_id. Open the page while logged into the
same customer, upload once, then reuse its result or list_recent_files. A repeated
session request with the same UUID returns the same session; another purpose
conflicts. Expired sessions cannot upload, and successful replay returns the same
owned file. This browser workflow requires App V2 to be enabled independently.

InputBoundary validates files; CustomerOutputStorage creates ordinary private
CustomerFiles, counts input storage quota, and follows default seven-day input
retention. References have the existing speaker_reference role and invalidate the
existing reference cache. Processing input file IDs are purpose-specific MCP
uploads; existing owned saved voice references also work. Temporary local copies
are bounded and closed in finally blocks. Existing storage expiry/cleanup remains
responsible for objects. Upload-session metadata remains durable for retry
identity; this release has no automatic session-row pruning policy. Do not remove
rows and inadvertently turn an old retry into a fresh upload session.

## Idempotency, limits, errors and privacy

SDK transport IDs can restart after a client reconnects, so they are not a durable
paid intent. Required request_id represents the tool-call identity: connection UUID
+ normalized UUID → deterministic existing API idempotency key. Canonical argument
hash detects reuse for different inputs. Same identity returns the same local job,
including after source-file expiry; a new deliberate UUID can create a new job with
identical text. Reconnection does not change the customer/client connection UUID.
Existing customer locks, unique API identity and native submission protections
handle concurrent admission. Lost/ambiguous provider acceptance keeps its existing
reservation/review state and never silently redispatches/refunds. A terminal local
failure remains terminal for that identity.

Tools share `customer-api-rate:{customer_id}` with REST and the existing API
concurrency admission. Outer machine throttle is 120/minute/IP, token exchange
30/minute/IP and browser upload 20/minute/IP. Shared production rate/cache/session
stores are required. Read resources are bounded by the transport throttle.

Safe tool errors use stable English codes such as paid_plan_required,
api_access_unavailable, scope_not_allowed, invalid_request, invalid_file,
insufficient_credits, concurrency_limit_exceeded, rate_limit, idempotency_conflict,
job_not_found and service_unavailable. Completed failed jobs use the existing
processing_failed/storage_limit_exceeded codes. SDK owns protocol/auth envelopes.
Browser pages translate EN/AR/KU, preserve RTL and shared confirmation/navigation.
Global Laravel MCP exception rendering sanitizes errors before debug output;
OAuth/browser validation retains the normal login/CSRF lifecycle.

SDK logging uses NullLogger; exception reporting suppresses MCP request bodies and
logs only safe exception type. Native submission safe errors use job ID/code.
Leo/Caption submission diagnostics retain IDs and exception class, with arbitrary
exception messages removed to prevent private provider/request content reaching logs.
Connections store client name/identity, scopes/status and timestamps only; no
conversation or bearer token. Native jobs retain their usual required inputs and
results, not a new conversation archive. Arbitrary active MCP request metadata is
removed before legacy session persistence. Operator access logs/APM must also
exclude OAuth query/body credentials, tool arguments and uploaded content.
Unsolicited client response payloads are excluded from persisted sessions. The SDK
requires an outgoing response queue for legacy delivery; session values are encrypted
using Laravel Crypt and the shared APP_KEY, and the SDK drains that queue on delivery.
This interface does not enable sampling or server-initiated RPC. Production must
disable debug output and request-recording development collectors.

HTTPS authority/Origin are validated; disallowed origins fail. Machine bodies are
bounded to 256 KiB before Laravel input normalization and again by the SDK.
The reverse proxy must enforce upload/body/time limits too. Current direct
cross-origin browser JavaScript adapters are not claimed: machine clients are
expected to make server/native HTTP requests. There is no permissive CORS wildcard.
Review actual trusted proxies; the repository's pre-existing `TRUSTED_PROXIES=**`
example is not evidence of a safe public deployment. Edge ingress must prevent
direct proxy-header spoofing and use explicit trusted proxy ranges.

`php artisan down` keeps MCP, token and discovery responses JSON HTTP 503 rather
than graphical maintenance HTML. Browser authorization stays blocked by Laravel
maintenance unless the intentional operator bypass is active. Do not configure a
blanket maintenance redirect for machine paths; the existing explicit native
redirect/exclusion policy remains operator-controlled.

## Customer portal and client compatibility

The separate `/{locale}/app-v2/mcp` page is linked beside API. It shows overview,
connection instructions, bounded owned connections/revocation, clients, all tools,
voices, upload handoff, jobs/results, credits, examples, security and troubleshooting.
Free users get the existing plans link and no Connect action. Paid customers with
missing API scopes get a configuration explanation. No token appears in DOM.
Revoke uses the existing `data-v2-confirm` SweetAlert bridge and retains jobs/files.
No new global navigation handlers or asset reloads were added. Admin execution or
impersonation was not added; existing P2 persisted jobs remain the operational view.

Official documentation checked **2026-09-26**; the operator subsequently confirmed
real ChatGPT production acceptance on **2026-10-05**, including one controlled
Apollo 2 paid generation and its protected result (see production lessons above).
Other clients, other paid-tool flows and refresh behavior require their own acceptance.
Host presentation (`@MetKurd`, slash commands, confirmation prompts)
depends on the client and is not a universal MetKurd command.

- **ChatGPT:** current official web documentation lists Plus, Pro, Business,
  Enterprise and Education for read/write developer mode, subject to workspace
  policy. Open Settings → Security and login → Developer mode, then ChatGPT Plugins
  → plus; supply the remote URL and OAuth. Use automatic CIMD or the public-client
  preregistration fallback and review tools. This host limitation
  is separate from MetKurd paid eligibility. [Developer mode](https://developers.openai.com/api/docs/guides/developer-mode),
  [connection](https://developers.openai.com/plugins/deploy/connect-chatgpt),
  [authentication](https://developers.openai.com/plugins/build/auth).
- **Claude web/Desktop:** configure an account remote connector. Organization
  owners use Organization settings → Connectors; users access Customize → Connectors.
  Advanced settings accept a public client ID. Select the reviewed connector and
  complete OAuth. Remote availability/account policy is governed by Claude.
  [Official connector instructions](https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp).
- **Claude Code:** `claude mcp add --transport http metkurd https://metkurd.ai/mcp`,
  then `/mcp` to sign in. CIMD is documented. The fallback uses `--client-id`
  and `--callback-port` with a reviewed native registration. Current docs use
  `http://localhost:PORT/callback` (restored in 2.1.231); match that fixed port/path.
  Local OAuth fixtures pass; real-client acceptance remains pending.
  [Official MCP configuration](https://code.claude.com/docs/en/mcp).
- **Codex:** `codex mcp add metkurd --url https://metkurd.ai/mcp`, then
  `codex mcp login metkurd`. Compatible versions discover CIMD automatically.
  For the `--oauth-client-id YOUR_REGISTERED_CLIENT_ID` fallback, register the
  complete displayed `127.0.0.1` callback, including its server-specific suffix.
  The listener port may vary; external HTTPS callback routing is no longer needed.
  [Official Codex MCP](https://developers.openai.com/codex/mcp).
- **Generic:** server MetKurd AI, Streamable HTTP, URL `https://metkurd.ai/mcp`, OAuth;
  support PKCE S256, resource indicators, refresh and CIMD or public preregistration
  under the exact web/native callback policy above.
  Do not paste keys into prompts/configuration or use legacy SSE as a substitute.

Protocol sources: [PHP SDK releases](https://github.com/modelcontextprotocol/php-sdk/releases),
[SDK HTTP](https://php.sdk.modelcontextprotocol.io/run/http/),
[MCP authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization),
[Laravel Passport](https://laravel.com/framework/docs/12.x/passport).

| Host | Identification / callback | PKCE, resource and refresh evidence |
| --- | --- | --- |
| ChatGPT | CIMD or public registered ID; host-provided exact HTTPS callback | Local grant enforcement tested. Operator reports production discovery, CIMD OAuth, initialize, tools/list, list_voices, and one controlled Apollo 2 paid generation through API credit consumption, persisted completion and protected file delivery on 2026-10-05. Refresh, other paid flows and the new native inline media patch need separate acceptance. |
| Claude web/Desktop | Public registered ID in advanced connector settings; exact HTTPS callback | Remote OAuth documented; wire-level resource/refresh behavior still needs real-client acceptance. |
| Claude Code | CIMD where supported, or public ID + fixed localhost callback | MCP OAuth documented; local S256/native/code/refresh fixtures pass. Actual exchange untested. |
| Codex | Automatic CIMD with loopback, or public ID + displayed callback suffix | Current guidance reviewed; local ephemeral-port OAuth fixtures pass. Actual refresh/resource exchange untested. |
| Generic | CIMD or public preregistration; web HTTPS or native loopback | Must satisfy enforced S256/resource contract. DCR-only and confidential-only clients unsupported. |

The portal leads with client choice → Connect → sign in → consent → task, with
expandable advanced setup. Natural-language examples remain; no universal
`@MetKurd` or slash-command invocation is promised.

### SDK version and upgrade policy

Keep the exact pre-1.0 `mcp/sdk: 0.8.1` pin; this hardening requires no upgrade.
Upgrades require release/dependency/security review and regression tests for both
negotiated protocols, OAuth/CIMD/PKCE/refresh/revocation, strict schemas, resources,
encrypted sessions, idempotency, paid authority and the focused REST/native suite.
Repeat real ChatGPT, Claude web/Desktop, Claude Code and Codex staging acceptance
before promotion. A successful Composer resolution is insufficient.

## Configuration and operator rollout

No application/production migration, gate change, OAuth registration, key creation,
deployment or paid configuration update was performed by this implementation.
Only isolated SQLite tests install the schema fixtures.

| Name | Default / use |
| --- | --- |
| FEATURE_MCP_V2 | false; independent machine rollout |
| MCP_PUBLIC_URL | https://metkurd.ai/mcp |
| MCP_OAUTH_ISSUER | https://metkurd.ai; same origin |
| MCP_TOKEN_TTL | 10 minutes |
| MCP_REFRESH_TOKEN_TTL | 30 days |
| MCP_SESSION_STORE | redis; shared production cache |
| MCP_ALLOWED_ORIGINS | https://metkurd.ai; exact comma-separated origins |
| MCP_OAUTH_PRIVATE_KEY / MCP_OAUTH_PUBLIC_KEY | protected PEM configuration or Passport key files |

App/API gates are independent. MCP OAuth/tools can operate with REST API disabled;
portal/browser file handoff also needs App V2. Do not enable other gates implicitly.
The existing APP_KEY must remain shared/stable for refresh/code encryption; it must
never be regenerated as part of MCP installation. All nodes share signing keys,
DB, session/cache and normal durable storage/scheduler infrastructure.

Operator steps, **only after separate deployment authorization**:

1. Review this contract, dependency/security audit, backups/restore and pending
   migrations against the intended native MySQL deployment. Keep all gates at their
   independently approved states. No production seeders.
2. Install the reviewed lockfile (`composer install --no-dev --prefer-dist --optimize-autoloader`).
   Passport auto-discovery is excluded; McpServiceProvider registers it with its
   automatic routes disabled. Do not run `passport:install` or a blanket vendor migration publish.
3. Review `php artisan migrate:status`. Preview each of these six paths with
   `php artisan migrate --pretend --path=database/migrations/FILENAME.php`.
   Apply **only reviewed pending paths**, in order, with
   `php artisan migrate --force --path=database/migrations/FILENAME.php`:
   - `2026_09_26_000100_create_oauth_auth_codes_table.php`
   - `2026_09_26_000200_create_oauth_access_tokens_table.php`
   - `2026_09_26_000300_create_oauth_refresh_tokens_table.php`
   - `2026_09_26_000400_create_oauth_clients_table.php`
   - `2026_09_26_000500_create_customer_mcp_connections.php`
   - `2026_09_26_000600_support_mcp_client_metadata.php`
   The first four are reviewed package tables (no device grant). The fifth creates
   connections/uploads and makes `api_jobs.api_key_id` nullable so MCP does not
   manufacture an API key. It preserves existing rows/FKs and does not alter wallets.
   The sixth widens exact client identities for CIMD and adds native/web type and
   metadata hash fields and Passport's nullable client scope restriction. It does not truncate identities on rollback. Native MySQL
   DDL/index/retained-row acceptance is required; no application migration was run.
4. Complete the [mandatory Passport signing prerequisites](#mandatory-passport-signing-prerequisites).
   Reuse the existing signing identity. Generate once only when no existing valid
   pair or grants exist; distribute that same pair to every app node. Missing keys
   with retained grants require recovery, not independent node generation.
5. Configure the names above, explicit trusted proxy ranges, HTTPS ingress, shared
   Redis/cache/rate limiter and shared browser sessions. Configure request/upload
   limits, existing media/document probes, private storage, scheduler and queue.
   Validate maintenance behavior and [machine ingress](#machine-ingress-and-cloudflare-acceptance).
   Keep FEATURE_MCP_V2 false during initial setup.
6. Through existing audited Admin controls, review each plan's explicit V2 family
   scopes, API action entitlements/prices and allowances. Missing allowance is an
   access/configuration issue, not authorization to mint credits.
7. CIMD clients need no operator registration. For a fallback, register a reviewed callback with
   `php artisan mcp:register-client "Reviewed client name" "https://CLIENT/EXACT_CALLBACK"`.
   Replace the example with the actual host-provided URI. The returned public client
   ID goes into the client; there is no secret to paste. No callbacks were registered
   outside isolated tests during implementation.
   For native fallback add `--native` and its exact loopback URI. Prefer IP literals;
   Claude Code localhost fallback requires a fixed matching port. Run
   `php artisan mcp:readiness` to inspect signing and plan configuration without granting access.
8. In an approved staging environment, explicitly enable MCP, rebuild configuration
   cache and restart app/queue instances through normal deployment procedures.
   Run the acceptance matrix below. Enable production only under separate approval.
9. Monitor safe connection/job/error metadata, existing reservations and queue
   reconciliation. Verify expired file cleanup and ordinary Passport token-table
   maintenance under an approved retention policy; do not automatically delete
   historical connection/upload intent records.

Rollback: disable FEATURE_MCP_V2 and refresh configuration on every node. Revoke
affected connections if needed; existing jobs continue through the scheduler and
results remain owned. Retain migrations/history; do not rollback populated OAuth
tables, make api_key_id nonnullable, remove native jobs or regenerate APP_KEY.

### Mandatory Passport signing prerequisites

Complete this check before enabling MCP and repeat on every load-balanced app
node after deployment. An enabled feature flag and healthy origin are insufficient.

- Inventory the effective `MCP_OAUTH_PRIVATE_KEY` / `MCP_OAUTH_PUBLIC_KEY` settings,
  Passport key files and retained OAuth grants without printing secrets. Nonempty
  settings override the corresponding files. Preserve an existing valid identity;
  if grants exist but keys are missing, recover the original pair from the approved
  secret/backup process. Do not silently rotate it.
- Only if **no existing valid pair or grants exist**, generate Passport keys
  **once** with `php artisan passport:keys` under the separately authorized
  deployment procedure. Securely install the exact same private/public pair on
  every node. Never independently generate a pair per node. Never run
  `passport:install`; never use `--force` over an existing production key pair.
- Keep both files outside the web root and out of repositories, logs, command
  output and support attachments. Recommended ownership is `root:www-data` and
  mode `640`, subject to deployment policy and the actual PHP-FPM user/group.
  Verify directory traversal permissions and that `www-data` can read **both**.
  Protect inline PEM configuration through the existing secret-management policy.
- Verify the public key derived from the private key matches the installed public
  key. Compare SHA-256 public-key fingerprints across **every** app node. Preserve
  the existing shared APP_KEY separately; never regenerate it for MCP.
- Verify Passport's `AuthorizationServer` can instantiate as the PHP-FPM identity,
  using its effective configuration and PHP runtime. A successful root CLI check
  does not prove that the service user can read the keys.

Read-only operator examples, from the reviewed release directory (substitute the
actual protected Passport key directory; these commands were not run in this task):

```bash
set -o pipefail
KEY_DIR='/reviewed/private/passport'
sudo -u www-data -- test -r "$KEY_DIR/oauth-private.key"
sudo -u www-data -- test -r "$KEY_DIR/oauth-public.key"
sudo -u www-data -- openssl pkey -in "$KEY_DIR/oauth-private.key" -pubout -outform DER | openssl dgst -sha256
sudo -u www-data -- openssl pkey -pubin -in "$KEY_DIR/oauth-public.key" -outform DER | openssl dgst -sha256
sudo -u www-data -- php artisan mcp:readiness --signing-only
```

Require successful exits and identical fingerprints; a digest from a failed pipe
is not acceptance. These pipelines print only a public-key digest, never PEM.
For inline PEM use readiness's public fingerprints without exporting PEM to a
terminal. Do not use `cat`, `-text`, config dumps or exception dumps to inspect keys.
`sudo` does not automatically reproduce PHP-FPM pool environment: the operator
must supply the same approved effective configuration/runtime and confirm FPM's
readability. Do not put secret values on the command line.

Readiness emits one `oauth_signing_keys` JSON record: configuration/file source,
RSA key usability, each key's public SHA-256 fingerprint (DER SubjectPublicKeyInfo),
`pair_matches`, `authorization_server` and `ready`. Invalid/missing/unreadable keys
share a sanitized failure status; exception text and paths are suppressed. Server
construction is attempted only for a valid matching pair, without issuing a token.
`--signing-only` does not query plans or the database; default mode retains the
existing read-only plan report even when signing fails. Exit 1 means signing is
not ready or the plan audit failed. Exit 0 is not full production acceptance:
plan rows still require review and runtime account/wallet checks still apply.

The check covers the **current process only**. It does not generate, replace,
chmod or distribute keys, compare remote nodes, impersonate PHP-FPM, query edge
settings or access production. Ownership/mode, web-root placement, retained grants,
cross-node equality and actual ingress remain explicit operator checks.

### Machine ingress and Cloudflare acceptance

MetKurd API and MCP are machine-to-machine surfaces. Never require JavaScript or
browser challenges on `/api/*`, `/mcp` and its machine subpaths, `/oauth/token`, or
the OAuth well-known metadata documents. On Cloudflare Free, standard Bot Fight
Mode cannot be skipped per path with WAF custom rules or Page Rules; it must remain
**OFF** while these machine surfaces are proxied through that configuration.
This does not disable DDoS protection, TLS, Managed WAF, ordinary custom security
rules or appropriate rate limiting. On a future tier supporting Super Bot Fight
Mode, use reviewed exceptions scoped to the machine endpoints and that bot
component, retaining normal browser protection and other security controls.
See [Cloudflare Bot Fight Mode](https://developers.cloudflare.com/bots/get-started/bot-fight-mode/)
and [Super Bot Fight Mode exceptions](https://developers.cloudflare.com/bots/get-started/super-bot-fight-mode/).

Keep OAuth/Bearer authentication, API keys, plan/scopes/action authorization, API
wallet authority, idempotency, customer/API throttling, the MCP outer IP throttle,
concurrency controls, validation, Cloudflare DDoS/WAF/TLS and Nginx limits intact.
Do not add a ChatGPT IP allowlist as a replacement for OAuth. A bot-rule exception
does not authorize a request or bypass Laravel's controls.

After separately authorized deployment, test the public edge without credentials:

```bash
curl -i https://metkurd.ai/mcp
curl -i https://metkurd.ai/api/v2/services
curl -i https://metkurd.ai/.well-known/oauth-protected-resource/mcp
curl -i https://metkurd.ai/.well-known/oauth-authorization-server
```

| Probe | Required response during normal enabled service |
| --- | --- |
| `/mcp` | MetKurd HTTP 401 with `WWW-Authenticate: Bearer` and resource metadata pointing to `https://metkurd.ai/.well-known/oauth-protected-resource/mcp` |
| `/api/v2/services` | HTTP 401, `Content-Type: application/json`, error code `authentication_failed` |
| OAuth protected-resource document | HTTP 200 JSON; resource `https://metkurd.ai/mcp`, correct authorization server |
| OAuth authorization-server document | HTTP 200 JSON; issuer `https://metkurd.ai`, correct public OAuth endpoints |

None may return Cloudflare challenge HTML, `cf-mitigated: challenge`, Managed
Challenge or "Just a moment". An HTTP status alone is insufficient: inspect
headers and body. Maintenance or intentionally disabled gates need their own
expected responses; do not weaken those gates to force this matrix to pass.

Repeat the same four probes against **each** origin node using the reviewed
`--resolve` method, preserving hostname, SNI and certificate verification:

```bash
NODE_IP='REVIEWED_NODE_IP'
curl -i --resolve "metkurd.ai:443:$NODE_IP" https://metkurd.ai/mcp
curl -i --resolve "metkurd.ai:443:$NODE_IP" https://metkurd.ai/api/v2/services
curl -i --resolve "metkurd.ai:443:$NODE_IP" https://metkurd.ai/.well-known/oauth-protected-resource/mcp
curl -i --resolve "metkurd.ai:443:$NODE_IP" https://metkurd.ai/.well-known/oauth-authorization-server
```

Use only approved origin connectivity and CA trust (no `-k` or firewall bypass).
Origin success does not prove edge success: retain both sets of sanitized
status/header evidence. Never include tokens, PEM or customer data in evidence.

## Verification and acceptance

### Local development / acceptance — 2026-09-26

**Historical snapshot:** the observations and verification results in this
September 26 section describe evidence available then. Statements that no remote
client was connected or production was unverified remain historical facts;
current ChatGPT production acceptance is recorded in the October 5 section above.

The operator applied the six migrations and configured the paid-tier scopes before
this runtime phase. Fresh read-only inspection confirmed all six migrations in
batch 20, and all seven family scopes plus job-read/file-download for Student,
Pro and Premium. All 12 actions are scoped, entitled and have applicable pricing
rows. Free remains API-disabled. No plans, credits, pricing or entitlements were
changed during runtime acceptance. Customer effective-plan authority and actual quotes
remain separate from plan configuration.

The verified application is `APP_ENV=local`, using the existing local development
database over loopback on the same machine. The actual server is **MariaDB
10.4.28**, despite Laravel's `mysql` driver. This is not native MySQL/RDS acceptance.
No application migrations, seeders or production connections were executed here.

Local setup:

- Reused the installed XAMPP Apache/PHP runtime for a separate, loopback-only
  HTTPS listener at **`https://localhost:8443`**. Both IPv4 and IPv6 loopback are
  bound. Existing port 80/443/8000 listeners were not replaced or restarted.
  The machine-specific configuration is ignored at
  `storage/app/private/mcp-local/apache.conf`; it is not a deployable server config.
- Created a dedicated 30-day localhost TLS certificate, trusted in the current
  Windows user's certificate store, valid through 2026-10-26. The PHP acceptance
  client explicitly trusts that certificate; verification is never disabled.
  A later CLI/client must trust the same certificate using its supported CA setting.
  Renew the certificate before expiry. No production TLS material is reused.
- Generated missing local Passport keys with `php artisan passport:keys`, without
  `--force`. Files are `storage/oauth-private.key` and `storage/oauth-public.key`.
  Nonempty `MCP_OAUTH_PRIVATE_KEY` / `MCP_OAUTH_PUBLIC_KEY` configuration overrides
  those files; neither override was present. The PHP process can read both.
  The private key ACL is restricted to the current Windows user, SYSTEM and
  Administrators. Existing `/storage/*.key` and private storage ignore rules protect
  keys/runtime artifacts. APP_KEY was present and was not regenerated or changed.
- Installed Redis 7.0.15 from Ubuntu's package repository in the already installed
  WSL Ubuntu, and the official PHP Redis 6.3.0 extension matching PHP 8.2 TS x64.
  Redis listens only on IPv4/IPv6 loopback, port 6379, with protected mode. Its local
  password is unset; this is not a production network/authentication policy. No
  Redis flush, Docker environment or Composer dependency update was performed.
- The dedicated listener uses these scoped settings; **the existing `.env` remains
  unchanged**. Artisan commands do not automatically inherit Apache `SetEnv`.
  Use the same explicitly scoped environment for any future acceptance client or
  worker that needs these runtime settings. Readiness now also checks local signing
  prerequisites (see the 2026-10-05 production lessons).

```dotenv
APP_URL=https://localhost:8443
MCP_PUBLIC_URL=https://localhost:8443/mcp
MCP_OAUTH_ISSUER=https://localhost:8443
MCP_ALLOWED_ORIGINS=https://localhost:8443
MCP_SESSION_STORE=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_CONNECTION=default
SESSION_STORE=redis
SESSION_COOKIE=metkurd-local-260915-session
SESSION_SECURE_COOKIE=true
CACHE_PREFIX=metkurd-local-260915-cache-
REDIS_PREFIX=metkurd-local-260915-database-
TRUSTED_PROXIES=""
```

Redis client remains `phpredis`, with default/cache DBs 0/1 and distinct local
prefixes. Queue backend remains `database`. Configuration is not cached; no cache
clear/flush was needed. The direct TLS listener has **no trusted reverse proxy**;
spoofed forwarded host/scheme headers did not change the discovery authority.
Production must separately specify its real ingress/proxy ranges and exact origins.

After migration/key/TLS/Redis prerequisites passed, `FEATURE_MCP_V2=true` was
set **only for this HTTPS listener**. The normal `.env`/CLI gate remains false.
Existing App V2 and API V2 gates were already true locally and were not changed;
API V2 is not a prerequisite for MCP, while App V2 is needed for the portal/upload
workflow. Production was not contacted, reconfigured or enabled.

Live network evidence:

| Check | Result |
| --- | --- |
| HTTPS `/up` | 200 with certificate validation |
| Both OAuth well-known documents | 200; exact local issuer/resource; S256, authorization-code/refresh, public `none`, CIMD; no DCR or production hostname |
| Missing Origin / exact local Origin | Accepted for discovery |
| Foreign Origin | 403 |
| Forwarded host/scheme spoof | Ignored; local issuer retained |
| Missing bearer, including official PHP MCP SDK initialize over the real HTTPS socket | 401; authenticated initialization not performed |
| Unknown-client token exchange | 401 |
| OAuth authorize and EN/AR/KU portal before login | 302 to the same local HTTPS origin; Secure/HttpOnly local session cookie |
| Two independent PHP processes using Redis | Cache/session/rate-counter visibility, mutual-exclusion lock and encrypted legacy MCP session roundtrip passed |
| Maintenance, with no active local jobs | `/mcp`, token and discovery returned JSON 503 + Retry-After 60; authorize/portal returned HTML 503; `up` restored health 200 |

Two-process Redis evidence is not a two-host deployment or native MySQL
refresh/revoke concurrency test. No queue worker/scheduler was running; neither
was started, because no approved paid processing job could be submitted.

**Business-policy correction, 2026-09-26:** the earlier runtime attempt rejected local
customer 1's Student `admin_manual_grant` because of an extra MCP payment-provenance
condition. The operator clarified that this was incorrect. The corrected source
accepts the existing effective active non-Free plan; read-only local inspection now
confirms an active account/Student plan, API capability, configured V2 scopes and
an API wallet with available credits. No payment, agreement, plan, scope, billing
history or credits were changed. The exact short Apollo 2 readiness quote also
passes action, price and available API-credit checks without submitting a job.

The corrected full MCP suite passed **150 tests / 1,392 assertions**, including
configured Student/Pro/Premium manual grants, a custom non-Free plan and internal
access without creating payment evidence. Normal online and bound agreement access,
expired grants, Free with carried credits, inactive plans, suspension, removed
scope/action access and insufficient API credits are covered. Online and manual
OAuth fixtures both exercise consent, S256, code/refresh replay and revocation.
All 67 frontend tests and changed-file syntax/Pint checks passed. Underlying billing
authority and REST source semantics were not changed by this correction.
The sequential REST API V2 plus billing-epoch regression run passed another
**97 tests / 2,035 assertions**: **247 distinct PHP tests / 3,427 assertions** for
this policy-correction pass. Test configuration stayed SQLite `:memory:`, array
cache/session, fake storage and mocked providers.

During real local browser acceptance, the operator completed login and consent as
customer 1. Resource-bound S256 code exchange, the issued token's customer/scopes
and rejection of code replay passed. A later SDK read-only check failed; the test
connection and its tokens were revoked rather than retained for retries. The local
development debug toolbar was found active. It was disabled with
`DEBUGBAR_ENABLED=false` on this dedicated HTTPS listener only, and diagnostic
files belonging to that local acceptance attempt were removed. The second attempt
uses fresh browser consent and a longer test-client timeout. This local configuration
correction does not change `.env`, the normal localhost listener or production.
At this checkpoint, the replacement native client is waiting for the operator's
fresh consent. The first attempt does not establish completed SDK discovery,
refresh-rotation or revoked-bearer acceptance. The ignored local acceptance helper
keeps grant tokens in memory, requests only speech/job/file scopes, revokes its
connections on completion/failure and expires its loopback wait after 20 minutes.
The approved development Omni endpoint is still unidentified, so paid processing,
real paid idempotency and final authenticated audio remain untested locally.

Apollo 2 would use `runpod.endpoints.omni_v2`, sourced from
`RUNPOD_ENDPOINT_ID_OMNI_V2`. It is configured, but its environment classification
is not encoded in the settings. The operator will identify an approved development
endpoint. **No provider, paid tool or file-processing call was made.** Do not start
a blanket scheduler or worker to make acceptance appear complete.

Both browser automation entry points failed to initialize with “failed to write
kernel assets … path specified … os error 3”. The operator therefore performed
the real browser login/consent manually during the policy-correction follow-up.
Full authenticated EN/AR/KU/RTL/mobile/navigation, connection-management UI and
uploads remain unverified. HTTP redirects alone are not visual acceptance.

To resume after those blockers are resolved: use the official PHP client against
the exact HTTPS resource; register a local public native callback with
`mcp:register-client ... --native`, using its actual loopback path/port. Local CIMD
metadata stays disallowed by SSRF controls; use preregistration for this live local
test. Complete real browser consent with S256 and the exact resource in both
authorization and token requests, then test refresh rotation/replay and revocation.
Call `metkurd_list_services` and `metkurd_list_voices` first, checking no billing/jobs.
Only against the approved endpoint, submit one short Apollo 2 speech request, replay
its request_id, and let existing durable reconciliation produce the final private
audio. `get_job` must remain read-only. A new request_id represents another paid
operation. Test upload handoff and one small OCR/ASR operation only after this works.

The focused isolated regression follow-up passed **34 tests / 708 assertions**,
covering SDK/idempotency, OAuth/PKCE/refresh, native/CIMD policy, paid-authority
denials, pure reads, localized portal rendering, encrypted sessions and maintenance.
These used SQLite `:memory:`, array test cache/sessions, fake private storage and
mocked processing; they did not modify the local application database. No source
fix or dependency-version change was required, and no frontend asset changed.

Local Apache access logging excludes queries, referers, credentials and request
bodies. Initial pre-consent runtime log scans found no token-field/private-key/signed-URL
markers in the new application log bytes or dedicated Apache logs. No real grant
tokens had been issued at that point, so this was limited evidence; see the later
policy-correction notes above for real grants and debug-toolbar cleanup.
Tokens/verifiers/keys must remain outside terminal output and browser
storage/history/logs. Completed live token privacy and authenticated download
acceptance cannot be claimed before an actual OAuth grant exists. Next external
client order remains Codex, Claude Code, Claude web/Desktop, then ChatGPT; none was
connected in this phase. Hosted connectors additionally need a separately approved
public HTTPS test origin. Do not repoint this local resource to production.

Reproduce only with isolated test configuration (`APP_ENV=testing`,
`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`,
`SESSION_DRIVER=array`; inspect inherited/cached configuration first):

```text
php vendor/bin/pest tests/Feature/Mcp/McpTest.php tests/Unit/McpMetadataTest.php tests/Unit/McpPrivacyTest.php tests/Unit/MaintenanceExperienceTest.php
php vendor/bin/pest tests/Feature/Api/ApiV2Test.php tests/Feature/MetKurd/V2CoreReviewTest.php tests/Feature/MetKurd/V2LocalizationTest.php tests/Feature/App/V2DashboardArchitectureTest.php
node --test tests/Frontend/*.test.mjs
npm run build
composer validate --no-check-publish
```

Run PHP lint and Pint against the changed files. Do not point fixture suites at an
application database or run the repository's deployment/seed commands as test setup.
Run fixture suites sequentially unless each process has a separate storage path;
fake disks and compiled views otherwise share local filesystem locations.

Initial implementation verification, 2026-09-26 (history, before this hardening):

- 128 tests / 12,437 assertions passed in the combined MCP, privacy, maintenance,
  V2CoreReview, V2Localization and V2DashboardArchitecture run. This includes 85
  MCP feature cases, all processing mappings under accepted/rejected/ambiguous
  outcomes, independent action entitlements, real browser CSRF enforcement and
  encrypted legacy SDK sessions.
- All 87 REST API V2 tests passed in the regression run: **215 distinct focused
  PHP tests passed** across these runs.
- All 67 existing frontend Node tests passed; production Vite build passed.
- Changed-file PHP syntax and Pint passed; Composer validation passed with
  advisory warnings about intentional exact dependency pins; git diff whitespace
  checks passed. The lockfile adds 14 packages, without changing existing versions.
- Earlier missing portal translations and a legacy SDK queue-filter regression
  were fixed and retested. A Windows compiled-view replacement access error did
  not recur in the final workspace run. No vendor compiler was modified.

Focused hardening verification — historical snapshot, 2026-09-26:

- **SOURCE VERIFIED:** MCP job/resource reads use the shared persisted-only
  serializer; durable synchronization owns settlement and artifact linking. REST
  retains its synchronization behavior. CIMD URL identities, native callbacks,
  public-client PKCE, bounded metadata discovery and declared client scopes are
  implemented. The SDK remains pinned to 0.8.1.
- **LOCAL VERIFIED:** 94 MCP feature cases, 44 metadata/security cases and two
  privacy cases passed across the full suite and final focused follow-ups. All 87
  REST API V2 cases passed. Another 183 focused service, storage, billing authority,
  effective-plan, maintenance, localization and App V2 cases passed: **410 distinct
  focused PHP tests** across these runs. This is not a claim that the whole repository
  test suite was run.
- All 67 frontend tests and the Vite production build passed. Changed-file PHP
  syntax, Pint, Composer validation and whitespace checks passed. Exact dependency
  pin warnings remain intentional. No dependency versions changed in this pass.
- At the hardening audit, read-only local plan inspection confirmed Student/Pro/Premium had no
  recognized V2 family scopes, despite configured API allowances and 12 entitled,
  priced actions per plan. No plan changes were made. This configuration is not
  ready for usable paid MCP tools at that point. The later operator configuration
  and local runtime section above supersede this historical scope finding.
- Initial fixture expectations that MCP reads would settle jobs were corrected to
  invoke durable reconciliation explicitly. Separate concurrent test processes
  also collided on their shared fake storage directory; sequential reruns passed.
  Neither issue was resolved by weakening production ownership or settlement rules.
- **REMOTE CLIENT VERIFIED: none.** ChatGPT, Claude web/Desktop, Claude Code and
  Codex have not been connected. The local official SDK and OAuth fixture tests
  do not establish actual host acceptance.
- **PRODUCTION UNVERIFIED:** no deployment, feature activation, application
  migration, production client registration or plan mutation was performed.
  Native MySQL DDL/locking, shared cache/session behavior, outbound DNS/egress,
  real clients, interactive RTL/navigation and real persistence/expiry acceptance
  remain required alongside deliberate Admin plan configuration.

Automated evidence is isolated SQLite `:memory:`, array session/cache, fake private
storage and mocked outbound processing. The official MCP client uses actual Laravel
HTTP kernel calls, not hand-written-only protocol requests. Test coverage includes
paid/free/expired/complimentary/agreement authority, revoked/suspended/expired/wrong
audience tokens, consent/PKCE/resource/refresh replay, strict schemas/scopes,
native tool mapping, API reservation/replay/failure, uploads and private resources.
REST/App V2 regressions and EN/AR/KU portal tests remain separate named suites.
Native DB locking, distributed Redis and real GPU/storage cannot be inferred from
these fixtures. Browser/mobile navigation and live OAuth return require interactive
acceptance; translated rendered markup is not a visual browser check.

**Historical verification matrix — 2026-09-26; not current production status.**
The ChatGPT row predates the October 5 production acceptance recorded above.

| Client / surface | Source tested | Locally tested | Remote client tested | Production |
| --- | --- | --- | --- | --- |
| Official PHP MCP client | Yes | Laravel HTTP, modern stateless + legacy handshake | No external network | Unverified |
| OAuth grants / tools / files | Yes | Isolated fixtures | No external OAuth host | Unverified |
| Customer portal EN/AR/KU | Yes | Rendered markup and shared frontend tests | No interactive browser acceptance | Unverified |
| ChatGPT | Integration/docs prepared | Not connected | Required | Unverified |
| Claude web/Desktop | Integration/docs prepared | Not connected | Required | Unverified |
| Claude Code | Current CIMD/native setup prepared | Fixed localhost OAuth fixtures | Not connected; required | Unverified |
| Codex | Current CIMD/native setup prepared | Ephemeral IPv4 OAuth fixtures | Not connected; required | Unverified |

Before release: test concurrent identical writes/refresh/revoke on two native MySQL
app nodes and shared Redis; test host consent denial, restart/refresh, downgrade,
revocation, paid tool confirmation, upload handoff and authenticated file rendering;
test mobile/RTL/navigation and real processing/persistence/expiry. Binary link
rendering and attachment transfer are host-dependent, not presumed supported.

The installation audit reported **45 advisories in 14 pre-existing locked packages**;
none were attributed to the newly installed packages. No broad dependency upgrades
were made. Resolve applicable inherited advisories under the existing application's
release/security process; source test success is not production security approval.
