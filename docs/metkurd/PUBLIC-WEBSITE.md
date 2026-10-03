# Public website content and discovery contract

Updated 2026-09-26. This describes source implementation and isolated local
verification, not a deployed release or approval to enable API/MCP.

## SEO readiness follow-up — 2026-10-03

The [Search Console refinement](PUBLIC-SEARCH-BASELINE.md) records the supplied
three-month baseline, descriptive localized H1, TTS/ASR intent copy, sourced
SI terminology note/FAQ, unchanged Pricing snippets and Translation retirement
options. AI remains the primary term; no new explainer route or service is added.

Public metadata and discovery now share the fixed canonical origin independently
of request host/APP_URL, with EN/AR/KU reciprocal alternates and English x-default.
Homepage descriptions are concise and availability-aware. Filename aliases return
to the existing locale entry; ingress still needs direct index.php normalization.
Local logo/QR WebP variants preserve the design and reduce transferred asset bytes.
See [the full source audit and production acceptance matrix](PUBLIC-SEO-ACCEPTANCE.md)
for measurements, remote-artwork limitations, test scope and proposed ingress rules.
No production/Cloudflare access or deployment occurred.

## Admin effective visibility follow-up — 2026-09-27

Admin Landing Tool Pages now reads the same current-family and public-family
projection as public discovery. Its badges, filters and counts distinguish:

- **Public / Active** (`status=active`): a current family, landing publication
  enabled, and at least one active current ToolAction with an active parent Tool.
- **Disabled** (`status=inactive`): a current family whose publication setting is
  disabled or which has no active eligible tool/action pair.
- **Legacy / Not Public** (`status=legacy`): a slug outside the current V2 public
  families, regardless of its historical `is_active` flag.

The editor labels its stored flag as **Landing publication**, not a guarantee of
public availability. Direct toggle/save calls cannot activate legacy slugs; legacy
editorial rows remain editable. Existing fresh Admin capability/reason/audit checks
remain. Imports derive their allowlist from the same current-family definitions,
create missing rows only, and preserve existing disabled rows. The five current
families are still tts, ctts, asr, ocr and stem. No public layout changed.

Root cause: the old import included `tran` and created active rows, while the old
Admin badge/filter used the raw flag. The public V2 refresh intentionally excluded
Translation without rewriting its historical CMS row. The local DB retained row 6
(`translation`, active, last updated 2026-04-25). The exact historical creator is
not established; the legacy import path explains how such a row could be created.

The narrowly scoped migration
`2026_09_27_120000_retire_translation_landing_publication.php` changes only that
slug's active flag, transactionally records a system migration audit (no impersonated
Admin), and preserves all editorial/media/demo fields and timestamps. It is
idempotent and its rollback does not reactivate a retired product or delete history.
Only this migration was applied to the verified loopback/local database: row 6 is
now inactive, audit event 12 records the change, and all other landing data was
compared unchanged. A pre-change snapshot is retained locally at
`storage/app/private/landing-translation-before-20260927.json` (not committed).
All five current local families still resolve Public / Active. Production was not
accessed or deployed; the migration is prepared for a separately authorized release.

Verification: 79 isolated PHP tests / 2,343 assertions passed across visibility,
Admin CMS connections and public website regressions. Coverage includes stale
active Translation, effective filters, active parent/action combinations, disabled
current pages, multiple products per family, repeated imports, direct legacy
activation rejection, capability revocation and exact data/audit preservation.
Four frontend Admin localization checks passed. Native local correction evidence
is separate from the isolated SQLite tests; browser visual acceptance was not run.

## Ownership and availability

`PublicProductCatalog` projects `MetKurdV2ToolCatalog` definitions onto active
`ToolAction` rows with active parent `Tool` rows. Coming-soon definitions are
excluded. `LandingToolPage.is_active=false` can additionally hide a whole public
family. A missing landing-page record does not disable an otherwise active V2
family. No product table, migration or new action code was introduced.

