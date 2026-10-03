# Search Console content refinement — 2026-10-03

## Evidence and scope

User-supplied Search Console baseline: last three months through 2026-09-29,
1,213 clicks / 13,520 impressions / 8.97% CTR / average position 6.85.
Mobile: 863 clicks / 8,890 impressions. These are historical observations supplied
by the owner, not new measurements, forecasts, or public marketing statistics.
The underlying export and query-by-page/device breakdown were not attached here.
No Search Console account, production site, provider or production database access.

| Query | Impressions | Average position |
| --- | ---: | ---: |
| kurdish tts | 820 | 6.53 |
| ai kurdish | 479 | 6.13 |
| kurdish ai | 275 | 7.57 |
| kurdish text to speech | 250 | 7.94 |
| text to speech kurdish sorani | 150 | 6.09 |
| kurdish speech to text | 39 | 8.21 |
| kurdish ocr | 21 | 7.05 |

The query figures are not assumed to belong to a particular page. They support
clearer service descriptions, not a guaranteed ranking or traffic improvement.

## Implemented content

- EN H1: **Kurdish AI for Sorani Speech, Voice, OCR & Audio**. AR/KU have natural
  localized equivalents in the existing gradient H1. No typography/layout CSS
  change. The existing availability-aware opening identifies MetKurd's primary
  Sorani focus and current services; the audience pills remain.
- TTS intro describes Sorani Kurdish text to speech. A distinct explanatory
  section answers how Kurdish TTS works, describes choosing a Kurdish AI voice,
  and distinguishes built-in voices from authorized reference-based cloning.
  Existing `PublicWebsiteContent::tool()` supports `about_title`/`about_copy`, so
  this replaces repetitive introductory text without adding a new page component.
- ASR intro describes Kurdish speech to text; its separate explanation covers
  Kurdish voice to text, recordings, transcript/caption choice and human review.
- EN/AR/KU copy describes supported operations, not accuracy percentages or new
  product capabilities. No global AI-to-SI replacement. Existing canonical,
  reciprocal hreflang, image optimizations and feature gates remain in place.

## Pricing and tools-index CTR

| Page | Impressions | Position | CTR |
| --- | ---: | ---: | ---: |
| /en/pricing | 435 | 2.75 | 0.92% |
| /ku/pricing | 307 | 3.01 | 0.33% |
| /ku/tools | 841 | 6.51 | 3.57% |

Pricing snippets remain unchanged. EN title is `MetKurd AI Pricing | Plans & Credits`;
description: `Compare MetKurd plans, App credits, storage and available developer
access. Choose the plan that fits your Kurdish content and processing needs.`
KU already presents localized plans/credits and the matching comparison description.
The tools-index title already describes Kurdish AI tools. No blind change based
on average position/CTR alone, and no new price, free-usage or unlimited claim.

Before a snippet experiment, filter each exact canonical page in Search Console
and compare queries, language/country, device, search appearance and date. Review
brand versus task/pricing intent and whether Google actually shows the supplied
title/description. After release, compare equivalent windows and device/query
groups with an annotation for this change; do not attribute all CTR movement to
copy. Mobile rendering and the existing performance acceptance remain priorities.

## Super Intelligence terminology

Verified against the White House's [Executive Order 14434, September 29, 2026](https://www.whitehouse.gov/presidential-actions/2026/09/inaugurating-the-era-of-super-intelligence/),
specifically sections 2 and 3. Its executive-branch terminology instruction is not
evidence that MetKurd is superhuman or has AGI, or a requirement to rename MetKurd.

Added a concise translated note next to the homepage FAQ with a direct source
link, one localized FAQ entry and matching FAQPage answer, and a sourced llms.txt
terminology section reusing the English FAQ answer. AR/KU technical labels are
isolated LTR. AI remains the primary product/search terminology. No feature flag,
pricing, billing or service behavior changes.

A separate explainer was considered and deferred: the current supplied search
opportunities concern Kurdish services; the concise sourced FAQ answers the
terminology question without duplicating it across a new editorial page. No new
route, Article schema, invented author/date, or sitemap entry was introduced.

## Retired Translation URLs

Historical owner-provided figures:

| Locale | Impressions | Clicks |
| --- | ---: | ---: |
| AR | 1,028 | 27 |
| EN | 620 | 25 |
| KU | 778 | 20 |

Current source excludes Translation from the V2 public catalog. Local Laravel
requests to `/{en,ar,ku}/tools/translation` return true 404 without a Location
redirect, even when an old LandingToolPage row remains active. They are absent
from sitemap/llms. No historical row or legacy application route is deleted.
The supplied totals do not identify every exact historical URL spelling; the
operator must match the export's page URLs to this inventory before deployment.

Business options (no new retirement decision executed):

1. **Permanent retirement:** keep genuine 404, or intentionally return 410 when
   permanent removal is confirmed. Remove discovery links; retain private history.
   Recommend the existing 404 for the current retired-V2 policy. Do not send users
   to an unrelated homepage/tools page. Historical traffic alone does not justify
   advertising a retired service or promise preservation of rankings.
2. **Temporary retirement:** if there is genuinely useful maintained public content,
   retain a substantive 200 page that clearly says translation is unavailable and
   has no working-service CTA. It must be separately classified from active public
   products. Avoid an empty coming-soon page presented as an available tool.
3. **Planned return:** keep the established locale URLs and useful, accurate
   explanatory content, explicitly state current unavailability, and restore the
   service there when approved. This can retain useful discovery paths but does
   not guarantee search equity. Do not invent a launch date or imply availability.

For any option, inspect exact historical URLs, referring queries and links before
implementing status changes. A truly equivalent future replacement could justify
a reviewed redirect; unrelated redirects are not proposed.

## Changed files in this follow-up

- `resources/lang/landing/en.json`, `ar.json`, `ku.json`
- `resources/views/landing/pages/home.blade.php`
- `app/Support/Landing/PublicWebsiteContent.php` (shared source URL constant)
- `app/Http/Controllers/Landing/PublicDiscoveryController.php`
- `tests/Feature/Landing/PublicWebsiteTest.php`
- This report, `PUBLIC-WEBSITE.md`, and the follow-up note in `PUBLIC-SEO-ACCEPTANCE.md`

Earlier uncommitted SEO changes are preserved. No CSS/JS source or product policy
changed in this follow-up. No deployment or migrations.

## Verification

- 82 focused public-site PHP tests passed / 4,045 assertions, using explicit
  testing environment, SQLite `:memory:`, array cache/session, fake storage and
  mocked HTTP. Covers localized H1/copy, source citation, visible/JSON-LD FAQ
  parity, llms terminology, existing metadata and all three retired Translation URLs.
- Vite production build passed; focused Pint and `git diff --check` passed.
- Rendered EN/AR/KU fixture pages inspected at 390px with existing production
  CSS/JS: no horizontal overflow; AR/KU retained RTL. This was an isolated
  loopback preview with external images/analytics/application calls blocked,
  not production or live performance acceptance.
- Canonical/hreflang/image source from the preceding audit remains unchanged.

```text
php vendor/bin/pest tests/Feature/Landing/PublicWebsiteTest.php --compact
npm run build
php vendor/bin/pint --test app/Support/Landing/PublicWebsiteContent.php app/Http/Controllers/Landing/PublicDiscoveryController.php tests/Feature/Landing/PublicWebsiteTest.php
git diff --check
```

