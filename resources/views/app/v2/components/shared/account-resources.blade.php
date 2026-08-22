<div class="v2-account-resource-wrap" wire:key="v2-account-resources-{{ $refreshKey }}">
    <details class="v2-account-resources" open x-data x-init="if (window.matchMedia('(max-width: 767.98px)').matches) $el.open = false">
        <summary><i class="ri-wallet-3-line"></i>{{ __('Credits & Resources') }}<i class="ri-arrow-down-s-line"></i></summary>
        <div class="v2-resource-list">
            @foreach($this->rows as $row)
                <div class="v2-resource-row is-{{ $row['tone'] }}">
                    <div class="v2-resource-copy"><a wire:navigate href="{{ $row['href'] }}">{{ $row['label'] }}</a><span dir="ltr">{{ $row['value'] }}</span></div>
                    <div class="v2-resource-progress" dir="ltr" role="progressbar" aria-label="{{ $row['label'] }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $row['percent'] }}"><span style="width: {{ $row['percent'] }}%"></span></div>
                </div>
            @endforeach
        </div>
    </details>
</div>
