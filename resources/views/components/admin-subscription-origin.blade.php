@props(['subscription'])
@php
    $source = $subscription->source;
    $label = match ($source) {
        'admin_cash_agreement' => __('agreement.title'),
        'admin_manual_grant' => __('admin_ux.grant_history'),
        'admin_manual', 'internal_non_revenue' => __('admin_ux.subscription_admin_source'),
        'system' => __('admin_ux.subscription_system_source'),
        'fib' => 'FIB',
        default => is_string($source) && $source !== '' ? \App\Support\Admin\AdminData::redact($source) : __('admin_p2.not_recorded'),
    };
    $reason = data_get($subscription->meta, 'reason') ?? data_get($subscription->meta, 'admin_note');
@endphp
<span dir="auto">{{ $label }}</span>
@if(is_string($reason) && trim($reason) !== '')
    <div class="small text-muted" dir="auto">{{ \App\Support\Admin\AdminData::redact($reason) }}</div>
@endif
