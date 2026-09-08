@props(['values'])
<dl class="row small mb-0 mt-2">
    @foreach ($values as $key => $value)
        <dt class="col-sm-5">{{ \Illuminate\Support\Facades\Lang::has('admin_p2.'.$key) ? __('admin_p2.'.$key) : $key }}</dt>
        <dd class="col-sm-7 text-break" dir="{{ in_array($key, ['username', 'customer', 'name', 'filename', 'reason', 'product', 'plan', 'previous_plan']) ? 'auto' : 'ltr' }}">
            @if (is_array($value))
                @if (array_is_list($value)) {{ implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value)) }}
                @else <x-admin-operation-values :values="$value" /> @endif
            @elseif (is_bool($value)) {{ __('admin_p2.'.($value ? 'yes' : 'no')) }}
            @else {{ in_array($key, ['outcome', 'operation_status', 'status', 'local_lifecycle', 'provider_status', 'persisted_result', 'retention_mode', 'storage_mode', 'object_present', 'fulfillment', 'direction']) && is_string($value) && \Illuminate\Support\Facades\Lang::has('admin_p2.'.$value) ? __('admin_p2.'.$value) : ($value ?? __('admin_p2.not_recorded')) }} @endif
        </dd>
    @endforeach
</dl>