| Public family / URL | Current products when active |
| --- | --- |
| `tools/tts` | Apollo 1.5, Apollo 2.0, Zeta 1.0 |
| `tools/ctts` | Vector 1.5, Vector 2.0, Theta 1.0 |
| `tools/asr` | Leo, Caption |
| `tools/ocr` | OCR Scanner 2.0 and related Harakat 1.0 Arabic diacritization |
| `tools/stem` | STEM 2, STEM 4 |

Harakat is not OCR. If Scanner is inactive while Harakat remains active, the OCR
family route presents Arabic diacritization without advertising Scanner.
Translation, Delta, NEO, Apollo 1.0 and Vector 1.0 are not current public products.
Legacy application implementations and stored historical data remain intact.
An unknown/inactive family URL returns 404; it is not redirected to an unrelated
product. Individual products currently share their family page.

Public labels and descriptions are curated in `resources/lang/landing/{en,ar,ku}.json`
under `public.*`, through `PublicWebsiteContent`. Internal action codes are only
used to join availability, entitlements and configured examples. They are not
marketing labels or serialized demo action fields.

`LandingToolPageCatalog` retains configured artwork, examples and localized mobile
download links, but public
product facts and SEO copy come from this curated current-content projection.
Old Admin editorial strings remain stored; editing them does not override the new
public product facts. Future Admin work should explicitly manage this ownership
instead of reintroducing arbitrary stale product lists. No Admin editor was built.

`FEATURE_API_V2` / `customer_api.v2_enabled` and `FEATURE_MCP_V2` / `mcp.enabled`
control public integration claims independently. MCP is omitted when disabled.
API is described as unavailable when disabled. These flags were not changed.
Public information is never customer authorization: effective plan, scopes,
per-action entitlements, credits and runtime gates remain separate checks.

## Pricing and metrics

Prices still use `ServicePlan` pricing methods; storage and credit-product prices
keep their existing authorities. No economics, DB rows or payment behavior changed.
Free, Student, Pro and Premium are shown when configured and active. Tool bullets
use active product names and channel-specific `PlanEntitlement` rows, with a
channel-specific deny taking precedence over `all`. API/MCP bullets additionally
require configured API access, recognized V2 scopes and an allowed active action.
App and API credits remain separate. Old editorial usage-equivalent bullets are
not reused; no new seconds-per-credit claims are calculated.

`LandingPublicMetrics` counts completed (`done`) job records, not customers, hours,
page totals or unique source tracks. It performs one grouped aggregate and caches
it for ten minutes. Speech totals include existing legacy speech job kinds plus
current single/batch kinds; transcription includes existing and current kinds.
OCR is honestly labelled **OCR Documents**, because the existing source counts
jobs rather than pages. **Music Separation** counts completed `stem` jobs.
Translation is not a metric. Historical qualifying jobs remain part of these
cumulative counts. No customer text, provider polling or storage probes are used.
The existing failure fallback is zero counts with a sanitized warning; operators
should investigate that warning rather than interpret a sudden zero as data loss.

## Demo contract for future Admin work

Examples stay in existing `LandingToolPage.demo_config` and pass through
`LandingDemoSampleSchema` and `PublicDemoCatalog`. Multiple items and groups are
supported. There is no required display count. A configurable defensive render
cap, `landing.public_demo_limit`, defaults to 60 (clamped to 1–200).

Publication fields on groups/items:

- `product`: current V2 product key, or `action`: exact existing ToolAction code.
  Conflicting explicit identities are rejected. Samples can inherit group identity.
- `title` / `label`, existing source/output media fields and text fields.
- `language`, `sort_order`, `is_active`.
- Family-specific media stay compatible with existing players: `audio` for TTS;
  `source_audio` and `cloned_audio` for CTTS; `audio` and `transcript` for ASR;
  `image` and `extracted_text` for OCR; `stems` for STEM.

Current identity keys are `apollo-1`, `apollo-2`, `zeta-1`, `vector-1`, `vector-2`,
`theta-1`, `leo`, `caption`, `scanner`, `harakat-1`, `2-stem`, `4-stem`.
Harakat currently has no matching public demo renderer: it is deliberately not
rendered as an OCR image extraction example.

