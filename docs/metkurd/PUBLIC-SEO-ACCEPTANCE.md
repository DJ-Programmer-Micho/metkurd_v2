# Public SEO / AEO / GEO acceptance — 2026-10-03

Source review and isolated local verification only. Production, Cloudflare and
remote storage were not accessed. No deployment, configuration change or migration.

Follow-up: [Search Console content refinement](PUBLIC-SEARCH-BASELINE.md) changes
the visible H1, TTS/ASR explanation and adds sourced SI terminology. The byte
measurements below are the earlier optimization checkpoint, not measurements of
the subsequently expanded copy. Canonical/hreflang/image work remains in place.

## Findings and source changes

- Metadata previously used the request origin; sitemap/llms used APP_URL instead.
  `PublicSiteUrl` now supplies the public identity `https://metkurd.ai` consistently
  for canonical, hreflang, Open Graph URL, local social images and schema URLs.
  Deliberately configured external media/CDN URLs remain external. Public metadata
  alone uses this origin: application links, signed URLs, auth and feature flags
  are unchanged. Local previews consequently advertise the production identity;
  non-production deployments must remain private/noindex at their ingress.
- Removed duplicated homepage-alias handling. Named localized routes own their
  canonicals. `/en/home` still renders with `/en` canonical for compatibility.
- Added 301 filename-alias routes to `/`, retaining safe query parameters. Root
  remains the existing temporary/session-aware locale and authenticated-app entry.
  Nginx may consume `/index.php` before Laravel; ingress acceptance is mandatory.
- Homepage descriptions no longer expand the full active-product entity statement.
  EN/AR/KU descriptions are 153/157/158 Unicode characters with current core tools.
  When a required family is unavailable, a translated generic description avoids
  advertising disabled tools. Titles are unchanged in meaning; HTML entities in
  Blade slots are decoded before escaping to prevent a literal `&amp;` in titles.
- Existing opening content already identifies Sorani focus, services and audience.
  Corrected mixed English/machine-like AR/KU hero badge and focus pill wording.
  No section redesign, new metrics, superlatives, or invented research claims.
- Added image dimensions to homepage logos, footer QR images and existing fixed
  square/16:9 product-card slots. Fixed-role dimensions describe the existing CSS
  display ratio, not a claim about remote source-file resolution.

## Canonical and hreflang matrix

For each public route suffix (empty for home, `/pricing`, `/tools/tts`, etc.):

| Page locale | Canonical | en alternate | ar alternate | ku alternate | x-default |
| --- | --- | --- | --- | --- | --- |
| EN | /en + suffix | /en + suffix | /ar + suffix | /ku + suffix | /en + suffix |
| AR | /ar + suffix | /en + suffix | /ar + suffix | /ku + suffix | /en + suffix |
| KU | /ku + suffix | /en + suffix | /ar + suffix | /ku + suffix | /en + suffix |

All above paths use `https://metkurd.ai`. EN/AR/KU are retained as the existing
public locale contract; worker language `ckb` is a separate concern. Tests render
all 45 current public pages as HTTP 200 inside Laravel, including self references
and reciprocal alternates. This does not establish production HTTP status.

## Homepage descriptions

- EN: MetKurd AI offers Sorani Kurdish text-to-speech, voice cloning, speech-to-text and OCR: Kurdish-first tools for creators, educators and everyday content.
- AR: ميت كورد منصة ذكاء اصطناعي للكردية السورانية: تحويل النص إلى كلام واستنساخ الصوت وتفريغ التسجيلات واستخراج النصوص من الصور، لصنّاع المحتوى والتعليم والأعمال.
- KU: مێتکورد ئامرازەکانی زیرەکی دەستکرد بۆ کوردیی سۆرانی پێشکەش دەکات: دەق بۆ دەنگ، لاساییکردنەوەی دەنگ، دەنگ بۆ دەق و دەرهێنانی دەق لە وێنە، بۆ ناوەڕۆک و فێرکردن.

## Social, schema and machine discovery

