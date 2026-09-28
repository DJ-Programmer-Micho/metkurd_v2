<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('admin::layouts.app')] class extends Component
{
    use \App\Support\Admin\ReadsOperations;
};
?>
<x-slot:title>{{ $customer ? __('admin_ux.customer_profile').' — ' : '' }}{{ __('admin_p2.'.$section) }} | {{ __('MET KURD') }}</x-slot:title>
<div>
    @php
        $billingView = $this->isBilling();
        $paymentView = $section === 'payments' || ($section === 'review' && $queue === 'payment_review');
    @endphp
    @if ($customer || (int) $customerFilter)
        <x-admin-page-header :title="__('admin_customer.customer_detail')" :description="__('admin_customer.detail_help')" />
    @else<h4>{{ $billingView ? __('admin_billing.title') : ($this->isDeveloper() ? __('admin_developer.title') : __('admin_p2.operations')) }}</h4>@endif
    <x-admin-customer-context :customer-id="$customer ?: (int) $customerFilter" :name="data_get($this->overview, 'identity.username')" />
    <p class="text-muted">{{ __('admin_p2.read_notice') }}</p>
    @if($this->isDeveloper())
        <p class="text-muted">{{ __('admin_developer.help') }}</p>
        <div class="d-flex flex-wrap gap-2 mb-3">@foreach(['API V2'=>(bool)config('customer_api.v2_enabled'), 'MCP V2'=>(bool)config('mcp.enabled')] as $gate=>$enabled)<x-admin-status-badge :tone="$enabled ? 'success' : 'secondary'"><bdi dir="ltr">{{ $gate }}</bdi>: {{ __('admin_p2.'.($enabled ? 'active' : 'inactive')) }}</x-admin-status-badge>@endforeach</div>
        @if(in_array($section,['api','mcp']))
            <div class="row g-2 mb-3">@foreach($this->developerSummary as $label=>$count)<div class="col-6 col-md-4 col-xl-3"><div class="card h-100 mb-0"><div class="card-body"><span class="small text-muted">{{ __('admin_developer.'.$label) }}</span><strong class="fs-4 d-block">{{ number_format($count) }}</strong></div></div></div>@endforeach</div>
            <p class="small text-muted">{{ __('admin_developer.count_help') }}</p>
        @endif
        @if($apiJob || $keyId || $connectionId)<p class="alert alert-info">{{ __('admin_developer.trace_active') }} <button class="btn btn-sm btn-outline-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button></p>@endif
    @endif
    @if($billingView)<p class="small text-muted">{{ __('admin_billing.help') }}</p>@endif
    @if ($section === 'jobs' && !($customer || (int) $customerFilter))
        <p class="text-muted small">{{ __('admin_ux.evidence_help') }}</p>
        <div class="row g-2 mb-3">
            @foreach ($this->jobSummary as $state => $count)
                <div class="col-6 col-md-3 col-xl"><button type="button" wire:click="$set('group', '{{ $state }}')" class="btn w-100 h-100 text-start border {{ $group === $state ? 'btn-primary' : 'btn-light' }}" aria-pressed="{{ $group === $state ? 'true' : 'false' }}">
                    <span class="small d-block">{{ __('admin_ux.'.($state === 'persistence' ? 'persistence_problems' : $state)) }}</span><strong class="fs-4">{{ number_format($count) }}</strong>
                </button></div>
            @endforeach
        </div>
        <p class="text-muted small">{{ __('admin_ux.summary_help') }}</p>
    @endif
    @if (!$customer)
        <div class="row g-2 mb-3">
            <div class="col-md-6"><label class="form-label" for="admin-field-adm-operations-1">{{ __('admin_p2.customer_search') }}</label><input class="form-control" wire:model.live.debounce.400ms="customerSearch" maxlength="100" dir="auto" id="admin-field-adm-operations-1"></div>
            <div class="col-md-6"><label class="form-label" for="admin-field-adm-operations-2">{{ __('admin_p2.customer') }}</label><select class="form-select" wire:model.live="customerFilter" id="admin-field-adm-operations-2"><option value="">{{ __('admin_p2.all') }}</option>@foreach ($this->customerOptions as $option)<option value="{{ $option->id }}">{{ $option->username }} · {{ $option->email }}</option>@endforeach</select></div>
        </div>
    @endif
    @if ($this->overview)
        @if ($section === 'jobs' && $group === '' && $status === '' && $search === '' && $job === '' && $payment === '')
            <x-admin-customer-overview :overview="$this->overview" :workspace="$this->customerWorkspace" />
        @endif
    @endif
    @if ($this->overview)
        <details class="admin-customer-evidence mb-3" id="customer-records" wire:ignore.self @if($section !== 'jobs' || $group !== '' || $status !== '' || $search !== '' || $job !== '' || $payment !== '' || request()->query('section') === 'jobs') open @endif>
        <summary class="admin-section-heading">{{ __('admin_customer.records') }}</summary>
    @endif
    @if ($this->context)
        @if($payment !== '')<details class="card mb-3"><summary class="card-header">{{ __('admin_p2.detail') }} <bdi>#{{ $this->context['id'] }}</bdi></summary><div class="card-body"><x-admin-operation-values :values="$this->context" /></div></details>
        @else<div class="card"><div class="card-body"><h5>{{ __('admin_p2.detail') }}</h5><x-admin-developer-table :rows="[$this->context]" section="jobs" /></div></div>@endif
        <a wire:navigate class="btn btn-soft-secondary mb-3" href="{{ route('admin.operations', ['locale' => app()->getLocale(), 'customerFilter' => $customer ?: $customerFilter]) }}">{{ __('admin_p2.clear_trace') }}</a>
    @endif
    <nav class="nav nav-pills gap-1 mb-3" aria-label="{{ __('admin_p2.sections') }}">
        @foreach (\App\Services\Admin\AdminOperations::SECTIONS as $tab)
            <button type="button" class="nav-link {{ $section === $tab ? 'active' : '' }}" wire:click="$set('section', '{{ $tab }}')">{{ __('admin_p2.'.$tab) }}</button>
        @endforeach
    </nav>
    @if ($paymentView || $section === 'orders')
        <div class="mb-3"><label for="financial-era" class="form-label">{{ __('billing_epoch.history') }}</label>
            <select id="financial-era" class="form-select" wire:model.live="financialEra"><option value="current">{{ __('billing_epoch.current') }}</option><option value="legacy">{{ __('billing_epoch.legacy') }}</option></select>
            <p class="text-muted small">{{ __('billing_epoch.help') }}</p>
            <p class="small {{ $financialEra === 'legacy' ? 'alert alert-secondary' : 'text-muted' }}">{{ __('admin_billing.'.($financialEra === 'legacy' ? 'history_notice' : 'current_notice')) }}</p>
        </div>
    @endif
    <div class="card"><div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-12"><button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button></div>
            @if(in_array($section, ['subscriptions', 'storage_subscriptions'], true))
                <div class="col-md-4"><label class="form-label" for="subscription-access">{{ __('admin_billing.effective') }}</label><select id="subscription-access" class="form-select" wire:model.live="subscriptionAccess"><option value="">{{ __('admin_p2.all') }}</option>@foreach(['effective', 'not_effective'] as $access)<option value="{{ $access }}">{{ __('admin_billing.'.$access) }}</option>@endforeach</select></div>
            @endif
            @if($paymentView)
                @if($section !== 'review')<div class="col-md-4"><label class="form-label" for="billing-state">{{ __('admin_billing.review_state') }}</label><select id="billing-state" class="form-select" wire:model.live="billingState"><option value="">{{ __('admin_p2.all') }}</option>@foreach(\App\Support\Admin\AdminBillingWorkspace::STATES as $state)<option value="{{ $state }}">{{ __('admin_billing.'.$state) }}</option>@endforeach</select></div>@endif
                <div class="col-md-4"><label class="form-label" for="billing-case">{{ __('admin_billing.case') }}</label><select id="billing-case" class="form-select" wire:model.live="billingCase"><option value="">{{ __('admin_p2.all') }}</option>@foreach(\App\Support\Admin\AdminBillingWorkspace::CASES as $case)<option value="{{ $case }}">{{ __('admin_billing.'.$case) }}</option>@endforeach</select></div>
                <div class="col-12 d-flex flex-wrap gap-2">@foreach(\App\Support\Admin\AdminBillingWorkspace::STATES as $state)<a wire:navigate class="btn btn-sm btn-outline-secondary" href="{{ route('admin.operations', ['locale' => app()->getLocale(), 'section' => 'payments', 'billingState' => $state, 'financialEra' => $financialEra, 'customerFilter' => $customer ?: $customerFilter]) }}">{{ __('admin_billing.'.$state) }}</a>@endforeach</div>
            @endif
            @if ($section === 'jobs')
                <div class="col-md-3"><label class="form-label" for="op-group">{{ __('admin_ux.job_group') }}</label><select id="op-group" class="form-select" wire:model.live="group"><option value="">{{ __('admin_p2.all') }}</option>@foreach (\App\Services\Admin\AdminOperations::JOB_GROUPS as $state)<option value="{{ $state }}">{{ __('admin_ux.'.($state === 'persistence' ? 'persistence_problems' : $state)) }}</option>@endforeach</select></div>
            @endif
            @foreach (['search', 'status', 'from', 'until'] as $filter)
                <div class="col-md-3"><label class="form-label" for="op-{{ $filter }}">{{ __('admin_p2.'.$filter) }}</label><input id="op-{{ $filter }}" @if($filter === 'status') list="admin-operational-statuses" @endif type="{{ in_array($filter, ['from', 'until']) ? 'date' : 'text' }}" class="form-control" wire:model.live.debounce.400ms="{{ $filter }}" maxlength="100" dir="auto"></div>
            @endforeach
            @if (in_array($section, ['jobs', 'api', 'reservations', 'review']) && !$billingView)
                <div class="col-md-4"><label class="form-label" for="admin-field-adm-operations-3">{{ __('admin_p2.service') }}</label><select class="form-select" wire:model.live="service" id="admin-field-adm-operations-3"><option value="">{{ __('admin_p2.all') }}</option>@foreach (app(\App\Services\CustomerApi\V2\ApiCatalog::class)->variants() as $variant)<option value="{{ $variant['action'] }}">{{ $variant['tool']['name'] }}</option>@endforeach</select></div>
                @if($section === 'jobs' || ($section === 'review' && $queue !== 'reservation_review'))<div class="col-md-4"><label class="form-label" for="admin-field-adm-operations-4">{{ __('admin_p2.failure_stage') }}</label><input class="form-control" wire:model.live.debounce.400ms="failure" dir="ltr" maxlength="80" id="admin-field-adm-operations-4"></div>@endif
            @endif
            @if (in_array($section, ['jobs', 'api', 'reservations', 'ledger', 'review']) && !$billingView)
                <div class="col-md-4"><label class="form-label" for="admin-field-adm-operations-5">{{ __('admin_p2.channel') }}</label><select class="form-select" wire:model.live="channel" id="admin-field-adm-operations-5"><option value="">{{ __('admin_p2.all') }}</option><option value="app">{{ $section === 'ledger' ? __('admin_p2.app_credits') : 'App' }}</option><option value="api">{{ $section === 'ledger' ? __('admin_p2.api_credits') : 'API' }}</option>@if($this->isDeveloper())<option value="mcp">MCP</option>@endif</select></div>
            @endif
            @if ($section === 'ledger')
                <div class="col-md-4"><label class="form-label" for="admin-field-adm-operations-6">{{ __('admin_p2.direction') }}</label><select class="form-select" wire:model.live="direction" id="admin-field-adm-operations-6"><option value="">{{ __('admin_p2.all') }}</option><option value="debit">{{ __('admin_p2.debit') }}</option><option value="credit">{{ __('admin_p2.credit') }}</option></select></div>
            @endif
            @if ($section === 'payments')<div class="col-md-4"><label class="form-label" for="admin-field-adm-operations-7">{{ __('admin_p2.provider') }}</label><input class="form-control" wire:model.live.debounce.400ms="method" maxlength="50" dir="ltr" id="admin-field-adm-operations-7"></div>@endif
            @if ($section === 'review')
                <div class="col-md-6"><label class="form-label" for="admin-field-adm-operations-8">{{ __('admin_p2.queue') }}</label><select class="form-select" wire:model.live="queue" id="admin-field-adm-operations-8">@foreach (\App\Services\Admin\AdminOperations::QUEUES as $item)<option value="{{ $item }}">{{ __('admin_p2.'.$item) }}</option>@endforeach</select></div>
                <p class="text-muted">{{ __('admin_p2.review_notice') }}</p>
            @endif
        </div>
        <datalist id="admin-operational-statuses">
            @foreach(['queued', 'running', 'saving', 'done', 'failed', 'accepted', 'processing', 'completed', 'cancelled', 'reserved', 'settled', 'released', 'pending', 'paid', 'expired', 'active', 'inactive'] as $statusOption)
                <option value="{{ $statusOption }}">{{ \Illuminate\Support\Facades\Lang::has('admin_p2.'.$statusOption) ? __('admin_p2.'.$statusOption) : $statusOption }}</option>
            @endforeach
        </datalist>
        @php($rows = $this->rows)
        <div wire:loading class="text-muted" role="status">{{ __('admin_p2.loading') }}</div>
        @if ($this->isDeveloper())
            <x-admin-developer-table :rows="$rows" :section="$section" />
        @elseif($billingView)
            <x-admin-billing-table :rows="$rows" :section="$section" />
        @else
        <div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>{{ __('admin_p2.id') }}</th><th>{{ __('admin_p2.customer') }}</th><th>{{ __('admin_p2.summary') }}</th><th>{{ __('admin_p2.trace') }}</th></tr></thead><tbody>
        @forelse ($rows as $row)
            <tr wire:key="op-{{ $section }}-{{ $row['id'] }}">
                <td dir="ltr">{{ $row['id'] }}</td>
                <td>@if (!empty($row['customer_id']))<a dir="auto" wire:navigate href="{{ route('admin.customers.detail', ['locale' => app()->getLocale(), 'customer' => $row['customer_id']]) }}">{{ $row['customer'] ?? $row['customer_id'] }}</a>@endif</td>
                <td><x-admin-operation-status :row="$row" /><div class="d-flex gap-2 flex-wrap" dir="auto">@foreach (['created_at', 'family', 'model', 'tool_action', 'wallet_type', 'type', 'amount', 'held_amount', 'settled_amount', 'released_amount', 'purpose', 'plan', 'name', 'action'] as $key)@if (isset($row[$key]))<span><small class="text-muted">{{ __('admin_p2.'.$key) }}:</small> {{ is_bool($row[$key]) ? __('admin_p2.'.($row[$key] ? 'yes' : 'no')) : (in_array($key, ['status', 'local_lifecycle', 'persisted_result']) && \Illuminate\Support\Facades\Lang::has('admin_p2.'.$row[$key]) ? __('admin_p2.'.$row[$key]) : $row[$key]) }}</span>@endif @endforeach</div>
                    <details class="mt-2"><summary>{{ __('admin_p2.detail') }}</summary><x-admin-operation-values :values="$row" /></details>
                </td>
                <td>
                    @if (array_key_exists('idempotency_present', $row))<a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route('admin.operations', ['locale' => app()->getLocale(), 'section' => 'reservations', 'search' => $row['id'], 'customerFilter' => $row['customer_id']]) }}">{{ __('admin_p2.reservations') }}</a>@endif
                    @php($jobId = array_key_exists('persisted_result', $row) ? $row['id'] : ($row['ml_job_id'] ?? null))
                    @if ($jobId)<a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route('admin.operations', ['locale' => app()->getLocale(), 'job' => $jobId, 'section' => 'files', 'customerFilter' => $row['customer_id'] ?? '']) }}">{{ __('admin_p2.job_trace') }}</a>@endif
                    @php($paymentId = array_key_exists('fulfillment', $row) ? $row['id'] : ($row['payment_id'] ?? null))
                    @if ($paymentId)<a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route('admin.operations', ['locale' => app()->getLocale(), 'payment' => $paymentId, 'section' => 'orders', 'financialEra' => $financialEra, 'customerFilter' => $row['customer_id'] ?? '']) }}">{{ __('admin_p2.payment_trace') }}</a>@endif
                    @if (!empty($row['api_job_id']))<a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route('admin.operations', ['locale' => app()->getLocale(), 'section' => 'api', 'search' => $row['api_job_id'], 'customerFilter' => $row['customer_id'] ?? '']) }}">{{ __('admin_p2.api') }}</a>@endif
                    @if (!empty($row['reference_code']))<a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route('admin.operations', ['locale' => app()->getLocale(), 'section' => 'ledger', 'search' => $row['reference_code'], 'customerFilter' => $row['customer_id'] ?? '']) }}">{{ __('admin_p2.ledger') }}</a>@endif
                </td>
            </tr>
        @empty <tr><td colspan="4">{{ __('admin_p2.empty') }}</td></tr> @endforelse
        </tbody></table></div>
        @endif
        {{ $rows->links() }}
        @if($this->billingEvidence)<x-admin-billing-evidence :evidence="$this->billingEvidence" />@endif
    </div></div>
    @if ($this->overview)</details>@endif
</div>
