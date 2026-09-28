@php($matrix = $this->entitlementMatrix)
<section class="card admin-service-workspace" data-entitlement-matrix>
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-3"><h2 class="admin-section-heading">{{ __('admin_service.matrix') }}</h2>
            <div class="btn-group" role="group" aria-label="{{ __('Channel') }}">@foreach(['app', 'api'] as $channel)<button type="button" class="btn btn-sm {{ $matrix['channel'] === $channel ? 'btn-primary' : 'btn-outline-primary' }}" wire:click="$set('matrixChannel', '{{ $channel }}')" aria-pressed="{{ $matrix['channel'] === $channel ? 'true' : 'false' }}"><bdi>{{ strtoupper($channel) }}</bdi></button>@endforeach</div>
        </div>
        <p class="text-muted small">{{ __('admin_service.matrix_help') }}</p>
        <p class="text-muted small">{{ __('admin_service.override_help') }}</p>
        <div class="table-responsive"><table class="table align-middle admin-service-matrix">
            <thead><tr><th>{{ __('admin_service.product') }}</th>@foreach($matrix['plans'] as $plan)<th dir="auto">{{ $plan->name }} @if(!$plan->is_active)<small class="d-block">{{ __('Inactive') }}</small>@endif</th>@endforeach</tr></thead>
            <tbody>@foreach($matrix['rows'] as $row)
                @if($this->actionFilter === 'all' || (int) $this->actionFilter === $row['action_id'])
                <tr wire:key="matrix-{{ $matrix['channel'] }}-{{ $row['action'] }}">
                    <th scope="row"><strong dir="auto">{{ $row['name'] }}</strong><small class="d-block text-muted">{{ $row['family'] }}</small>@if(!$row['active'])<span class="badge bg-warning text-dark">{{ __('admin_service.disabled') }}</span>@endif</th>
                    @foreach($matrix['plans'] as $plan)
                        @php($cell = $row['cells'][$plan->id])
                        <td data-matrix-cell="{{ $plan->id }}-{{ $row['action_id'] }}-{{ $matrix['channel'] }}">
                            <x-admin-status-badge :tone="$cell['decision'] === 'deny' ? 'danger' : ($cell['effective'] ? 'success' : 'secondary')">{{ __('admin_service.decision_'.$cell['decision']) }}</x-admin-status-badge>
                            @if($cell['source'] === 'all')<small class="d-block text-muted">{{ __('admin_service.inherited_all') }}</small>@endif
                            @if($cell['characters'] !== null)<small class="d-block">{{ __('admin_service.characters', ['count' => number_format($cell['characters'])]) }} @if($cell['configured_characters'] === null)<span class="text-muted">({{ __('admin_service.fallback') }})</span>@endif</small>@endif
                            @if($row['action_id'])<button type="button" class="btn btn-sm btn-link px-0" wire:click="openMatrixEntitlement({{ $plan->id }}, {{ $row['action_id'] }}, '{{ $matrix['channel'] }}')" @disabled(! \App\Support\Admin\AdminUiAccess::can('admin.pricing'))>{{ __('Edit') }} <bdi>{{ strtoupper($matrix['channel']) }}</bdi></button>@endif
                        </td>
                    @endforeach
                </tr>
                @endif
            @endforeach</tbody>
        </table></div>
    </div>
</section>
