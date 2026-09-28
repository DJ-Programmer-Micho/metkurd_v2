# Admin services, access and pricing — Phase 3

Implemented locally on 2026-09-27. No deployment, application migrations, seeders,
economic-data changes or rollout changes were performed. Phase 1 navigation and
Phase 2 customer workflows remain the foundation.

## Services and public identity

Services / Tools starts with current V2 products grouped by service family.
For a selected plan it shows the product, human-readable action, metric, effective
service state, separate App/API access, matching-price status and relevant limits.
The current-product projection comes from the existing V2/API catalogs; legacy
registry filtering, usage statistics and editors remain under Technical reference.
Raw action identities and advanced configuration stay there.

Active service and Public Landing visible are distinct. AdminServiceWorkspace uses
PublicProductCatalog.products() for publication status. A disabled parent Tool,
disabled action or disabled family Landing page hides the affected product.
A retired action is Legacy / Not Public regardless of an old editorial row.
The registry uses a neutral legacy badge rather than an active-service badge.
No historical record is deleted or reactivated.

The overview links to the existing Landing pages/examples editor; that editor links
back to service controls and explains the combined publication requirements.
No Landing layout, demo contract or public route changes.

## Entitlements and limits

The App/API matrix shows current products against existing plans, distinguishing
allow, explicit deny, missing grant and inherited all-channel decisions. Plan/action
filters also scope the matrix; detailed record filters and legacy/mobile/all-channel
records remain available separately. Customer overrides and agreements are explicitly
outside the plan-default preview.

Effective access is resolved through the existing unsaved AdminV2Catalog customer
preview and Customer::isAllowed. Provenance identifies the channel-specific row
or existing all-channel fallback; it does not introduce another access authority.
An inactive service still blocks an allow decision. API submission additionally
requires its existing plan configuration, scopes, platform gates, account, consent,
credit and input checks.

Matrix Edit opens the existing modal for the selected plan/action/channel. If no
channel-specific row exists, it prepares a new explicit decision, initially denied.
It never silently edits an inherited all-channel row. Nothing is saved until the
existing reason, confirmation, validation and audited mutation run.

Apollo/Vector character limits still use PlanEntitlement.limits.max_chars_per_submit
and are displayed through InputBoundary::characterLimit, including the unchanged
400-character fallback. Existing customer override and channel fallback behavior
is unchanged. The editor preserves other existing limit JSON. Zeta/Theta, OCR and
Harakat shared limits remain read-only references, not new plan-level controls.
No per-plan duration limit or worker-envelope authority was invented.

## Pricing, plans and voices

Pricing uses product names with technical identities disclosed separately. App and
API price columns remain separate; MCP uses API prices. The existing grouped editor
still reviews App, API and legacy Mobile prices together. Metric, priority, rounding,
minimums, rule grouping, temporal conditions and all-channel fallback are preserved.
The service overview's price status is a resolved sample match, not a claim that all
possible requests have the same price.

Plan rows retain monthly App/API allowances, prices and existing controls. Their
expandable service summary adds enabled families and per-channel speech limits.
API settings and App concurrency display existing runtime configuration/resolvers.
Storage is explained as a separate customer storage subscription, not an invented
service-plan allowance. Customer agreement overrides do not modify this preview.

Voice rows show public voice code, engine/family, visibility, catalog plan names
and configured preview status. Omni plan availability uses OmniSpeakerCatalog,
including public voices, explicit active grants and reference-safety rules. Service
entitlement remains an additional requirement. Other engines show active plan
assignments rather than claiming V2 availability. Preview status does not probe
file existence or expose reference paths. Voice and plan-access mutations retain
their existing catalog cache-version invalidation.

## Editing, validation and lifecycle

Existing forms, methods, field bindings, capabilities, reason requirements, audit
observers and pricing/entitlement mutations remain. The only new editor action
prefills a matrix cell; it does not save data. Form review annotations include the
existing visible fields in the shared confirmation. Plan activation now uses that
same confirmation/reason bridge instead of a direct checkbox call.

The five service/plan pages no longer install their own persistent modal and
navigation listeners. Their existing named show/hide events are handled by the
shared Admin bridge. On navigation, the bridge disposes modals and clears open
dialog state before history snapshots. No additional navigation owner is introduced.
Modal validation summaries, inline errors and sanitized permission/operation
failures remain visible.

## Verification and remaining acceptance

Focused tests cover current/legacy identity, public visibility, per-channel allow/
deny/fallback, runtime character limits, inherited-row preservation, capability/
reason/audit behavior, price validation, plan projections, voice catalog access and
cache invalidation. EN/AR/KU HTTP rendering compares catalog/economic snapshots to
verify reads do not alter records. Existing service/pricing/consumer tests exercise
actual editor mutations in isolated SQLite with mocked external services.

Final results: **177 distinct PHP cases passed across focused runs**. The service
run passed 52 cases (AdminServiceWorkspace, AdminP1Correctness,
AdminServicePricingTableGrouping and AdminConsumerConnections). The safety run
covered 134 cases including nine repeated workspace cases: AdminP0Safety,
AdminP3Ui, AdminUsability, LandingToolVisibility, AdminPaymentPlansCredits and
AdminPlanDeletion. Its sole failure was the retired plan-label expectation; after
updating that presentation assertion, the affected test passed with its actual
App/API balance assertions retained. Initial component-scope and localization
issues were fixed before these accepted results.

**31 frontend tests passed**, the final Vite build passed, and focused syntax,
seven-file Pint and whitespace checks passed. This is targeted Admin coverage,
not a full repository run. Source comparison against the Phase 3 starting snapshot
confirmed unchanged domain models, pricing/access/billing authorities, public
catalog, routes and V2 configuration. Existing mutation method names and form
bindings remain; the new matrix prefill and shared modal lifecycle are UI changes.

Frontend tests cover localization parity, shared modal events, navigation cleanup,
reason handling and the existing shell/customer lifecycle. Responsive tables retain
horizontal scrolling; cards and controls wrap, and mixed identifiers use directional
isolation. There are no changes to the Phase 1 drawer or header geometry.

Browser automation could not initialize because its kernel assets were unavailable.
Interactive desktop/tablet/mobile and EN/AR/KU visual acceptance remain pending;
static layout checks and HTTP rendering are not a substitute. Native MySQL and live
deployment acceptance were not performed.

For Phase 4, first complete interactive acceptance of Phases 1–3, then scope the
remaining operational review queues and evidence navigation. Keep any financial
policy change, legacy deletion or billing redesign explicitly separate.
