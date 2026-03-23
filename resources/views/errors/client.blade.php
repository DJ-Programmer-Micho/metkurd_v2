@php
    $locale = request()->route('locale') ?: app()->getLocale();
    $appUserAuthenticated = auth('app')->check();
    $primaryUrl = $primaryUrl ?? ($appUserAuthenticated
        ? route('app.home', ['locale' => $locale])
        : url('/'));
    $primaryLabel = $primaryLabel ?? ($appUserAuthenticated ? 'Back to App' : 'Go Home');
    $secondaryUrl = $secondaryUrl ?? ($appUserAuthenticated
        ? route('app.billing', ['locale' => $locale])
        : route('app.signin'));
    $secondaryLabel = $secondaryLabel ?? ($appUserAuthenticated ? 'Open Billing' : 'Sign In');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $code }} {{ $title ?? 'Client Error' }} | METKURD</title>
    <style>
        :root {
            color-scheme: light;
            --mk-bg: #0f172a;
            --mk-bg-soft: #16213c;
            --mk-panel: rgba(15, 23, 42, 0.78);
            --mk-panel-border: rgba(148, 163, 184, 0.18);
            --mk-text: #e2e8f0;
            --mk-muted: #94a3b8;
            --mk-accent: #f5a30b;
            --mk-accent-strong: #cc0022;
            --mk-button: #f8fafc;
            --mk-button-text: #0f172a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--mk-text);
            background:
                radial-gradient(circle at top left, rgba(249, 22, 22, 0.18), transparent 30%),
                radial-gradient(circle at bottom right, rgba(245, 11, 11, 0.14), transparent 32%),
                linear-gradient(160deg, var(--mk-bg) 0%, #111827 48%, var(--mk-bg-soft) 100%);
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .mk-error-shell {
            width: min(100%, 760px);
        }

        .mk-error-card {
            position: relative;
            overflow: hidden;
            padding: 32px;
            border-radius: 28px;
            background: var(--mk-panel);
            border: 1px solid var(--mk-panel-border);
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(14px);
        }

        /* .mk-error-card::before {
            content: "";
            position: absolute;
            inset: 0 auto auto 0;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(245, 158, 11, 0.2), transparent 72%);
            pointer-events: none;
        } */

        .mk-error-code {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(245, 158, 11, 0.12);
            color: #fcd34d;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        h1 {
            margin: 18px 0 12px;
            font-size: clamp(2rem, 5vw, 3.4rem);
            line-height: 1.02;
            letter-spacing: -0.04em;
        }

        p {
            margin: 0;
            font-size: 1rem;
            line-height: 1.7;
            color: var(--mk-muted);
            max-width: 62ch;
        }

        .mk-error-hint {
            margin-top: 16px;
            padding-left: 16px;
            border-left: 3px solid rgba(245, 11, 11, 0.45);
        }

        .mk-error-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        .mk-button,
        .mk-button-secondary,
        .mk-button-ghost {
            appearance: none;
            border: 0;
            border-radius: 999px;
            padding: 12px 18px;
            font-size: 0.96rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.16s ease, box-shadow 0.16s ease, opacity 0.16s ease;
        }

        .mk-button:hover,
        .mk-button-secondary:hover,
        .mk-button-ghost:hover {
            transform: translateY(-1px);
        }

        .mk-button {
            background: linear-gradient(135deg, var(--mk-accent), var(--mk-accent-strong));
            color: var(--mk-button-text);
            box-shadow: 0 14px 28px rgba(249, 115, 22, 0.26);
        }

        .mk-button-secondary {
            background: var(--mk-button);
            color: var(--mk-button-text);
        }

        .mk-button-ghost {
            background: transparent;
            color: var(--mk-text);
            border: 1px solid rgba(148, 163, 184, 0.24);
        }

        .mk-error-footer {
            margin-top: 26px;
            font-size: 0.92rem;
            color: rgba(148, 163, 184, 0.82);
        }

        @media (max-width: 640px) {
            .mk-error-card {
                padding: 24px;
                border-radius: 22px;
            }

            .mk-error-actions {
                flex-direction: column;
            }

            .mk-button,
            .mk-button-secondary,
            .mk-button-ghost {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <main class="mk-error-shell">
        <section class="mk-error-card">
            <div class="mk-error-code">Client Error <span>{{ $code }}</span></div>
            <h1>{{ $title ?? 'Something went wrong' }}</h1>
            <p>{{ $message ?? 'The request could not be completed.' }}</p>

            @if(!empty($hint ?? null))
                <p class="mk-error-hint">{{ $hint }}</p>
            @endif

            <div class="mk-error-actions">
                <a href="{{ $primaryUrl }}" class="mk-button">{{ $primaryLabel }}</a>

                @if(!empty($secondaryUrl ?? null))
                    <a href="{{ $secondaryUrl }}" class="mk-button-secondary">{{ $secondaryLabel }}</a>
                @endif

                <button type="button" class="mk-button-ghost" onclick="window.history.back();">Go Back</button>
            </div>

            <div class="mk-error-footer">
                Request path: <strong>{{ request()->path() }}</strong>
            </div>
        </section>
    </main>
</body>
</html>
