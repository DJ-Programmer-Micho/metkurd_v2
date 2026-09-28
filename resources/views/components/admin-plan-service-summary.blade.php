@props(['plan', 'summary'])
<details class="admin-plan-services mt-2">
    <summary>{{ __('admin_service.plan_summary') }}</summary>
    <div class="mt-2">
        @foreach(['app', 'api'] as $channel)<p class="small mb-2"><strong><bdi>{{ strtoupper($channel) }}</bdi></strong>: {{ implode(' · ', $summary['families'][$channel]) ?: __('admin_service.no_families') }}</p>@endforeach
        <p class="small text-muted">{{ __('admin_service.override_help') }}</p>
        <p class="small text-muted">{{ __('admin_service.storage_separate') }}</p>
        <strong class="small">{{ __('admin_service.character_limits') }}</strong>
        <ul class="small">@foreach($summary['limits'] as $name => $limits)<li><bdi>{{ $name }}</bdi>: <bdi>App {{ number_format($limits['app']) }} / API {{ number_format($limits['api']) }}</bdi></li>@endforeach</ul>
        <a wire:navigate href="{{ route('admin.services.entitlements', ['locale' => app()->getLocale(), 'plan' => $plan->id]) }}">{{ __('admin_service.access_limits') }}</a>
    </div>
</details>
