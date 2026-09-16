@props(['overview'])
<div class="row g-3 mb-3">
    @foreach (['app_credits', 'api_credits'] as $wallet)
        <div class="col-md-6"><section class="card h-100 border-top border-3 border-info"><div class="card-body">
            <h5>{{ __('admin_p2.'.$wallet) }}</h5>
            <p class="fs-2 fw-semibold mb-1" dir="ltr">{{ isset($overview[$wallet]['balance_credits']) ? number_format($overview[$wallet]['balance_credits']) : __('admin_p2.not_recorded') }}</p>
            <small class="text-muted">{{ __('admin_p2.balance_credits') }}</small>
            <x-admin-operation-values :values="\Illuminate\Support\Arr::only($overview[$wallet], ['subscription_balance_credits', 'addon_balance_credits', 'held_amount', 'lifetime_earned', 'lifetime_spent', 'lifetime_refunded', 'latest_activity'])" />
        </div></section></div>
    @endforeach
    @foreach (['identity', 'plan', 'storage'] as $title)
        <div class="col-xl-4 col-md-6"><section class="card h-100"><div class="card-body">
            <h5>{{ __('admin_p2.'.$title) }}</h5><x-admin-operation-values :values="$overview[$title]" />
        </div></section></div>
    @endforeach
</div>
<p class="text-muted small">{{ __('admin_p2.wallet_notice') }}</p>
<details class="card mb-3"><summary class="card-header">{{ __('admin_p2.api_access') }} · {{ __('admin_p2.effective_access') }}</summary>
    <div class="card-body row"><div class="col-md-6"><x-admin-operation-values :values="$overview['api_access']" /></div><div class="col-md-6"><x-admin-operation-values :values="$overview['effective_access']" /></div></div>
</details>
