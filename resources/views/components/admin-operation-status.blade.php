@props(['row'])
<div class="d-flex flex-wrap gap-2 mb-2">
    @foreach (['local_lifecycle' => 'local_status', 'status' => 'record_status', 'provider_status' => 'provider_evidence', 'persisted_result' => 'persistence', 'fulfillment' => 'financial_state'] as $key => $label)
        @if(isset($row[$key]) && !($key === 'status' && isset($row['local_lifecycle'])))
            @php($value = (string) $row[$key])
            @php($tone = in_array($value, ['done','completed','paid','fulfilled','persisted','active','settled','released']) ? 'success' : (in_array($value, ['failed','disabled','cancelled','expired','invalid','revoked','delete_failed']) ? 'danger' : (in_array($value, ['inactive','deleted','not_recorded','not_confirmed']) ? 'secondary' : 'warning')))
            <x-admin-status-badge :tone="$tone"><span>{{ __('admin_p3.'.$label) }}:</span> {{ \Illuminate\Support\Facades\Lang::has('admin_p2.'.$value) ? __('admin_p2.'.$value) : $value }}</x-admin-status-badge>
        @endif
    @endforeach
</div>
