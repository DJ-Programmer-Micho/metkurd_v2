@props(['tool'])
@php
    $legacyRoute = (string) ($tool['legacy_route'] ?? '');
    $legacyWorkspaceName = (string) ($tool['legacy_workspace_name'] ?? __('V1 workspace'));
@endphp

<div class="turbo-border">
    <div class="turbo-inner">
        <div class="glass-load glass-load--info p-4 p-lg-5">
            <div class="d-flex align-items-start gap-3 flex-wrap">
                <div class="v2-waveform text-info flex-shrink-0" aria-hidden="true">
                    @for ($bar = 0; $bar < 18; $bar++)<i></i>@endfor
                </div>
                <div class="flex-grow-1">
                    <p class="small text-info text-uppercase fw-semibold mb-2">{{ __('V1 execution workspace') }}</p>
                    <h2 class="h4 mb-2">{{ __('Continue with :workspace', ['workspace' => $legacyWorkspaceName]) }}</h2>
                    <p class="v2-muted mb-4">{{ __('The established V1 workspace remains the live execution experience while V2 submission moves to the shared, exactly-once job workflow.') }}</p>
                    @if ($legacyRoute !== '' && \Illuminate\Support\Facades\Route::has($legacyRoute))
                        <a wire:navigate href="{{ route($legacyRoute, ['locale' => app()->getLocale()]) }}" class="btn btn-primary waves-effect waves-light">
                            {{ __('Open V1 workspace') }} <i class="ri-arrow-right-line ms-1"></i>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
