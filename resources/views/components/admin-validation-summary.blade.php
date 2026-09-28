@if ($errors->any())
    <div class="alert alert-danger admin-validation-summary" role="alert" tabindex="-1">
        <strong>{{ __('admin_cleanup.validation') }}</strong>
        <ul class="mb-0">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif
