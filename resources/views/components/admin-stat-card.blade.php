@props(['label', 'value', 'detail' => null, 'icon' => 'ri-bar-chart-line', 'tone' => 'info', 'href' => null])
@php($tone = in_array($tone, ['success', 'warning', 'danger', 'info', 'secondary'], true) ? $tone : 'info')
<div {{ $attributes->class(['card h-100 admin-stat']) }}>
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between gap-2"><span class="admin-stat-label">{{ $label }}</span><i class="{{ $icon }} admin-stat-icon bg-{{ $tone }}-subtle text-{{ $tone }}" aria-hidden="true"></i></div>
        <div class="admin-stat-value"><bdi>{{ $value }}</bdi></div>
        @if($detail)<p class="small text-muted mb-2">{{ $detail }}</p>@endif
        @if($href)<a wire:navigate href="{{ $href }}">{{ __('admin_shell.view_records') }}<span class="visually-hidden"> — {{ $label }}</span></a>@endif
    </div>
</div>