Illustrative configuration shape only; these paths are placeholders, not seeded
or published samples:

```json
{
  "type": "ocr",
  "groups": [{
    "product": "scanner",
    "is_active": true,
    "samples": [
      {"title": "Authorized document example", "image": "landing/demos/REPLACE.png", "extracted_text": "REPLACE WITH VERIFIED OUTPUT", "language": "ckb", "sort_order": 10, "is_active": true},
      {"title": "Second authorized example", "image": "landing/demos/REPLACE-2.png", "extracted_text": "REPLACE WITH VERIFIED OUTPUT", "language": "ckb", "sort_order": 20, "is_active": true}
    ]
  }]
}
```

STEM 2 uses `vocals` and `instrumental`; STEM 4 uses `vocals`, `drums`, `bass`,
`other`. Existing media sanitization and preview components remain in use.
Unknown, inactive or wrong-family samples are hidden. The only compatibility
identity mappings are `xomni` → Apollo 1.5 and `clone_xomni` → Vector 1.5.
Legacy audio is never renamed as Apollo 2 or Theta. Public `voice_id` previews
require an active/public Voice with the `xomni` engine and active Apollo 1.5.
There is no automatic fallback loading all public voices. Empty configured groups
stay empty. Operators must supply real authorized media and verified product
identity; no sample audio, OCR output or celebrity clone was fabricated.

## SEO, localization and discovery

- Page-specific titles/descriptions describe current services. Brand images and
  existing Open Graph/Twitter machinery are retained; no large new media assets.
- Canonicals exclude query strings. `/{locale}/home` canonicalizes to `/{locale}`;
  localized equivalents use EN/AR/KU hreflang plus English `x-default`.
- Organization/WebSite/WebPage, breadcrumbs, family software descriptions and
  applicable FAQ structures remain. Visible FAQs and FAQ JSON-LD share one array.
  Unsubstantiated zero-price offers were removed. No fabricated ratings, adoption
  counts or quality rankings were added. JSON-LD uses HTML-safe JSON encoding.
- Public copy is translated into EN/AR/KU; AR/KU use the existing RTL layouts.
  Technical product labels use LTR isolation where mixed with translated copy.
- `PublicDiscoveryController` serves `/sitemap.xml` and `/llms.txt` dynamically
  from the small cached catalog and current flags.
  Public identity now uses `PublicSiteUrl`, not APP_URL (see the follow-up above).
  With all five families active, the sitemap contains 45 public localized routes;
  disabling a family removes its three detail routes.
- Static `public/sitemap.xml` and `public/llms.txt` were removed to prevent the web
  server bypassing Laravel. Deployment must actually remove old physical files
  and refresh any cached discovery documents. Merely copying changed PHP while
  retaining those stale files will not activate the new behavior.
- Sitemap excludes auth, app, Admin, API/MCP and customer artifacts. Private
  surfaces receive noindex controls via middleware/layouts and robots policy.
  `/app/` and `/livewire/` are not blanket-blocked in robots because public pages
  need their CSS/JS assets. Robots are indexing hints, not access controls.
- The published privacy policy states data is not used for model training. Public
  copy attributes that statement to the policy; this is not a new provider audit
  or certification. Do not infer HIPAA/SOC2/GDPR certification or end-to-end encryption.

## Query and cache behavior

The cold active catalog uses two projection queries and has a five-minute shared
cache plus per-request memoization. The index loads only page artwork, not every
demo payload. Detail pages load one configured page's examples and only referenced
public voices. Pricing eagerly loads entitlements; its raw plan cache lasts fifteen
minutes and flag-sensitive formatting happens afterwards.

Observers invalidate public catalog/page/pricing caches on Eloquent Tool,
ToolAction, LandingToolPage and PlanEntitlement changes, including after commit.
Transaction-local catalog reads are not published into the shared cache. Existing
ServicePlan invalidation still applies. Direct SQL/bulk updates bypass model
events; allow TTL expiry or explicitly invalidate caches after an operator-approved
change. No new indexing migration was added: production aggregate query plans and
latency must be evaluated on native MySQL at real data volume.

