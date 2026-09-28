@props(['rows', 'section'])
<div class="table-responsive admin-developer-table">
<table class="table table-hover align-middle">
    <thead><tr>@foreach(['record', 'state', 'evidence', 'trace'] as $label)<th>{{ __('admin_developer.'.$label) }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($rows as $row)
        <tr wire:key="developer-{{ $section }}-{{ $row['id'] }}">
            <td>
                <strong dir="auto">{{ $row['product'] ?? $row['name'] ?? __('admin_p2.'.$section) }}</strong>
                <small class="d-block"><bdi dir="ltr">{{ $row['id'] }}</bdi></small>
                <a wire:navigate dir="auto" href="{{ route('admin.customers.detail', ['locale'=>app()->getLocale(), 'customer'=>$row['customer_id']]) }}">{{ $row['customer'] ?? $row['customer_id'] }}</a>
                @if($row['channel'])<span class="badge bg-info-subtle text-info"><bdi dir="ltr">{{ strtoupper($row['channel']) }}</bdi></span>@endif
                @if(!empty($row['tool_action']))<small class="d-block"><bdi dir="ltr">{{ $row['tool_action'] }}</bdi></small>@endif
            </td>
            <td>
                <x-admin-operation-status :row="$row" />
                @if(isset($row['reservation_state']))<p>{{ __('admin_developer.reservation_state') }}: <strong>{{ __('admin_p2.'.$row['reservation_state']) }}</strong></p>@endif
                @foreach($row['problems'] ?? [] as $problem)<p class="text-warning mb-1">{{ __('admin_developer.'.$problem) }}</p>@endforeach
                @if(!empty($row['problems']))<p class="small text-muted">{{ __('admin_developer.next_action') }}</p>@endif
            </td>
            <td>
                <x-admin-developer-fields :row="$row" :fields="['wallet_type','app_charged','held_amount','settled_amount','released_amount','result_count','key_prefix','client_identity','scopes','purpose','mime','size_bytes','result_kind']" />
                <details class="mt-2"><summary>{{ __('admin_p2.detail') }}</summary>
                    <x-admin-developer-fields :row="$row" :fields="['api_status','ml_job_id','api_job_id','api_result_id','reservation_id','reserved_amount','retention_mode','storage_mode','object_present','created_at','updated_at','finished_at','api_completed_at','settled_at','released_at','last_used_at','revoked_at','expires_at','deleted_at','api_result_deleted_at']" />
                </details>
            </td>
            <td><div class="d-flex flex-wrap gap-2">
                @php($scope = ['locale'=>app()->getLocale(), 'customerFilter'=>$row['customer_id']])
                @if($row['api_job_id'])
                    @foreach(['api','reservations','files','audit'] as $target)<a wire:navigate class="btn btn-sm btn-outline-info" href="{{ route('admin.operations', $scope + ['section'=>$target, 'apiJob'=>$row['api_job_id']]) }}">{{ __('admin_p2.'.$target) }}</a>@endforeach
                @elseif($row['ml_job_id'])
                    @foreach(['files','audit'] as $target)<a wire:navigate class="btn btn-sm btn-outline-info" href="{{ route('admin.operations', $scope + ['section'=>$target, 'job'=>$row['ml_job_id']]) }}">{{ __('admin_p2.'.$target) }}</a>@endforeach
                @endif
                @if($row['ml_job_id'])<a wire:navigate class="btn btn-sm btn-outline-info" href="{{ route('admin.operations', $scope + ['section'=>'jobs', 'job'=>$row['ml_job_id']]) }}">{{ __('admin_developer.ml_job') }}</a>@endif
                @if($row['ml_job_id'])<a wire:navigate class="btn btn-sm btn-outline-info" href="{{ route('admin.operations', $scope + ['section'=>'ledger', 'job'=>$row['ml_job_id']]) }}">{{ __('admin_p2.ledger') }}</a>@endif
                @foreach(['key_id'=>['keys','keyId'], 'connection_id'=>['mcp','connectionId']] as $field=>$target)
                    @if($row[$field])
                        <a wire:navigate class="btn btn-sm btn-outline-info" href="{{ route('admin.operations', $scope + ['section'=>$target[0], $target[1]=>$row[$field]]) }}">{{ __('admin_p2.'.$target[0]) }}</a>
                        @if(in_array($section,['keys','mcp']))<a wire:navigate class="btn btn-sm btn-outline-info" href="{{ route('admin.operations', $scope + ['section'=>'api', $target[1]=>$row[$field]]) }}">{{ __('admin_developer.activity') }}</a><a wire:navigate class="btn btn-sm btn-outline-info" href="{{ route('admin.operations', $scope + ['section'=>'audit', $target[1]=>$row[$field]]) }}">{{ __('admin_p2.audit') }}</a>@endif
                    @endif
                @endforeach
            </div></td>
        </tr>
    @empty<tr><td colspan="4" class="text-muted p-4">{{ __('admin_p2.empty') }}</td></tr>@endforelse
    </tbody>
</table>
</div>
