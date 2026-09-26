<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar', 'ku']) ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('mcp.connect') }}</title><link rel="stylesheet" href="{{ asset('app/css/bootstrap.min.css') }}"><link rel="stylesheet" href="{{ asset('app/css/app.min.css') }}"></head>
<body class="bg-dark text-light">
<main class="container py-5"><div class="row justify-content-center"><div class="col-12 col-md-8 col-lg-6"><div class="card card-body">
    <h1>{{ __('mcp.connect') }}</h1>
    <p dir="auto">{{ $client->name }}</p>
    @if($client->mcp_metadata_hash)<p><code dir="ltr">{{ $client->id }}</code></p>@endif
    <p>{{ __('mcp.consent') }}</p>
    @php($scopeTools = ['v2:speech' => 'speak', 'v2:voice-clone' => 'clone_voice', 'v2:transcriptions' => 'transcribe', 'v2:captions' => 'caption', 'v2:ocr' => 'ocr', 'v2:stem' => 'stem', 'v2:harakat' => 'harakat', 'v2:jobs:read' => 'get_job', 'v2:files:download' => 'list_recent_files', 'mcp:uploads' => 'create_upload_session'])
    <ul>@foreach($scopes as $scope)<li>{{ __('mcp.tool_'.$scopeTools[$scope->id]) }} <code dir="ltr">{{ $scope->id }}</code></li>@endforeach</ul>
    <p>{{ __('mcp.credits') }}</p>
    <form method="post" action="{{ route('passport.authorizations.approve') }}">
        @csrf
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button class="btn btn-primary" name="decision" value="approve">{{ __('mcp.approve') }}</button>
        <button class="btn btn-outline-secondary" name="decision" value="deny">{{ __('mcp.deny') }}</button>
    </form>
</div></div></div></main>
</body></html>
