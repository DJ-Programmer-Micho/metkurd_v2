@php
    $preview = $this->v2Preview;
    $workspace = app(\App\Support\Admin\AdminServiceWorkspace::class);
    $actionRecords = \App\Models\ToolAction::whereIn('id', array_filter(array_column($preview['rows'] ?? [], 'action_id')))->get()->keyBy('id');
    $planCustomer = isset($preview['plan']) ? app(\App\Services\Admin\AdminV2Catalog::class)->planCustomer($preview['plan']) : null;
@endphp
<section class="card admin-service-workspace" data-service-overview>
    <div class="card-body">
        <h2 class="admin-section-heading">{{ __('admin_service.catalog') }}</h2>
        <p class="text-muted">{{ __('admin_service.hierarchy') }}</p>
        <div class="row g-3 mb-3"><div class="col-sm-6 col-lg-4">
            <label for="service-preview-plan" class="form-label">{{ __('admin_service.preview_plan') }}</label>
            <select id="service-preview-plan" class="form-select" wire:model.live="v2PlanId">
                @foreach($this->v2Plans as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </select>
        </div><div class="col-sm-6 col-lg-8"><p class="small text-muted">{{ __('admin_service.availability_help') }}</p>
            <span class="badge bg-secondary"><bdi>App</bdi>: {{ config('metkurd_v2.enabled') ? __('Enabled') : __('Disabled') }}</span>
            <span class="badge bg-secondary"><bdi>API</bdi>: {{ config('customer_api.v2_enabled') ? __('Enabled') : __('Disabled') }}</span>
        </div></div>
        @if(isset($preview['error']))<p role="alert">{{ __('admin_p1.sample_error') }}</p>
        @elseif($preview)
            @foreach(collect($preview['rows'])->groupBy('web_service') as $family => $rows)
                <h3 class="h6 mt-4">{{ \App\Support\Admin\AdminServiceWorkspace::family($family) }}</h3>
                <div class="table-responsive"><table class="table align-middle admin-service-table">
                    <thead><tr><th>{{ __('admin_service.product') }}</th><th>{{ __('Metric') }}</th><th>{{ __('admin_service.service_state') }}</th><th><bdi>App</bdi></th><th><bdi>API</bdi></th><th>{{ __('admin_service.public_state') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
                    @foreach($rows as $row)
                        @php($publicState = $workspace->publicStatus($row['action']))
                        <tr wire:key="service-overview-{{ $row['action_id'] ?? $row['slug'] }}">
                            <td><strong dir="auto">{{ $row['name'] }}</strong><small class="d-block text-muted">{{ __('Tool Action') }}: {{ $actionRecords->get($row['action_id'])?->name ?? __('Unknown Action') }}</small>
                                @foreach(app(\App\Support\Admin\AdminServiceLimits::class)->reference($row['action_id']) as $label => $value)<small class="d-block text-muted">{{ __($label) }}: {{ number_format($value) }}</small>@endforeach
                            </td>
                            <td>{{ $this->metricLabel($row['metric']) }}</td>
                            <td><x-admin-status-badge :tone="$row['active'] ? 'success' : 'warning'">{{ __('admin_service.'.($row['active'] ? 'active' : 'disabled')) }}</x-admin-status-badge></td>
                            @foreach(['app', 'api'] as $channel)
                                <td><x-admin-status-badge :tone="($channel === 'api' ? $row['api_effective'] : $row['channels']['app']['entitlement']) ? 'success' : 'secondary'">{{ ($channel === 'api' ? $row['api_effective'] : $row['channels']['app']['entitlement']) ? __('Allowed') : __('admin_customer.unavailable') }}</x-admin-status-badge>
                                    <small class="d-block text-muted">{{ __('admin_service.'.($row['channels'][$channel]['price_exists'] ? 'price_configured' : 'price_missing')) }}</small>
                                    @if(in_array($row['tool']['kind'] ?? '', ['omni_tts', 'omni_clone'], true))<small class="d-block">{{ __('admin_service.characters', ['count' => number_format(app(\App\Services\MetKurd\V2\InputBoundary::class)->characterLimit($planCustomer, $row['action'], $channel))]) }}</small>@endif
                                </td>
                            @endforeach
                            <td><x-admin-status-badge :tone="$publicState === 'public' ? 'info' : 'secondary'">{{ __('admin_service.'.$publicState) }}</x-admin-status-badge></td>
                            <td><div class="d-flex flex-wrap gap-2">
                                @if($row['action_id'])
                                    <button class="btn btn-sm btn-outline-primary" wire:click="openActionEditModal({{ $row['action_id'] }})" @disabled(! \App\Support\Admin\AdminUiAccess::can('admin.catalog'))>{{ __('Edit') }}</button>
                                    <a wire:navigate href="{{ route('admin.services.entitlements', ['locale' => app()->getLocale(), 'action' => $row['action_id'], 'plan' => $preview['plan']->id]) }}">{{ __('admin_service.access_limits') }}</a>
                                    <a wire:navigate href="{{ route('admin.services.pricing', ['locale' => app()->getLocale(), 'action' => $row['action_id'], 'plan' => $preview['plan']->id]) }}">{{ __('Pricing') }}</a>
                                @endif
                            </div></td>
                        </tr>
                    @endforeach
                    </tbody></table></div>
            @endforeach
        @endif
        <p class="small text-muted">{{ __('admin_service.public_help') }}</p>
        <a wire:navigate class="btn btn-sm btn-outline-secondary" href="{{ route('admin.landing.tools', ['locale' => app()->getLocale()]) }}">{{ __('admin_service.landing_editor') }}</a>
    </div>
</section>
