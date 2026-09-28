@props(['evidence'])
<section class="card mt-3 admin-billing-evidence" aria-label="{{ __('admin_billing.timeline') }}">
    <div class="card-header"><h5 class="mb-0">{{ __('admin_billing.timeline') }}</h5></div>
    <div class="card-body">
    @if($evidence['restricted'])<p>{{ __('admin_billing.restricted') }}</p>
    @else
        <p class="text-muted small">{{ __('admin_billing.bounded_evidence') }}</p>
        @foreach(['events', 'allocations'] as $kind)
            @if($page = $evidence[$kind])
                <h6 id="billing-{{ $kind }}">{{ __('admin_billing.'.$kind) }} <span class="text-muted">({{ $page->total() }})</span></h6>
                @forelse($page as $item)
                    <details class="border rounded p-2 mb-2"><summary><bdi>#{{ $item['id'] }}</bdi> · <bdi>{{ $item['event_type'] ?? $item['allocation_type'] }}</bdi> · <bdi>{{ $item['created_at'] ?? $item['cycle_started_at'] }}</bdi></summary><x-admin-operation-values :values="$item" />
                        @if($kind === 'allocations')<a wire:navigate href="{{ route('admin.operations', ['locale' => app()->getLocale(), 'section' => 'subscriptions', 'billingRecord' => $item['subscription_id'], 'customerFilter' => $evidence['customer_id']]) }}">{{ __('admin_p2.subscriptions') }} <bdi>#{{ $item['subscription_id'] }}</bdi></a>@endif
                    </details>
                @empty<p class="text-muted">{{ __('admin_p2.empty') }}</p>@endforelse
                {{ $page->links() }}
            @endif
        @endforeach
    @endif
    </div>
</section>
