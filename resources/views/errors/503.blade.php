@php
    // Local files only. Bake every locale and the logo into --render's single document.
    $copy = json_decode(file_get_contents(resource_path('lang/maintenance.json')), true, 512, JSON_THROW_ON_ERROR);
    $logo = base64_encode(file_get_contents(public_path('app/logo/white_logo_xml/144.png')));
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr" class="landing-maintenance">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#07111f">
    <title>Scheduled maintenance | MetKurd AI</title>
    <script>
        // Runs for the visitor's URL, never for the URL present during artisan down.
        (() => {
            const parts = window.location.pathname.split('/').filter(Boolean);
            const locale = ['en', 'ar', 'ku'].includes(parts[0]) ? parts.shift() : 'en';
            const html = document.documentElement;
            html.lang = locale;
            html.dir = locale === 'en' ? 'ltr' : 'rtl';
            html.className = ['app', 'app-v2', 'adm'].includes(parts[0]) ? 'app-maintenance' : 'landing-maintenance';
        })();
    </script>
    <style>
        :root{color-scheme:dark;--bg:#07111f;--text:#eaf2ff;--muted:#9cb0d3;--accent:#a99bff;--line:rgba(170,186,218,.18)}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;min-height:100svh;color:var(--text);font-family:Inter,system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;background:radial-gradient(ellipse at 12% 15%,#7c5cff20,transparent 45%),radial-gradient(ellipse at 88% 60%,#06d6ff12,transparent 45%),var(--bg);display:flex;flex-direction:column}
        .brand{display:flex;align-items:center;gap:12px;direction:ltr;font-size:18px;font-weight:650;letter-spacing:-.4px}.brand img{width:42px;height:42px;object-fit:contain}.header{width:min(1200px,100%);margin:auto auto 0;padding:32px 40px;display:flex;justify-content:space-between;align-items:center}.header small{color:var(--muted);font-size:12px;letter-spacing:.16em;direction:ltr}
        main{flex:1;display:grid;place-items:center;padding:48px 24px 64px}.panel{width:min(760px,100%);text-align:center;position:relative}.status{display:inline-flex;align-items:center;gap:9px;color:var(--accent);font-size:12px;font-weight:600;border:1px solid #a99bff36;border-radius:100px;padding:8px 14px;background:#a99bff08}.status::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;box-shadow:0 0 12px #a99bff55}
        .visual{position:relative;width:158px;height:158px;margin:0 auto 36px;display:grid;place-items:center;border:1px solid #a99bff20;border-radius:50%;background:radial-gradient(circle,#7c5cff20,transparent 70%)}.visual::before,.visual::after{content:"";position:absolute;border:1px solid #a99bff18;border-radius:50%;inset:16px}.visual::after{inset:-18px;border-style:dashed;opacity:.6}.visual svg{width:66px;height:66px;color:var(--accent)}.spark{position:absolute;width:8px;height:8px;background:#a99bff;border-radius:50%;top:16px;right:15px;box-shadow:0 0 18px #a99bff60}
        h1{font-size:clamp(36px,5vw,62px);font-weight:650;line-height:1.16;letter-spacing:-.04em;white-space:pre-line;margin:24px 0;color:#f1f4ff}p{font-size:16px;line-height:1.85;color:var(--muted);max-width:490px;margin:0 auto}.hint{font-size:14px;margin-top:16px}.retry{display:inline-flex;align-items:center;justify-content:center;gap:10px;margin-top:32px;padding:13px 25px;min-height:48px;border:1px solid #ad9bff70;border-radius:12px;background:#7c5cff;color:white;text-decoration:none;font:inherit;font-size:14px;font-weight:600;box-shadow:0 5px 24px #7c5cff20;cursor:pointer}.retry:hover{background:#8c70ff}.retry:focus-visible{outline:3px solid #eaf2ff;outline-offset:5px}.retry svg{width:17px;height:17px;flex-shrink:0}footer{padding:22px 24px;text-align:center;color:#798ba9;font-size:12px;letter-spacing:.06em}footer span{display:inline-block;border-top:1px solid var(--line);min-width:160px;padding-top:20px}
        [dir=rtl] h1{letter-spacing:0;line-height:1.5}[dir=rtl] .status{letter-spacing:0}
        .app-maintenance{--bg:#10151a;--muted:#9eafc5;--accent:#93c5fd}.app-maintenance body{background:radial-gradient(ellipse at 50% 35%,#3b82f610,transparent 60%),var(--bg)}.app-maintenance .header{padding-block:24px}.app-maintenance .panel{max-width:560px;padding:44px 40px;border:1px solid #93c5fd2e;border-radius:22px;background:linear-gradient(145deg,#1b2734,#141c26);box-shadow:0 24px 80px #0003}.app-maintenance .visual{width:96px;height:96px;margin-bottom:28px}.app-maintenance .visual::after{display:none}.app-maintenance .visual svg{width:42px;height:42px}.app-maintenance .spark{width:6px;height:6px;top:6px;right:18px;background:#93c5fd}.app-maintenance h1{font-size:clamp(28px,4vw,36px);letter-spacing:-.025em;margin:24px 0 16px}.app-maintenance[dir=rtl] h1{letter-spacing:0}.app-maintenance .status{border-color:#93c5fd30;background:#93c5fd08}.app-maintenance .retry{background:#93c5fd;color:#102239;border-color:#93c5fd;box-shadow:none}.app-maintenance .retry:hover{background:#b3d6ff}
        @media(max-width:600px){.header{padding:24px}.header small{font-size:10px}.brand{font-size:16px}main{padding:35px 20px}.visual{width:120px;height:120px;margin-bottom:32px}.app-maintenance .panel{padding:32px 23px}p{font-size:15px}.retry{width:100%;max-width:260px}footer{padding-bottom:24px}}
        @media(min-width:601px) and (max-height:800px){.header{padding-block:20px}main{padding-block:24px}.visual{width:112px;height:112px;margin-bottom:24px}h1{font-size:48px;margin:18px 0 14px}.hint{margin-top:10px}.retry{margin-top:22px}footer{padding-block:14px}footer span{padding-top:12px}.app-maintenance .panel{padding-block:30px}}
    </style>
</head>
<body>
    <header class="header"><div class="brand"><img src="data:image/png;base64,{{ $logo }}" width="42" height="42" alt=""><span>MetKurd AI</span></div><small aria-hidden="true">503</small></header>
    <main>
        <section class="panel" aria-labelledby="maintenance-title">
            <div class="visual" aria-hidden="true"><span class="spark"></span><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 22v20M23 15v34M32 23v18M41 10v44M49 20v24"/><path d="M10 55h44" opacity=".25"/></svg></div>
            <div class="status" data-copy="status">Scheduled maintenance</div>
            <h1 id="maintenance-title">A little pause.<br>A better MetKurd.</h1>
            <p id="maintenance-message">We're making improvements to MetKurd. The service will be available again shortly.</p>
            <p class="hint" data-copy="hint">Please try again shortly.</p>
            <a class="retry" href="" id="maintenance-retry"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M6.1 6.2a8 8 0 0 1 13 3.8M4.9 14a8 8 0 0 0 13 3.8"/></svg><span data-copy="retry">Try Again</span></a>
        </section>
    </main>
    <footer><span data-copy="footer">MetKurd AI</span></footer>
    <script>
        (() => {
            const translations = @json($copy);
            const copy = translations[document.documentElement.lang] || translations.en;
            const mode = document.documentElement.className === 'app-maintenance' ? 'app' : 'landing';
            document.querySelectorAll('[data-copy]').forEach(node => { node.textContent = copy[node.dataset.copy]; });
            document.getElementById('maintenance-title').textContent = copy[mode + 'Title'];
            document.getElementById('maintenance-message').textContent = copy[mode + 'Message'];
            document.title = copy.status + ' | MetKurd AI';
            document.getElementById('maintenance-retry').addEventListener('click', event => {
                event.preventDefault();
                window.location.reload();
            });
        })();
    </script>
</body>
</html>
