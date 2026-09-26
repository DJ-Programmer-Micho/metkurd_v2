<p>{{ __('api_v2.scope') }}: <code dir="ltr">{{ $contract['scope'] }}</code></p>
<p>{{ __('api_v2.billing') }}</p>
<p>{{ __('api_v2.permissions') }}</p>
@if($serviceId === 'harakat')
    <p>{{ __('api_v2.harakat_fields') }}</p>
    <p>{{ __('api_v2.harakat_limit', ['chars' => $contract['limits']['max_chars']]) }}</p>
    <p>{{ __('api_v2.harakat_errors') }}</p>
@else
    <p>{{ __('api_v2.batch_fields') }}</p>
    <p>{{ __('api_v2.batch_limit', ['segments' => $contract['limits']['max_segments'], 'each' => $contract['limits']['max_segment_chars'], 'total' => $contract['limits']['max_total_chars']]) }}</p>
    <p>{{ __('api_v2.pause') }}</p>
    @if($serviceId === 'zeta')
        <p>{{ __('api_v2.zeta_fields') }} <code dir="ltr">GET /api/v2/voices</code></p>
    @else
        <p>{{ __('api_v2.theta_fields') }}</p>
        <p>{{ __('api_v2.reference_limit', ['total' => $contract['limits']['max_project_reference_bytes'] / 1024 / 1024]) }}</p>
        <p>{{ __('api_v2.reference_upload') }}</p>
        <pre dir="ltr">curl -X POST '{{ url('/api/v2/references') }}' \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -F 'file=@reference.wav'</pre>
        <pre dir="ltr">{"reference_id":123,"mime_type":"audio/wav","size_bytes":96044,"expires_at":null}</pre>
        <p>{{ __('api_v2.reference_policy') }}</p>
        <p>{{ __('api_v2.theta_errors') }}</p>
    @endif
    <p>{{ __('api_v2.batch_errors') }}</p>
@endif
<h3 class="h6">{{ __('api_v2.completed_response') }}</h3>
<pre dir="ltr">{{ $documentation->responseExample($serviceId) }}</pre>