Open Graph and Twitter already existed; their supposed absence is not reproduced
by the rendered local responses. One title/description/image per social platform,
one OG URL/type and one canonical are tested. Homepage social descriptions match
the normal description. Configured media files still require public HTTP acceptance.

Retained Organization, WebSite, WebPage, BreadcrumbList, FAQPage and family-page
SoftwareApplication. No schema types added or removed. Organization and WebSite
identities now share the canonical origin. FAQs and FAQPage use the same visible
array, including independently gated MCP copy. No HowTo, ratings or speculative
Product/Offer additions. Syntactically valid schema is not a promise of rich results.

Robots allows public rendering assets; private App/Admin/API/MCP/OAuth surfaces
remain excluded. Sitemap contains 45 current localized public URLs with all five
families active, and no machine/auth/private routes. `llms.txt` still reflects the
active catalog and API/MCP flags. No fabricated lastmod: the pages combine curated
copy, config and catalog data, so a single trustworthy update time is unavailable.
Do not add static sitemap/llms files over their dynamic Laravel routes.

## Images and assets

| Image | Original bytes | Delivered WebP bytes | Dimensions |
| --- | ---: | ---: | --- |
| Black logo | 163,851 | 980 / 2,304 | 44×40 / 88×80, 22px CSS slot |
| White logo | 149,922 | 992 / 2,294 | 44×40 / 88×80, 22px CSS slot |
| Telegram QR | 145,912 | 49,122 | 512×512, unchanged pixels |
| Google review QR | 4,394 | 726 | 132×132, unchanged pixels |

Both logo variants are declared via srcset/sizes. Original artwork remains intact.
Original unique assets total 464,079 bytes; typical 44px variants total 51,820,
or 54,446 with 88px logos (about 88% reduction). This is asset-file size, not a
measured production waterfall; hidden theme images and browser cache affect actual
requests. Pixel comparison proved lossless QR conversion. Footer images are lazy;
navigation logos remain eager. `scripts/optimize-landing-images.py` reproduces the
committed derivatives with local Pillow; no production imaging dependency added.

The homepage hero is HTML/CSS, not an image. No speculative LCP preload added.
Admin product artwork uses shared-storage paths/URLs and original uploads; those
files were not available in this checkout and were not fetched. Their byte sizes,
responsive conversion and detail/demo image intrinsic dimensions remain an operator
acceptance/follow-up item. Existing square and cover cards retain their layout.
Do not claim that these local logo savings resolve all reported mobile LCP costs.

The production build emits landing CSS 35.39 kB (gzip 7.47 kB) and JS 8.06 kB
(gzip 2.58 kB). Shared Bootstrap CSS is 282,343 bytes and its JS is 80,421 bytes.
Bootstrap utilities, navigation, Livewire components/navigation, theme handling,
pricing and demo controls have real consumers. WaveSurfer initialization exits on
pages without players. No duplicate Vite app/admin bundle is loaded on landing.
No Google text-font import; Segoe UI/Arial/system fallback remains. Bootstrap Icons
is an external render-blocking icon stylesheet. Analytics is async. No package
removal or speculative CSS purge without browser coverage of all landing states;
no vendor/framework manual minification. Production coverage/waterfall profiling
is still needed to quantify unused CSS and external icon latency.

## HTML measurement and verification scope

Measurements use actual Laravel-rendered anonymous GET responses with isolated
SQLite, all current tools active, API/MCP disabled, no configured demos/plans/media,
array caches/sessions and fake disks. They are reproducible source fixtures, not a
measurement of the populated production database. `LANDING_MEASURE=before|after`
on the measurement test writes ignored local HTML under
`storage/framework/testing/landing-seo/`. Gzip level 6 is simulated, not transferred
bytes from the deployed ingress. Session/Livewire tokens slightly vary gzip sizes.

| Locale | Raw before | Raw after | Gzip-6 before | Gzip-6 after |
| --- | ---: | ---: | ---: | ---: |
| EN | 80,263 | 80,852 | 12,141 | 12,342 |
| AR | 90,897 | 91,310 | 13,966 | 14,192 |
| KU | 94,684 | 94,844 | 14,081 | 14,318 |

