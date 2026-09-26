@component('app::v2.layouts.app')
    <div class="v2-api v2-mcp">@include('app.v2.mcp.styles')<header class="api-header"><h1>{{ __('mcp.uploads') }}</h1></header><main class="api-main">
        <p>{{ __('mcp.upload_help') }}</p><p>{{ __('mcp.upload_limits') }}</p>
        @if($fileId)<div class="alert alert-success">{{ __('mcp.upload_done') }} <code dir="ltr">{{ $fileId }}</code></div>
        @else<form method="post" enctype="multipart/form-data">@csrf<label for="mcp-file">{{ __('mcp.choose_file') }}</label><input id="mcp-file" type="file" name="file" class="form-control" required>@foreach($errors->all() as $error)<p class="text-danger">{{ $error }}</p>@endforeach<button class="btn btn-primary mt-3">{{ __('mcp.upload') }}</button></form>@endif
    </main></div>
@endcomponent
