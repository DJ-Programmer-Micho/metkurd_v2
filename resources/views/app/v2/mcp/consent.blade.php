<!doctype html>
<html class="metkurd-v2-typography" lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar', 'ku']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('mcp.connect') }}</title>
    @vite('resources/css/v2-typography.css')
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f8; color: #202431; font-family: "Segoe UI", Arial, sans-serif; font-size: 15px; line-height: 1.6; }
        .consent-shell { width: min(100% - 32px, 760px); margin: 40px auto; }
        .consent-brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 24px; color: #252637; font-size: 20px; font-weight: 700; }
        .consent-mark { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 11px; background: #6350cf; color: #fff; }
        .consent-card { overflow: hidden; background: #fff; border: 1px solid #e0e3ec; border-radius: 20px; box-shadow: 0 12px 40px #23283c0a; }
        .consent-header, .consent-body, .consent-footer { padding: 28px 32px; }
        .consent-header { border-bottom: 1px solid #e7e9f0; }
        h1 { margin: 0 0 10px; font-size: clamp(26px, 4vw, 32px); line-height: 1.25; letter-spacing: -.025em; }
        h2 { margin: 0 0 16px; font-size: 17px; line-height: 1.4; }
        p { margin: 0; }
        .consent-intro, .consent-label { color: #596174; }
        .consent-client { margin-top: 24px; padding: 16px 18px; border: 1px solid #e7e9f0; border-radius: 12px; background: #f8f9fc; min-width: 0; }
        .consent-label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 3px; }
        .consent-client-name { display: block; font-size: 18px; overflow-wrap: anywhere; }
        .consent-client-id { margin-top: 12px; }
        code { font-family: Consolas, monospace; font-size: 12px; color: #525c70; overflow-wrap: anywhere; }
        .consent-client-id code { display: block; text-align: start; }
        .consent-scopes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; list-style: none; padding: 0; margin: 0; }
        .consent-scopes li { min-width: 0; padding: 14px 16px; border: 1px solid #e4e7ef; border-radius: 10px; font-size: 14px; }
        .consent-scopes code { display: block; width: fit-content; max-width: 100%; margin-top: 7px; padding: 2px 6px; border-radius: 4px; background: #f0f1f7; font-size: 11px; }
        .consent-credits { margin-top: 24px; padding: 18px 20px; border-inline-start: 3px solid #8170dc; border-radius: 0 8px 8px 0; background: #f5f3fc; color: #45405e; font-size: 13px; }
        [dir="rtl"] .consent-credits { border-radius: 8px 0 0 8px; }
        .consent-credits h2 { font-size: 14px; margin-bottom: 6px; color: #342a58; }
        .consent-footer { border-top: 1px solid #e7e9f0; background: #fbfbfd; }
        .consent-actions { display: flex; gap: 12px; }
        .consent-button { min-height: 48px; padding: 11px 24px; border: 1px solid #cfd3df; border-radius: 9px; background: #fff; color: #363d4e; font: inherit; font-weight: 600; cursor: pointer; }
        .consent-approve { flex: 1; background: #6350cf; border-color: #6350cf; color: #fff; }
        .consent-approve:hover { background: #5140b5; border-color: #5140b5; }
        .consent-deny:hover { background: #f0f1f7; }
        .consent-button:focus-visible { outline: 3px solid #37277f; outline-offset: 3px; }
        @media (max-width: 540px) {
            .consent-shell { width: min(100% - 24px, 760px); margin: 24px auto; }
            .consent-header, .consent-body, .consent-footer { padding: 22px 20px; }
            .consent-scopes { grid-template-columns: 1fr; }
            .consent-actions { flex-direction: column; }
            .consent-button { width: 100%; }
        }
    </style>
</head>
<body>
<main class="consent-shell">
    <div class="consent-brand" dir="ltr">
        <span class="consent-mark" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M4 18V6l8 8 8-8v12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <span>MetKurd</span>
    </div>
    <section class="consent-card" aria-labelledby="consent-title">
        <header class="consent-header">
            <h1 id="consent-title">{{ __('mcp.connect') }}</h1>
            <p class="consent-intro">{{ __('mcp.consent') }}</p>
            <div class="consent-client">
                <span class="consent-label">{{ __('mcp.requesting_app') }}</span>
                <strong class="consent-client-name" dir="auto">{{ $client->name }}</strong>
                @if($client->mcp_metadata_hash)
                    <div class="consent-client-id">
                        <span class="consent-label">{{ __('mcp.client_identity') }}</span>
                        <code dir="ltr">{{ $client->id }}</code>
                    </div>
                @endif
            </div>
        </header>
        <div class="consent-body">
            <h2>{{ __('mcp.permissions') }}</h2>
            @php($scopeTools = ['v2:speech' => 'speak', 'v2:voice-clone' => 'clone_voice', 'v2:transcriptions' => 'transcribe', 'v2:captions' => 'caption', 'v2:ocr' => 'ocr', 'v2:stem' => 'stem', 'v2:harakat' => 'harakat', 'v2:jobs:read' => 'get_job', 'v2:files:download' => 'list_recent_files', 'mcp:uploads' => 'create_upload_session'])
            <ul class="consent-scopes">
                @foreach($scopes as $scope)
                    <li><span>{{ __('mcp.tool_'.$scopeTools[$scope->id]) }}</span><code dir="ltr">{{ $scope->id }}</code></li>
                @endforeach
            </ul>
            <aside class="consent-credits" aria-labelledby="credits-title">
                <h2 id="credits-title">{{ __('mcp.credits_title') }}</h2>
                <p>{{ __('mcp.credits') }}</p>
            </aside>
        </div>
        <footer class="consent-footer">
            <form class="consent-actions" method="post" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="consent-button consent-approve" name="decision" value="approve">{{ __('mcp.approve') }}</button>
                <button type="submit" class="consent-button consent-deny" name="decision" value="deny">{{ __('mcp.deny') }}</button>
            </form>
        </footer>
    </section>
</main>
</body>
</html>