No embedded base64 image data. Initial EN JSON-LD totaled 8,772 bytes, with ordinary
Livewire snapshots and small theme/analytics scripts; no multi-megabyte inline
blob was reproduced. Around 10 KB could describe compressed content; around
100 KB is plausible raw localized HTML. The 2.86 MB claim remains unverified and
may concern another page or total resources. No 5-second LCP claim was independently
confirmed. Slight markup growth from responsive attributes is intentional.

Browser checks used these isolated HTML fixtures with real local production CSS/JS
at loopback, CSP blocking remote images, analytics and application network calls.
EN/AR/KU desktop layouts and KU at 390px had no horizontal overflow; RTL and logo
appearance were inspected. This is presentation verification, not a populated CMS,
Livewire interaction, production latency or full mobile performance acceptance.

Verification: 156 focused PHP tests / 4,308 assertions passed across Landing,
CustomerAppEntry and CustomerAppDestination. Fourteen frontend navigation/account
tests passed. Vite production build, focused Pint and whitespace checks passed.
Tests cover all 45 locale routes, reciprocal/self/x-default tags, canonical origin,
query exclusion, unique social tags, JSON-LD, visible/schema FAQ parity, MCP gates,
short localized descriptions including disabled-family fallback, image dimensions,
filename alias redirects, root locale negotiation and existing customer entry rules.

Commands used with explicit testing / SQLite `:memory:` / array cache-session:

```text
php vendor/bin/pest tests/Feature/Landing tests/Feature/Auth/CustomerAppEntryTest.php tests/Unit/CustomerAppDestinationTest.php --compact
node --test tests/Frontend/v2-navigation.test.mjs tests/Frontend/v2-account.test.mjs
npm run build
```


## Required ingress acceptance — operator only, not executed

No authoritative deployed Nginx/Cloudflare configuration was supplied. Therefore
no specific missing live rule or WAF challenge is established. First inspect current
rules; retain equivalent working rules instead of duplicating them.

If Cloudflare already proxies these public hostnames, it is the preferred earliest
layer. Proposed Single Redirect rules, **only if equivalent rules are absent**:

1. Match `(http.host in {"metkurd.ai" "www.metkurd.ai"}) and
   (http.request.uri.path in {"/index.html" "/index.htm" "/index.php"})`.
   Static target `https://metkurd.ai/`, status 308, preserve query string enabled.
2. Match `(http.host in {"metkurd.ai" "www.metkurd.ai"}) and
   ((http.host eq "www.metkurd.ai") or (http.request.scheme eq "http"))`.
   Dynamic target `concat("https://metkurd.ai", http.request.uri.path)`, status 308,
   preserve query string enabled. Put alias normalization first.

These use a fixed host and do not trust a query-supplied destination. 308 preserves
methods. Keep canonical `/` locale negotiation temporary and session-aware; never
edge-cache its authenticated/session-specific redirect. Do not add host/scheme
redirects based on untrusted forwarded headers in Laravel.

