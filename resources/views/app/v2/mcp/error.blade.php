<!doctype html>
<html class="metkurd-v2-typography" lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar', 'ku']) ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>MetKurd MCP</title><link rel="stylesheet" href="{{ asset('app/css/bootstrap.min.css') }}">@vite('resources/css/v2-typography.css')</head>
<body><main class="container py-5"><h1>MetKurd MCP</h1><p>{{ $message }}</p><a href="{{ route('app.v2.mcp', ['locale' => app()->getLocale()]) }}">{{ __('mcp.overview') }}</a></main></body>
</html>
