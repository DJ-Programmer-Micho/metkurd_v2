@php($preview = $this->v2Preview)
<section class="card" aria-label="{{ __('admin_p1.catalog') }}">
    <div class="card-header"><h5 class="mb-0">{{ __('admin_p1.catalog') }}</h5></div>
    <div class="card-body">
        <p>{{ __('admin_p1.read_only') }}</p>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><label class="form-label" for="admin-field-admin-v2-catalog-1">{{ __('admin_p1.plan') }}</label>
                <select class="form-select" wire:model.live="v2PlanId" id="admin-field-admin-v2-catalog-1">
                    @foreach($this->v2Plans as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-8"><details><summary>{{ __('admin_p3.advanced') }}</summary><label class="form-label" for="admin-field-admin-v2-catalog-2">{{ __('admin_p1.sample') }}</label>
                <textarea class="form-control font-monospace" dir="ltr" rows="2" maxlength="4000" wire:model.blur="v2SampleJson" id="admin-field-admin-v2-catalog-2"></textarea></details>
            </div>
        </div>
        @if(isset($preview['error']))<p role="alert">{{ __('admin_p1.sample_error') }}</p>
        @elseif($preview)
            @php($plan = $preview['plan'])
            @php($effective = $preview['effective'])
            <div class="row g-3 mb-3"><div class="col-md-6 border rounded p-3"><strong>{{ __('admin_p1.configured') }}</strong>:
                {{ __('admin_p1.api_enabled') }} {{ $plan->api_enabled ? __('admin_p1.yes') : __('admin_p1.no') }};
                <bdi dir="ltr">RPM {{ $plan->api_requests_per_minute }} · {{ __('admin_p1.concurrency') }} {{ $plan->api_concurrent_jobs }}</bdi>
                </div><div class="col-md-6 border rounded p-3"><strong>{{ __('admin_p1.effective') }}</strong>:
                {{ __('admin_p1.api_enabled') }} {{ $effective['api_enabled'] ? __('admin_p1.yes') : __('admin_p1.no') }};
                <bdi dir="ltr">RPM {{ $effective['requests_per_minute'] }} · {{ __('admin_p1.concurrency') }} {{ $effective['concurrent_jobs'] }}</bdi>
            </div></div>
            <p class="small">{{ __('admin_p1.access_note') }}</p>
            <p class="small">{{ __('admin_p1.explicit_scopes') }}:
                <bdi dir="ltr">{{ implode(', ', app(\App\Services\Admin\AdminEntitlementScopes::class)->explicit($plan)) ?: '—' }}</bdi><br>
                {{ __('admin_p1.derived_scopes') }}:
                <bdi dir="ltr">{{ implode(', ', data_get($plan->meta, 'admin_api_scopes.derived', [])) ?: '—' }}</bdi>
            </p>
            <div class="table-responsive"><table class="table align-middle">
                <thead><tr><th>{{ __('admin_p1.product') }}</th><th>{{ __('admin_p1.identity') }}</th><th>{{ __('admin_p1.app_quote') }}</th><th>{{ __('admin_p1.api_quote') }}</th><th>{{ __('admin_p1.access') }}</th><th>{{ __('admin_p1.diagnostics') }}</th></tr></thead>
                <tbody>
                @foreach($preview['rows'] as $row)
                    <tr wire:key="v2-catalog-{{ $row['action'] }}">
                        <td><strong>{{ $row['family'] }}</strong><br>{{ $row['name'] }}<br><span class="badge bg-info">{{ __('admin_p1.current') }}</span> <span class="badge bg-{{ $row['active'] ? 'success' : 'secondary' }}">{{ $row['active'] ? __('Active') : __('Inactive') }}</span></td>
                        <td><details><summary>{{ __('admin_p3.technical') }}</summary><code dir="ltr">{{ $row['tool_code'] }} / {{ $row['action'] }}</code><br>
                            <small dir="ltr">Tool #{{ $row['tool_id'] ?? '—' }} · Action #{{ $row['action_id'] ?? '—' }} · {{ $row['metric'] ?? '—' }}</small>
                            <details><summary>{{ __('admin_p1.routes') }}</summary><div dir="ltr"><code>{{ $row['route'] }}</code><br><code>{{ $row['path'] }}</code><br><code>/api/v2/{{ $row['service'] }}</code><br><code>{{ $row['scope'] }}</code></div></details>
                        </details></td>
                        @foreach(['app', 'api'] as $channel)
                            <td><bdi dir="ltr">{{ $row['channels'][$channel]['price_exists'] ? number_format($row['channels'][$channel]['credits']) : '—' }}</bdi>
                                @if($row['channels'][$channel]['price_exists'])
                                    <small class="d-block" dir="ltr">#{{ $row['channels'][$channel]['rule_id'] }} · {{ $row['channels'][$channel]['rule_channel'] }} · P{{ $row['channels'][$channel]['priority'] }} · {{ $row['channels'][$channel]['rule_plan_id'] ? __('admin_p1.plan') : __('admin_p1.global') }}</small>
                                @endif
                            </td>
                        @endforeach
                        <td><small>
                            {{ __('admin_p1.active') }}: {{ $row['active'] ? __('admin_p1.yes') : __('admin_p1.no') }}<br>
                            {{ __('admin_p1.app_entitlement') }}: {{ $row['channels']['app']['entitlement'] ? __('admin_p1.yes') : __('admin_p1.no') }}<br>
                            {{ __('admin_p1.api_entitlement') }}: {{ $row['channels']['api']['entitlement'] ? __('admin_p1.yes') : __('admin_p1.no') }}<br>
                            {{ __('admin_p1.scope') }}: {{ $row['scope_allowed'] ? __('admin_p1.yes') : __('admin_p1.no') }}<br>
                            {{ __('admin_p1.effective') }} API: {{ $row['api_effective'] ? __('admin_p1.yes') : __('admin_p1.no') }}
                        </small></td>
                        <td>@forelse($row['diagnostics'] as $diagnostic)<div class="small text-warning">{{ __('admin_p1.'.$diagnostic) }}</div>@empty<span>{{ __('admin_p1.covered') }}</span>@endforelse</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <p class="small">{{ __('admin_p1.quote_note') }}</p>
        @endif
        <details><summary>{{ __('admin_p1.limits') }}</summary>
            <p>{{ __('admin_p1.limits_text') }}</p>
            <p>{{ __('admin_p1.runtime_limits') }}</p>
        </details>
    </div>
</section>
