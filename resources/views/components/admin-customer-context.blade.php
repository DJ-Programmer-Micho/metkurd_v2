@props(['customerId', 'name' => null])
@if ((int) $customerId > 0)
<nav class="customer-context d-flex flex-wrap align-items-center gap-2 mb-3" aria-label="{{ __('admin_p3.customer_context') }}" data-admin-context="{{ __('admin_p2.customer').' #'.$customerId }}{{ $name ? ' · '.$name : '' }}">
    <strong dir="auto">{{ __('admin_p2.customer').' #'.$customerId }}{{ $name ? ' · '.$name : '' }}</strong>
    @php($links = [
        ['admin.customers.detail', ['customer' => $customerId], 'overview'],
        ['admin.customers.usage', ['customer' => $customerId], 'usage'],
        ['admin.customers.register', ['customer' => $customerId], 'billing'],
        ['admin.customers.detail', ['customer' => $customerId, 'section' => 'jobs'], 'jobs'],
        ['admin.customers.detail', ['customer' => $customerId, 'section' => 'api'], 'api'],
        ['admin.customers.detail', ['customer' => $customerId, 'section' => 'audit'], 'audit'],
    ])
    @foreach($links as [$routeName, $parameters, $label])
        <a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route($routeName, ['locale' => app()->getLocale()] + $parameters) }}">{{ $label === 'billing' ? __('admin_customer.manage_actions') : __('admin_p3.'.$label) }}</a>
    @endforeach
    <details class="dropdown">
        <summary class="btn btn-sm btn-soft-info">{{ __('admin_ux.more_records') }}</summary>
        <div class="d-flex flex-wrap gap-1 mt-2">
            @foreach (['files', 'subscriptions', 'storage_subscriptions', 'payments', 'orders', 'ledger'] as $section)
                <a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route('admin.customers.detail', ['locale' => app()->getLocale(), 'customer' => $customerId, 'section' => $section]) }}">{{ __('admin_ux.'.$section) }}</a>
            @endforeach
        </div>
    </details>
    <a wire:navigate class="btn btn-sm btn-soft-secondary ms-auto" href="{{ route('admin.customers.list', ['locale' => app()->getLocale()]) }}">{{ __('Back to Customers') }}</a>
</nav>
@endif