## Refresh audit and delivery report

1. **Stale content:** the live site/source advertised Translation/TRANS-CKB,
   Delta/NEO, old voice models, conflicting API availability, usage equivalents,
   a fixed uptime claim and obsolete discovery files. The reviewed public pages
   included EN/AR/KU home, pricing and five tool families; one live KU tools-index
   fetch was unavailable. Live production was not modified.
2. **Active source:** V2 definitions plus active Tool/ToolAction, with optional
   whole-family publication disablement; no second product database.
3. **Homepage:** Sorani-first factual positioning, current preview labels, useful
   service descriptions and conditional developer information; design retained.
4. **Stats:** completed STEM jobs replace Translation; OCR job units corrected;
   compact formatting fixed for values such as 100K.
5. **Core products:** five current families, plus the existing-style developer
   card only when API or MCP is enabled; product names reflect active state.
6. **Details:** Apollo/Zeta TTS, Vector/Theta consent-based cloning, Leo/Caption
   transcription/subtitles, Scanner plus distinct Arabic Harakat, and explicit
   STEM 2/4 output descriptions. Existing demo players remain.
7. **Removal:** retired marketing and discovery paths disappear; direct inactive
   routes return 404; no legacy application deletion.
8. **Pricing:** amounts unchanged, Student included on home, permission-derived
   features and separate App/API credits replace stale editorial usage claims.
9. **FAQ/AEO:** localized concrete answers cover dialects, service use, review,
   privacy, storage, credits and enabled integrations; visible/schema agreement.
10. **Titles/descriptions:** distinct current localized metadata for main pages
    and five families; unsupported first/best/accuracy claims removed.
11. **Canonical/hreflang:** localized equivalents retained, duplicate home alias
    consolidated, query strings excluded.
12. **Structured data:** current family capabilities, visible FAQs, safe encoding;
    invented free offers removed, no reviews/ratings fabricated.
13. **llms.txt:** generated active products, Sorani focus, language limitations,
    policy and integration state; no worker identifiers or private endpoint URLs.
14. **Sitemap:** generated routable public pages/locales and only active families.
15. **Demos:** multiple ordered examples bound to real current identities, active
    filtering and preserved players; future Admin editor explicitly deferred.
16. **Performance:** cached projections/aggregates, request reuse, selected columns
    and eager entitlements; no job-history hydration or provider calls.
17. **Languages:** EN/AR/KU copy and metadata, RTL and mixed-label direction.
18. **Verification:** see the result record below; isolated application tests are
    not visual browser, real-storage or production acceptance.
19. **Operator judgment:** choose authorized real demo media and language-specific
    labels; review Arabic/Sorani editorial nuance; confirm deployment flags,
    published policy and canonical production origin. Perform visual responsive/
    audio playback checks, native MySQL aggregate performance checks, and verify
    the host/CDN serves dynamic discovery files after a separately approved release.

### Verification record

Focused PHP coverage includes public catalog/metadata/locales, every sitemap URL,
FAQ parity, active filtering, channel-aware pricing, demo identity and privacy,
Admin landing persistence, media paths, metrics and customer-entry regressions.
PHP tests use isolated SQLite, array cache/session, fake storage and mocked HTTP.
The focused suite passed **103 tests / 1,936 assertions**. All **67 frontend tests**
passed, and `npm run build` succeeded. Focused Pint passed for all 20 changed/new
non-view PHP files; PHP syntax checks passed for 41 PHP/Blade files. EN/AR/KU JSON
parsing and `git diff --check` passed. The final focused demo render run also passed
**4 tests / 25 assertions**, including stable OCR labels across repeated filtering;
associative key ordering is not part of that display contract.

Interactive browser verification was unavailable: the computer-use runtime failed
to initialize with a missing kernel-assets path. No visual or real-media acceptance
is claimed. No deployment, feature activation, application migration or financial
data mutation was performed.
