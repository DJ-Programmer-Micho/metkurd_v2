@if($retention['api'])
    <small class="d-block">
        <span class="badge d-inline-block bg-info-subtle text-info"><bdi>API</bdi></span>
        <span class="badge d-inline-block bg-secondary-subtle text-secondary">{{ $retention['temporary'] ? __('Temporary') : __('Permanent') }}</span>
    </small>
    @if($retention['expires_at'])
        <small class="d-block text-wrap text-break">{{ __('Expires') }}: <bdi><time datetime="{{ $retention['expires_at'] }}">{{ $retention['expires_at'] }}</time></bdi></small>
    @endif
    @if($retention['temporary'])
        <small class="d-block text-wrap">{{ __('Excluded from permanent storage quota') }}</small>
    @endif
@endif
