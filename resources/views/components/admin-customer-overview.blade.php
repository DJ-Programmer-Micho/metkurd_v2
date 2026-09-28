@props(['overview', 'workspace'])
@php
    $customerId = (int) $overview['identity']['id'];
    $detailUrl = fn (array $query = []) => route('admin.customers.detail', ['locale' => app()->getLocale(), 'customer' => $customerId] + $query);
    $actionUrl = route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $customerId]);
    $plan = $workspace['plan'];
    $apiAvailable = $workspace['api_allowed'] && $workspace['api_gate'];
@endphp
<div class="admin-customer-workspace" data-customer-workspace="{{ $customerId }}">
    <div class="row g-3 mb-3">
        <div class="col-lg-6"><section class="card h-100"><div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><h2 class="admin-section-heading mb-0">{{ __('admin_customer.overview') }}</h2><x-admin-status-badge :tone="$workspace['account_active'] ? 'success' : 'danger'">{{ __('admin_p2.'.($workspace['account_active'] ? 'active' : 'inactive')) }}</x-admin-status-badge></div>
            <h3 class="h5" dir="auto">{{ $overview['identity']['username'] }}</h3>
            <p class="text-muted" dir="auto">{{ $overview['identity']['email'] }}</p>
            <dl class="admin-customer-facts">
                <dt>{{ __('admin_p2.created_at') }}</dt><dd><bdi>{{ $overview['identity']['created_at'] }}</bdi></dd>
                <dt>{{ __('admin_p2.email_verify') }}</dt><dd>{{ __('admin_p2.'.($overview['identity']['email_verify'] ? 'yes' : 'no')) }}</dd>
                <dt>{{ __('admin_p2.phone_verify') }}</dt><dd>{{ __('admin_p2.'.($overview['identity']['phone_verify'] ? 'yes' : 'no')) }}</dd>
            </dl>
            <a wire:navigate class="btn btn-sm btn-outline-primary" href="{{ $actionUrl }}#customer-actions">{{ __('admin_customer.manage_actions') }}</a>
            @if(\App\Support\Admin\AdminUiAccess::can('admin.customers'))
                <a wire:navigate class="btn btn-sm btn-outline-secondary" href="{{ route('admin.customers.list', ['locale' => app()->getLocale(), 'q' => $overview['identity']['username']]) }}">{{ __('admin_customer.account_controls') }}</a>
            @endif
            <details class="mt-3"><summary>{{ __('admin_customer.technical') }}</summary><x-admin-operation-values :values="$overview['identity']" /></details>
        </div></section></div>
        <div class="col-lg-6"><section class="card h-100"><div class="card-body">
            <h2 class="admin-section-heading">{{ __('admin_customer.effective_plan') }}</h2><p class="h4" dir="auto">{{ $plan['name'] }}</p>
            <dl class="admin-customer-facts">
                <dt>{{ __('admin_p2.source') }}</dt><dd>{{ $plan['source_label'] }}</dd>
                <dt>{{ __('admin_customer.expiry') }}</dt><dd><bdi>{{ $plan['expiry'] ?? __('admin_customer.no_expiry') }}</bdi></dd>
                <dt>{{ __('admin_customer.next_renewal') }}</dt><dd><bdi>{{ $plan['next_renewal'] ?? __('admin_customer.not_scheduled') }}</bdi></dd>
            </dl>
            <p class="small text-muted">{{ __('admin_customer.plan_authority') }}</p>
            <details><summary>{{ __('admin_customer.subscription_evidence') }}</summary><x-admin-operation-values :values="$overview['plan']" /></details>
        </div></section></div>
    </div>
    <h2 class="admin-section-heading">{{ __('admin_customer.resources') }}</h2>
    <div class="row g-3 mb-3">
        @foreach (['app_credits', 'api_credits'] as $wallet)
            <div class="col-sm-6 col-xl-3"><section class="card h-100"><div class="card-body">
                <h3 class="h6">{{ __('admin_p2.'.$wallet) }}</h3><p class="h3"><bdi>{{ isset($overview[$wallet]['balance_credits']) ? number_format($overview[$wallet]['balance_credits']) : __('admin_p2.not_recorded') }}</bdi></p>
                <details><summary>{{ __('admin_customer.credit_breakdown') }}</summary><x-admin-operation-values :values="$overview[$wallet]" /></details>
            </div></section></div>
        @endforeach
        <div class="col-sm-6 col-xl-3"><section class="card h-100"><div class="card-body">
            <h3 class="h6">{{ __('admin_p2.storage') }}</h3><p class="h5"><bdi>{{ number_format($overview['storage']['used_bytes'] / 1048576, 1) }} / {{ number_format($overview['storage']['quota_bytes'] / 1048576) }} MB</bdi></p>
            @if($overview['storage']['over_quota'])<x-admin-status-badge tone="warning">{{ __('admin_customer.over_quota') }}</x-admin-status-badge>@endif
            <p class="small text-muted">{{ __('admin_customer.file_count', ['count' => number_format($workspace['files_count'])]) }}</p><a wire:navigate href="{{ $detailUrl(['section' => 'files']) }}">{{ __('admin_p2.files') }}</a>
        </div></section></div>
        <div class="col-sm-6 col-xl-3"><section class="card h-100"><div class="card-body">
            <h3 class="h6">{{ __('admin_customer.concurrency') }}</h3>
            <dl class="admin-customer-facts"><dt><bdi>App</bdi></dt><dd>{{ $workspace['app_concurrency'] }}</dd><dt><bdi>API</bdi></dt><dd>{{ $overview['api_access']['concurrent_jobs'] }}</dd></dl>
            <p class="small text-muted">{{ __('admin_customer.separate_limits') }}</p>
        </div></section></div>
    </div>
    <p class="small text-muted">{{ __('admin_p2.wallet_notice') }}</p>
    <section class="card mb-3"><div class="card-body">
        <h2 class="admin-section-heading">{{ __('admin_customer.developer_access') }}</h2>
        <div class="row g-3">
            <div class="col-md-4"><h3 class="h6">{{ __('admin_customer.app_access') }}</h3><x-admin-status-badge :tone="$workspace['account_active'] ? 'success' : 'danger'">{{ __('admin_customer.'.($workspace['account_active'] ? 'account_active' : 'account_inactive')) }}</x-admin-status-badge><p class="small text-muted mt-2">{{ __('admin_customer.action_access_help') }}</p></div>
            <div class="col-md-4"><h3 class="h6"><bdi>API</bdi></h3><x-admin-status-badge :tone="$apiAvailable ? 'success' : 'secondary'">{{ __('admin_customer.'.($apiAvailable ? 'enabled' : 'unavailable')) }}</x-admin-status-badge>
                @if(!$workspace['api_gate'])<p class="small text-muted mt-2">{{ __('admin_customer.feature_disabled') }}</p>@elseif(!$workspace['api_allowed'])<p class="small text-muted mt-2">{{ __('admin_customer.api_access_unavailable') }}</p>@endif
                <p class="small mt-2">{{ __('admin_p2.api_credits') }}: <bdi>{{ isset($overview['api_credits']['balance_credits']) ? number_format($overview['api_credits']['balance_credits']) : __('admin_p2.not_recorded') }}</bdi></p>
                <p class="small mt-2">{{ __('admin_customer.scope_count', ['count' => count($overview['api_access']['scopes'])]) }}</p>
                <details><summary>{{ __('admin_customer.scopes') }}</summary><div class="d-flex flex-wrap gap-1 mt-2">@forelse($overview['api_access']['scopes'] as $scope)<code dir="ltr">{{ $scope }}</code>@empty<span class="text-muted">{{ __('admin_customer.no_scopes') }}</span>@endforelse</div></details>
            </div>
            <div class="col-md-4"><h3 class="h6"><bdi>MCP</bdi></h3><x-admin-status-badge :tone="$workspace['mcp_available'] ? 'success' : 'secondary'">{{ __('admin_customer.'.($workspace['mcp_available'] ? 'eligible' : 'unavailable')) }}</x-admin-status-badge>
                @if($workspace['mcp_reason'])<p class="small text-muted mt-2">{{ __('admin_customer.'.(in_array($workspace['mcp_reason'], ['feature_disabled', 'paid_plan_required', 'api_access_unavailable']) ? $workspace['mcp_reason'] : 'unavailable')) }}</p>@endif
                <p class="small text-muted mt-2">{{ __('admin_customer.mcp_processing') }}</p>
                <details><summary>{{ __('admin_customer.scopes') }}</summary><div class="d-flex flex-wrap gap-1 mt-2">@forelse($overview['mcp_access']['scopes'] as $scope)<code dir="ltr">{{ $scope }}</code>@empty<span class="text-muted">{{ __('admin_customer.no_scopes') }}</span>@endforelse</div></details>
            </div>
        </div>
        <details class="mt-3"><summary>{{ __('admin_customer.entitlements') }}</summary><div class="table-responsive mt-2"><table class="table"><thead><tr><th>{{ __('admin_customer.action') }}</th><th><bdi>App</bdi></th><th><bdi>API</bdi></th></tr></thead><tbody>
            @foreach($workspace['action_names'] as $action => $name)<tr><td dir="auto">{{ $name }}</td>@foreach(['App', 'API'] as $channel)<td>{{ __('admin_p2.'.(!empty($overview['effective_access'][$action.' / '.$channel]) ? 'yes' : 'no')) }}</td>@endforeach</tr>@endforeach
        </tbody></table></div><p class="small text-muted">{{ __('admin_customer.action_access_help') }}</p></details>
    </div></section>
    <section class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between gap-2 flex-wrap mb-3"><h2 class="admin-section-heading mb-0">{{ __('admin_customer.processing') }}</h2><a wire:navigate href="{{ $detailUrl(['section' => 'jobs']) }}#customer-records">{{ __('admin_customer.all_records') }}</a></div>
        <div class="row g-2 mb-3">@foreach($workspace['job_counts'] as $group => $count)<div class="col-6 col-md-3"><a wire:navigate class="admin-customer-count" href="{{ $detailUrl($group === 'queued' ? ['section' => 'jobs', 'status' => 'queued'] : ['section' => 'jobs', 'group' => $group]) }}#customer-records"><strong>{{ number_format($count) }}</strong><span>{{ __('admin_customer.jobs_'.$group) }}</span></a></div>@endforeach</div>
        <p class="small text-muted">{{ __('admin_customer.recent_jobs_help') }}</p>
        <x-admin-job-table :rows="$workspace['jobs']" />
    </div></section>
    <div class="row g-3 mb-3">
        <div class="col-lg-6"><section class="card h-100"><div class="card-body">
            <h2 class="admin-section-heading">{{ __('admin_customer.billing') }}</h2>
            <dl class="admin-customer-facts"><dt>{{ __('admin_customer.effective_plan') }}</dt><dd dir="auto">{{ $plan['name'] }}</dd><dt>{{ __('admin_customer.current_subscription') }}</dt><dd>{{ $plan['subscription_id'] ? '#'.$plan['subscription_id'] : __('admin_p2.not_recorded') }}</dd><dt>{{ __('admin_customer.agreement') }}</dt><dd>{{ __('admin_customer.'.($plan['has_agreement'] ? 'active_agreement' : ($plan['has_pending_agreement'] ? 'pending_agreement' : 'no_agreement'))) }}</dd></dl>
            @if($workspace['latest_payment'])
                @php($latestPayment = $workspace['latest_payment'])
                <p class="small">{{ __('admin_customer.latest_payment') }}: <bdi>#{{ $latestPayment['id'] }} · {{ $latestPayment['created_at'] }}</bdi></p>
                <a wire:navigate href="{{ $detailUrl(['section' => 'payments', 'payment' => $latestPayment['id']]) }}#customer-records">{{ __('admin_customer.payment_evidence') }}</a>
            @else<p class="small text-muted">{{ __('admin_customer.no_current_payment') }}</p>@endif
            <p class="small text-muted mt-2">{{ __('admin_customer.billing_boundary') }}</p>
            <div class="d-flex flex-wrap gap-2"><a wire:navigate class="btn btn-sm btn-outline-secondary" href="{{ $detailUrl(['section' => 'subscriptions']) }}#customer-records">{{ __('admin_customer.subscriptions_grants') }}</a><a wire:navigate class="btn btn-sm btn-outline-secondary" href="{{ $detailUrl(['section' => 'orders']) }}#customer-records">{{ __('admin_customer.orders_addons') }}</a><a wire:navigate class="btn btn-sm btn-outline-secondary" href="{{ $actionUrl }}#customer-agreements">{{ __('admin_customer.agreements') }}</a></div>
            <details class="mt-3"><summary>{{ __('admin_customer.legacy') }}</summary><a wire:navigate href="{{ $detailUrl(['section' => 'payments', 'financialEra' => 'legacy']) }}#customer-records">{{ __('admin_customer.legacy_payments') }}</a></details>
        </div></section></div>
        <div class="col-lg-6"><section class="card h-100"><div class="card-body">
            <h2 class="admin-section-heading">{{ __('admin_customer.audit') }}</h2>
            @forelse($workspace['audit'] as $event)<div class="admin-customer-event"><strong dir="auto">{{ $event['action'] }}</strong><small class="text-muted d-block"><bdi>{{ $event['created_at'] ?? '' }}</bdi></small><details><summary>{{ __('admin_customer.technical') }}</summary><x-admin-operation-values :values="$event" /></details></div>@empty<x-admin-empty-state>{{ __('admin_customer.no_audit') }}</x-admin-empty-state>@endforelse
            <a wire:navigate href="{{ $detailUrl(['section' => 'audit']) }}#customer-records">{{ __('admin_customer.all_audit') }}</a>
        </div></section></div>
    </div>
</div>
