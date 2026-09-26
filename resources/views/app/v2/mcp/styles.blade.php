<style>
    .v2-mcp { --mcp-border: rgba(128,140,160,.2); padding: 24px; max-width: 1600px; margin: auto; }
    .v2-mcp .api-header { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-bottom: 28px; }
    .v2-mcp .api-header h1 { font-size: 30px; font-weight: 700; margin: 6px 0 10px; }
    .v2-mcp .api-eyebrow { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--bs-secondary-color); }
    .v2-mcp .api-header p { margin: 0; max-width: 680px; color: var(--bs-secondary-color); }
    .v2-mcp .api-grid { display: grid; grid-template-columns: 200px minmax(0,1fr); border: 1px solid var(--mcp-border); border-radius: 16px; overflow: hidden; background: var(--bs-body-bg); }
    .v2-mcp .api-nav { padding: 16px 10px; border-inline-end: 1px solid var(--mcp-border); grid-row: auto; }
    .v2-mcp .api-nav button { display: block; width: 100%; border: 0; background: transparent; color: inherit; text-align: start; border-radius: 8px; padding: 10px 12px; font-size: 13px; }
    .v2-mcp .api-nav button:hover, .v2-mcp .api-nav button.is-active { background: rgba(105,108,255,.1); color: var(--bs-primary); }
    .v2-mcp .api-main { padding: 28px; min-width: 0; }
    .v2-mcp .api-main h2 { font-size: 23px; margin-bottom: 18px; }
    .v2-mcp .api-main h3 { font-size: 18px; margin-top: 24px; }
    .v2-mcp .api-main h4 { font-size: 15px; }
    .v2-mcp .api-main p, .v2-mcp .api-main li { font-size: 14px; line-height: 1.75; }
    .v2-mcp .api-main pre { overflow: auto; padding: 16px; background: rgba(128,140,160,.08); border-radius: 8px; }
    .v2-mcp .api-main code { overflow-wrap: anywhere; }
    .v2-mcp .api-card { padding: 18px; border: 1px solid var(--mcp-border); border-radius: 12px; }
    .v2-mcp .api-card h3 { margin-top: 0; }
    .v2-mcp blockquote { border-inline-start: 3px solid var(--bs-primary); padding-inline-start: 16px; }
    .v2-mcp .api-table { overflow-x: auto; }
    .v2-mcp table { width: 100%; border-collapse: collapse; }
    .v2-mcp th, .v2-mcp td { padding: 12px; text-align: start; vertical-align: top; border-bottom: 1px solid var(--mcp-border); }
    .v2-mcp .api-main dt { font-size: 13px; color: var(--bs-secondary-color); }
    .v2-mcp .api-main dd { font-size: 16px; margin-bottom: 18px; }
    .v2-mcp .api-mobile-nav { display: none; }
    @media (max-width: 760px) {
        .v2-mcp { padding: 14px; }
        .v2-mcp .api-header { align-items: flex-start; flex-direction: column; gap: 16px; }
        .v2-mcp .api-header h1 { font-size: 26px; }
        .v2-mcp .api-grid { display: block; }
        .v2-mcp .api-nav { display: none; }
        .v2-mcp .api-main { padding: 18px; }
        .v2-mcp .api-mobile-nav { display: block; margin-bottom: 15px; }
        .v2-mcp .api-mobile-nav label { display: block; font-size: 12px; margin-bottom: 6px; }
    }
</style>
