@props(['rows', 'section'])
<div class="table-responsive admin-billing-table" tabindex="0" role="region" aria-label="{{ __('admin_billing.title') }}">
<table class="table align-middle"><thead><tr>
    <th>{{ __('admin_p2.customer') }} / {{ __('admin_p2.id') }}</th>
    <th>{{ __('admin_p2.summary') }}</th>
    <th>{{ __('admin_billing.local_state') }}</th>
    <th>{{ __('admin_billing.safe_action') }}</th>
</tr></thead><tbody>
@forelse($rows as $row)
    @php
        $isPayment = array_key_exists('billing_state', $row);
        $isSubscription = array_key_exists('effective_now', $row);
        $legacy = ($row['financial_era'] ?? '') === 'legacy';
        $paymentId = $isPayment ? $row['id'] : ($row['payment_id'] ?? null);
        $trace = ['locale' => app()->getLocale(), 'customerFilter' => $row['customer_id']];
        $paymentTrace = $trace + ['payment' => $paymentId, 'financialEra' => $isPayment ? $row['financial_era'] : ($row['payment_era'] ?? 'current')];
        $detail = $isPayment ? $paymentTrace + ['section' => 'payments'] : $trace + ['section' => $section, 'billingRecord' => $row['id'], 'financialEra' => $row['financial_era'] ?? 'current'];
    @endphp
    <tr wire:key="billing-{{ $section }}-{{ $row['id'] }}" class="{{ $legacy ? 'admin-billing-historical' : '' }}">
        <td><a wire:navigate dir="auto" href="{{ route('admin.customers.detail', ['locale' => app()->getLocale(), 'customer' => $row['customer_id']]) }}">{{ $row['customer'] ?? $row['customer_id'] }}</a>
            <small class="d-block text-muted"><bdi>#{{ $row['id'] }}</bdi></small>
            @if(isset($row['financial_era']))<span class="badge {{ $legacy ? 'bg-secondary-subtle text-secondary' : 'bg-info-subtle text-info' }}">{{ __('billing_epoch.'.$row['financial_era']) }}</span>@endif
            @if($isSubscription)<span class="badge {{ $row['effective_now'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">{{ __('admin_billing.'.($row['effective_now'] ? 'effective' : 'not_effective')) }}</span>@endif
        </td>
        <td>
            @if($isPayment)
                <strong><bdi>{{ $row['amount'] }} {{ $row['currency'] }}</bdi></strong>
                <div>{{ __('admin_billing.'.$row['purchase_type']) }} · <bdi>{{ $row['product'] }}</bdi></div>
                <div class="small"><bdi>{{ strtoupper($row['provider'] ?? '') }}</bdi> · <bdi>{{ $row['local_reference'] }}</bdi></div>
                @if(!empty($row['provider_reference']))<div class="small"><span class="text-muted">{{ __('admin_p2.provider_reference') }}:</span> <bdi>{{ $row['provider_reference'] }}</bdi></div>@endif
                <small class="d-block">{{ __('admin_p2.created_at') }}: <bdi>{{ $row['created_at'] }}</bdi></small>
                <small class="d-block">{{ __('admin_billing.updated_at') }}: <bdi>{{ $row['updated_at'] }}</bdi></small>
            @elseif($isSubscription)
                <strong dir="auto">{{ $row['plan'] }}</strong><div>{{ $row['source_label'] }}</div>
                <div class="small">{{ __('admin_p2.starts_at') }}: <bdi>{{ $row['starts_at'] ?? '—' }}</bdi></div>
                <div class="small">{{ __('admin_billing.paid_through') }}: <bdi>{{ $row['effective_access_until'] ?? $row['ends_at'] ?? '—' }}</bdi></div>
                <div class="small">{{ __('admin_p2.auto_renew') }}: {{ __('admin_p2.'.($row['auto_renew'] ? 'yes' : 'no')) }}</div>
                <div class="small">{{ __('admin_billing.renewal_status') }}: {{ \Illuminate\Support\Facades\Lang::has('subscription_lifecycle.'.$row['renewal_status']) ? __('subscription_lifecycle.'.$row['renewal_status']) : $row['renewal_status'] }}</div>
                @if($row['provider_cancel_pending'])<p class="small text-warning">{{ __('admin_billing.cancellation') }}</p>@endif
                @if(!empty($row['agreement']))
                    <details class="mt-2"><summary>{{ __('admin_billing.agreement') }} <bdi>#{{ $row['agreement']['id'] }}</bdi></summary>
                        <x-admin-operation-values :values="$row['agreement']" /><p class="small text-muted">{{ __('admin_billing.agreement_help') }}</p>
                        <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $row['customer_id']]) }}">{{ __('admin_billing.agreement') }}</a>
                    </details>
                @endif
            @else
                <strong>{{ \Illuminate\Support\Facades\Lang::has('admin_billing.'.$row['order_type']) ? __('admin_billing.'.$row['order_type']) : $row['order_type'] }}</strong>
                @if($row['base_amount_iqd'] !== null)<div><bdi>{{ $row['base_amount_iqd'] }} IQD</bdi></div>@elseif($row['amount_usd'] !== null)<div><bdi>{{ $row['amount_usd'] }} USD</bdi></div>@endif
                <div class="small">{{ __('admin_billing.credit_effect') }}: <bdi>{{ number_format($row['credits_amount'] ?? 0) }}</bdi></div>
                <small class="text-muted">{{ __('admin_billing.credit_help') }}</small>
                @if($row['revenue_excluded'])<div class="small text-warning">{{ __('admin_billing.excluded') }}</div>@endif
            @endif
        </td>
        <td>
            @if($isPayment)
                <span class="badge {{ match($row['billing_state']) { 'needs_review' => 'bg-warning-subtle text-warning', 'failed' => 'bg-danger-subtle text-danger', 'resolved' => 'bg-success-subtle text-success', default => 'bg-info-subtle text-info' } }}">{{ __('admin_billing.'.$row['billing_state']) }}</span>
                @if(in_array($row['billing_case'], \App\Support\Admin\AdminBillingWorkspace::CASES, true))<div class="small mt-2">{{ __('admin_billing.'.$row['billing_case']) }}</div>@endif
            @endif
            <x-admin-operation-status :row="$row" />
            @if($isSubscription)<p class="small text-muted mt-2">{{ __('admin_billing.authority_help') }}</p>@endif
            <details class="mt-2"><summary>{{ __('admin_billing.technical') }}</summary><x-admin-operation-values :values="array_diff_key($row, array_flip(['billing_state', 'billing_case', 'financial_era', 'effective_now', 'source_label', 'payment_era', 'agreement']))" /></details>
        </td>
        <td><div class="d-flex gap-2 flex-wrap">
            <a wire:navigate class="btn btn-sm btn-outline-primary" href="{{ route('admin.operations', $detail) }}">{{ __('admin_billing.evidence') }}</a>
            @if($paymentId && !$isPayment)<a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route('admin.operations', $paymentTrace + ['section' => 'payments']) }}">{{ __('admin_p2.payments') }} <bdi>#{{ $paymentId }}</bdi></a>@endif
            <a wire:navigate class="btn btn-sm btn-soft-secondary" href="{{ route('admin.operations', $paymentId ? $paymentTrace + ['section' => 'audit'] : $trace + ['section' => 'audit']) }}">{{ __('admin_p2.audit') }}</a>
            @if($paymentId)
                @foreach(['subscriptions', 'storage_subscriptions', 'orders', 'ledger'] as $target)<a wire:navigate class="btn btn-sm btn-soft-secondary" href="{{ route('admin.operations', $paymentTrace + ['section' => $target]) }}">{{ __('admin_p2.'.$target) }}</a>@endforeach
            @elseif(!$isPayment)<small>{{ __('admin_billing.no_payment') }}</small>@endif
            @if($isPayment && !$legacy && \App\Support\Admin\AdminUiAccess::can('admin.reconcile'))
                <a wire:navigate class="btn btn-sm btn-outline-primary" href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $row['customer_id'], 'billingPayment' => $row['id']]) }}">{{ __('admin_billing.open_workflow') }}</a>
            @endif
        </div>
        <p class="small text-muted mt-2 mb-0">{{ $legacy ? __('admin_billing.history_notice') : (($row['billing_case'] ?? '') === 'cancellation' ? __('admin_billing.cancellation_help') : __('admin_billing.inspect_first')) }}</p>
        </td>
    </tr>
@empty<tr><td colspan="4"><x-admin-empty-state>{{ __('admin_p2.empty') }}</x-admin-empty-state></td></tr>@endforelse
</tbody></table></div>
