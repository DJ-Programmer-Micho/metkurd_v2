@props(['capabilities'])
@php($missing = array_filter($capabilities, fn ($capability) => ! \App\Support\Admin\AdminUiAccess::can($capability)))
@if ($missing)
    <div class="alert alert-info d-flex flex-wrap gap-2 align-items-center" role="status">
        <span>{{ __('admin_ux.permission_notice') }}</span>
        @foreach ($missing as $capability)<code dir="ltr">{{ $capability }}</code>@endforeach
    </div>
@endif