Cloudflare's [Single Redirect settings](https://developers.cloudflare.com/rules/url-forwarding/single-redirects/settings/)
and [domain redirect example](https://developers.cloudflare.com/fundamentals/manage-domains/redirect-domain/)
document dynamic targets and query preservation. Confirm DNS proxying and TLS for
both names. Do not stack contradictory Page Rules/Workers/Always Use HTTPS rules.

If Nginx owns redirects instead, use the fixed canonical host in dedicated HTTP
and TLS-www server blocks: `return 308 https://metkurd.ai$request_uri;`.
In the canonical server, direct alias requests can use this guarded server-level
return before existing front-controller handling:

```nginx
if ($request_uri ~ "^/index\.(html|htm|php)(\?|$)") {
    return 308 https://metkurd.ai/$is_args$args;
}
```

Use original `$request_uri`, not rewritten `$uri`, so internal `/index.php`
front-controller rewrites do not loop. See [Nginx variable reference](https://nginx.org/en/docs/http/ngx_http_core_module.html#variables).
This is a snippet for operator integration, not a complete vhost replacement.
Behind TLS termination do not redirect every HTTP origin request based on `$scheme`:
review the actual trusted proxy topology first. Application trusted-proxy configuration
was inspected but not changed; operator must verify it matches that topology.

Acceptance matrix (GET and HEAD, no login cookies, then localized session checks):

- `https://metkurd.ai/en`, `/ar`, `/ku`: 200, no intermediate redirect, one
  self canonical and reciprocal EN/AR/KU/x-default tags; review full response HTML.
- `https://www.metkurd.ai`, `http://metkurd.ai`, `http://www.metkurd.ai`, each with
  `/ar/tools/tts?utm_source=acceptance`: permanent redirect to the same canonical
  path/query, then 200; no loop. Test an encoded safe query too.
- `/index.html`, `/index.htm`, `/index.php`: permanent alias normalization, then
  existing temporary locale negotiation; no separate indexable homepage body.
- `/`: preserve remembered EN/AR/KU and customer/Admin routing. No permanent
  session-dependent redirect. `/en/home` remains compatibility content with `/en`
  canonical and is absent from sitemap.
- Validate all sitemap destinations, social images, CSS/JS/fonts and configured
  product artwork return 200 with correct MIME, caching and compression. Confirm
  old physical `sitemap.xml`/`llms.txt` do not shadow Laravel.
- Compare ordinary browser and legitimate crawler responses. Investigate specific
  Cloudflare Security Events/rule IDs if challenged; user-agent spoofing alone
  does not prove bot legitimacy. Any exception must be restricted to verified
  crawlers/public GET/HEAD paths and the offending rule. Keep private routes,
  Turnstile, authentication and general WAF policy intact.
- Capture a mobile waterfall/LCP candidate on the populated site, cold and warm
  cache, with documented device/network and compression. Audit shared-storage
  artwork before claiming image/LCP acceptance. Verify no public full-page cache
  leaks authenticated navigation or locale sessions.

No production verification commands were run by this review.

## Changed files

- `app/Http/Controllers/Landing/PublicDiscoveryController.php`
- `app/Support/Landing/PublicSiteUrl.php`
- `app/Support/Landing/PublicWebsiteContent.php`
- `app/Support/LandingContent.php`
- `docs/metkurd/CHANGELOG.md`
- `docs/metkurd/PUBLIC-SEO-ACCEPTANCE.md`
- `docs/metkurd/PUBLIC-WEBSITE.md`
- `public/landing/images/black_logo-44.webp`
- `public/landing/images/black_logo-88.webp`
- `public/landing/images/google_review.webp`
- `public/landing/images/qr_tele.webp`
- `public/landing/images/white_logo-44.webp`
- `public/landing/images/white_logo-88.webp`
- `resources/lang/landing/ar.json`
- `resources/lang/landing/en.json`
- `resources/lang/landing/ku.json`
- `resources/views/landing/components/footer.blade.php`
- `resources/views/landing/components/navigation.blade.php`
- `resources/views/landing/components/tool-grid.blade.php`
- `resources/views/landing/law/clean.blade.php`
- `resources/views/landing/layouts/app.blade.php`
- `resources/views/landing/pages/contact.blade.php`
- `resources/views/landing/pages/home.blade.php`
- `resources/views/landing/pages/how-built.blade.php`
- `resources/views/landing/pages/kurdish-ai-challenges.blade.php`
- `resources/views/landing/pages/overview.blade.php`
- `resources/views/landing/pages/pricing.blade.php`
- `resources/views/landing/pages/privacy.blade.php`
- `resources/views/landing/pages/research-development.blade.php`
- `resources/views/landing/pages/terms.blade.php`
- `resources/views/landing/pages/tool-detail.blade.php`
- `resources/views/landing/pages/tools.blade.php`
- `routes/landing.php`
- `scripts/optimize-landing-images.py`
- `tests/Feature/Landing/PublicWebsiteTest.php`
