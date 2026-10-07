# Canonical legal pages — 2026-10-07

The public legal documents are `/{locale}/privacy` and `/{locale}/terms`, for
`en`, `ar` and `ku`. The existing Livewire landing pages and public layout render
them. Canonical URLs, hreflang and route names remain unchanged.

## Content authority

`resources/lang/landing/{en,ar,ku}.json` owns `privacy_page.*` and `terms_page.*`.
English is the default content and date authority; AR/KU provide localized copy.
`LandingContent` reads these sources for raw legal defaults and its editable
translation catalog as well as normal page rendering. There is no separate PHP
legal-body constant to drift from the translations. Dates are displayed as
`Last updated: 2026-10-07` with localized labels, not a claim of deployment.

All 13 detailed Privacy sections and existing metadata/introductory text were
preserved exactly in each locale. The original Terms provisions were restored
in 17 localized sections: operator/agreement, services, account security,
acceptable use, output review, ownership/commercial use, data/storage/privacy,
case studies, billing, refunds, API/MCP use, availability, liability, termination,
changes, Iraqi governing law and contact. Obsolete translation-tool advertising,
blanket private-access assurances and universal recurring-renewal wording were
updated to current service/access and Privacy descriptions. Applicable-law
qualifications accompany the existing refund and liability provisions.

## Legacy routes and historical evidence

`LawController` remains only as a redirect adapter for named routes `law.privacy`
(`/law/privacy-policy`) and `law.terms` (`/law/terms-conditions`). It selects an
allowlisted session/default locale, falling back to English, with a private,
non-cacheable 302 because the destination depends on the visitor's locale.
Signup now links directly to the localized canonical routes.

Before archiving, a source-reference scan confirmed that no route, controller,
view, config or bootstrap code still read the exported HTML documents or used
their wrapper views. The exports were moved unchanged outside the web root:

- [Original Privacy, effective 2026-04-12](legal-history/METKURDPrivacy.html)
- [Original Terms, effective 2026-04-14](legal-history/METKURDTermcondition.html)

These are historical records, not current policy or runtime content. Direct
static-file URLs are retired; the supported localized and legacy named legal
routes remain. The two unused wrapper views were removed. `landing/law/clean`
remains a shared/tested layout but supplies no legal document content.

No API/MCP behavior, billing rules, plugin manifest or production configuration
was changed, and no deployment was performed. Focused tests cover legal route
rendering, redirects, signup links, content/catalog parity, dates and EN/AR/KU
direction and metadata.

Local verification: 19 legal route/render/catalog tests passed (2,434 assertions),
21 focused public metadata/localization render tests passed (2,639 assertions),
and one isolated translation-editor localization test passed (10 assertions).
All tests used SQLite in memory, fake storage and blocked/mocked outbound HTTP.
The 32 existing Privacy values per locale were compared with the pre-change Git
source and remained identical. Archive hashes were checked during the move.
No production or interactive browser acceptance is claimed.
