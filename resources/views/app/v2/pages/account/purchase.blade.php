<div class="v2-account v2-purchase" data-v2-purchase>
    @php
        $data = $this->catalog;
        $kind = $data['kind'];
    @endphp
    <x-slot:title>{{ __('purchase_v2.'.$kind.'_title') }} | MetKurd</x-slot:title>
    @include('app.v2.pages.account.navigation', ['active' => 'purchase'])
    <header class="v2-account-heading"><span class="v2-account-eyebrow">{{ __('account_v2.account') }}</span><h1>{{ __('purchase_v2.'.$kind.'_title') }}</h1><p>{{ __('purchase_v2.'.$kind.'_intro') }}</p></header>
    @if(!$data['available'])
        <section class="v2-account-panel" role="alert"><p>{{ __('account_v2.unavailable') }}</p><button wire:click="$refresh" class="btn btn-info">{{ __('account_v2.retry') }}</button></section>
    @else
        @php
            $state = $data['state']; $sub = $state['subscription']; $end = $state['period_ends_at'];
            $currentFree = $kind === 'storage' ? $data['current']->priceIqdAmount() <= 0 : (bool) $data['current']->is_free;
            $currentCycle = data_get($sub?->meta, 'billing_cycle', 'monthly');
            $currentCycle = in_array($currentCycle, ['monthly','yearly','lifetime','hourly'], true) ? __('purchase_v2.'.$currentCycle) : __('account_v2.unknown');
        @endphp
        @if($kind === 'service')@include('app.v2.pages.account.agreement-status', ['state' => $state])@endif
        @if(in_array($kind, ['service', 'storage']))@include('app.v2.pages.account.subscription-lifecycle', ['lifecycleKind' => $kind])@endif
        <section class="v2-account-panel v2-purchase-summary">
            <div><span class="v2-account-label">{{ __('Current Plan') }}</span><h2 dir="auto">{{ $data['current']->name }}</h2>
                <span class="v2-account-badge">{{ $data['complimentary'] ? __('account_v2.complimentary') : ($state['cancellation_scheduled'] ? __('account_v2.cancel_pending') : ($currentFree ? __('Free') : ($end?->isPast() ? __('account_v2.expired') : __('account_v2.active')))) }}</span>
                @if($end && !$currentFree)<p>{{ __($end->isPast() ? 'account_v2.recorded_end' : 'account_v2.access_until') }} <b dir="ltr">{{ (($state['externally_managed'] ?? false) ? $end->copy()->subSecond() : $end)->format('Y-m-d H:i') }}</b></p>@endif
                @if($state['externally_managed'] ?? false)<p>{{ __('agreement.customer_help') }}</p>
                @elseif($sub && !$data['complimentary'] && !$currentFree)<small>{{ __($state['cancellation_scheduled'] ? 'purchase_v2.renew_off' : ($sub->auto_renew ? 'purchase_v2.recurring' : 'purchase_v2.one_time')) }} · {{ $currentCycle }}</small>@endif
            </div>
            <div class="v2-account-actions"><a wire:navigate href="{{ route('app.v2.billing', ['locale' => app()->getLocale()]) }}" class="btn btn-outline-info">{{ __('purchase_v2.history') }}</a>
                @if($kind === 'storage')<a wire:navigate href="{{ route('app.v2.storage', ['locale' => app()->getLocale()]) }}" class="btn btn-outline-info">{{ __('My Storage') }}</a>@endif
                @if($kind !== 'addon' && $state['cancelable'] && !$data['complimentary'])<button class="btn btn-link" wire:click="$set('confirmCancellation', true)">{{ __('account_v2.cancel_renewal') }}</button>@endif
            </div>
        </section>
        @if($confirmCancellation)
            <section class="v2-account-panel" role="group" aria-label="{{ __('account_v2.cancel_renewal') }}"><p>{{ __('account_v2.cancel_help') }}</p>@if($kind === 'storage')<p>{{ __('purchase_v2.quota_warning') }}</p>@endif<button class="btn btn-outline-warning" wire:click="cancel" wire:loading.attr="disabled">{{ __('account_v2.confirm_cancel') }}</button> <button class="btn btn-link" wire:click="$set('confirmCancellation', false)">{{ __('account_v2.keep_plan') }}</button></section>
        @endif
        @error('cancellation')<p class="v2-account-error" role="alert">{{ $message }}</p>@enderror
        @if($kind === 'storage')
            <section class="v2-account-panel"><div class="v2-purchase-metrics"><div><span>{{ __('purchase_v2.used') }}</span><strong dir="ltr">{{ number_format($state['used_bytes'] / 1048576, 1) }} MB</strong></div><div><span>{{ __('purchase_v2.quota') }}</span><strong dir="ltr">{{ number_format($state['current_limit_mb']) }} MB</strong></div><div><span>{{ __('purchase_v2.remaining') }}</span><strong dir="ltr">{{ number_format(max(0, $state['current_limit_bytes'] - $state['used_bytes']) / 1048576, 1) }} MB</strong></div></div>
            @if($state['over_quota'])<p class="v2-account-notice">{{ __('purchase_v2.quota_warning') }}</p>@endif</section>
        @else
            <div class="v2-purchase-wallets">@foreach(($kind === 'addon' ? ['app'] : ['app','api']) as $channel)
                <section class="v2-account-panel"><h2>{{ __('account_v2.'.$channel.'_credits') }}</h2><div class="v2-purchase-metrics">@foreach(['subscription_balance_credits' => 'subscription_credits', 'addon_balance_credits' => 'addon_credits', 'balance_credits' => 'available_credits'] as $field => $label)<div><span>{{ __('account_v2.'.$label) }}</span><strong dir="ltr">{{ number_format($data['wallets']->get($channel)?->$field ?? 0) }}</strong></div>@endforeach</div></section>
            @endforeach</div>
        @endif
        @if($data['pendingUrl'])<div class="v2-account-notice"><p>{{ __($data['pendingState'] === 'review' ? 'payment_v2.block_review' : 'payment_v2.block_active') }}</p><a class="btn btn-outline-info" href="{{ $data['pendingUrl'] }}">{{ __('purchase_v2.resume') }}</a></div>@endif
        @if($data['blocked'])<p class="v2-account-notice" role="alert">{{ __('purchase_v2.storage_replace_blocked') }}</p>@endif
        @if(!$data['access']['allowed'])<p class="v2-account-notice">{{ $data['access']['reason'] }} <a wire:navigate href="{{ route('app.v2.subscription-plans', ['locale' => app()->getLocale()]) }}">{{ __('purchase_v2.service_title') }}</a></p>@endif
        @if($kind !== 'addon')
            <section class="v2-purchase-controls" aria-label="{{ __('purchase_v2.options') }}">
                <div><label class="v2-account-label" for="purchase-cycle">{{ __('purchase_v2.interval') }}</label><select id="purchase-cycle" wire:model.live="cycle" class="form-select">@foreach($data['intervals'] as $interval)<option value="{{ $interval }}">{{ __('purchase_v2.'.$interval) }}</option>@endforeach</select></div>
                @if($kind === 'service' && count($data['modes']) === 1)
                    <div><span class="v2-account-label">{{ __('purchase_v2.mode') }}</span><p data-purchase-mode-readonly>{{ __('purchase_v2.'.$data['modes'][0]) }}</p></div>
                @elseif(count($data['modes']) > 0)
                    <div><label class="v2-account-label" for="purchase-mode">{{ __('purchase_v2.mode') }}</label><select id="purchase-mode" wire:model.live="mode" class="form-select"><option value="all">{{ __('purchase_v2.all_modes') }}</option>@foreach($data['modes'] as $mode)<option value="{{ $mode }}">{{ __('purchase_v2.'.$mode) }}</option>@endforeach</select></div>
                    <small>{{ __('purchase_v2.mode_help') }}</small>
                @endif
            </section>
        @endif
        <div wire:loading role="status">{{ __('account_v2.updating') }}</div>
        <div class="v2-purchase-grid">
            @forelse($data['items'] as $item)
                <article class="v2-account-panel v2-purchase-card {{ $item['same'] ? 'is-current' : '' }}" wire:key="purchase-{{ $kind }}-{{ $item['id'] }}">
                    <div><h2 dir="auto">{{ $item['name'] }}</h2>@if($item['recommended'])<span class="v2-account-badge">{{ __('purchase_v2.recommended') }}</span>@endif @if($item['same'])<span class="v2-account-badge is-good">{{ __('Current Plan') }}</span>@endif</div>
                    <div class="v2-purchase-price" dir="auto">{{ $item['price']['display_label'] }}</div>@if($item['price']['has_localized_estimate'])<small dir="ltr">{{ $item['price']['iqd_label'] }}</small>@endif
                    <p>{{ $item['free'] ? __('Free') : __('purchase_v2.'.$item['mode']) }} @if($kind !== 'addon') · {{ __('purchase_v2.'.$item['cycle']) }} @endif</p>
                    @if($kind === 'service')<dl class="v2-account-breakdown"><div><dt>{{ __('account_v2.app_credits') }} / {{ __('purchase_v2.month') }}</dt><dd dir="ltr">{{ number_format($item['app']) }}</dd></div><div><dt>{{ __('account_v2.api_credits') }} / {{ __('purchase_v2.month') }}</dt><dd dir="ltr">{{ number_format($item['api']) }}</dd></div><div><dt>{{ __('purchase_v2.concurrent') }}</dt><dd>{{ $item['jobs'] }}</dd></div></dl>
                    @elseif($kind === 'storage')<strong dir="ltr">{{ number_format($item['quota']) }} MB</strong>
                    @else<strong>{{ number_format($item['app']) }} {{ __('account_v2.addon_credits') }}</strong>@endif
                    @if($item['features'])<ul>@foreach($item['features'] as $benefit)@if(is_string($benefit))<li dir="auto">{{ $benefit }}</li>@endif @endforeach</ul>@endif
                    @if($item['free'] && !$item['same'])<p>{{ __('purchase_v2.free_change') }}</p>
                    @elseif(!$item['same'])<button class="btn btn-info" wire:click="select({{ $item['id'] }})" wire:loading.attr="disabled" @disabled(!$data['access']['allowed'])>{{ __('purchase_v2.'.$item['action']) }}</button>@endif
                    @if(!$item['free'] && !$item['methods'])<small>{{ __('purchase_v2.unavailable') }}</small>@endif
                </article>
            @empty<section class="v2-account-panel"><p>{{ __('purchase_v2.empty') }}</p></section>@endforelse
        </div>
        @if($data['selected'])
            @php
                $selected = $data['selected'];
            @endphp
            <section class="v2-account-panel v2-purchase-confirm" role="region" aria-label="{{ __('purchase_v2.review') }}" tabindex="-1" x-init="$el.scrollIntoView({block:'center', behavior:'smooth'}); $el.focus({preventScroll:true})">
                @if($data['pendingUrl'])<p class="v2-account-notice">{{ __($data['pendingState'] === 'review' ? 'payment_v2.block_review' : 'payment_v2.block_active') }} <a href="{{ $data['pendingUrl'] }}">{{ __('purchase_v2.resume') }}</a></p>@endif
                @if($data['blocked'])<p class="v2-account-notice">{{ __('purchase_v2.storage_replace_blocked') }}</p>@endif
                @if(!$selected['supported'])<p class="v2-account-notice">{{ __('purchase_v2.storage_interval_blocked') }}</p>@endif
                <div class="v2-account-section-heading"><h2>{{ __('purchase_v2.review') }} · <span dir="auto">{{ $selected['name'] }}</span></h2><button class="btn btn-link" wire:click="$set('selectedId', null)">{{ __('purchase_v2.close') }}</button></div>
                <p>{{ __('purchase_v2.'.$selected['mode']) }} @if($kind !== 'addon') · {{ __('purchase_v2.'.$selected['cycle']) }} @endif</p><p>{{ __('purchase_v2.'.$selected['mode'].'_help') }}</p>
                @if($kind === 'service')
                    <p class="v2-account-notice">{{ __('purchase_v2.credit_policy') }}</p>
                    <div class="v2-purchase-wallets">@foreach(['app','api'] as $channel)<div><h3>{{ __('account_v2.'.$channel.'_credits') }}</h3><dl class="v2-account-breakdown"><div><dt>{{ __('purchase_v2.current_subscription') }}</dt><dd>{{ number_format($data['wallets']->get($channel)?->subscription_balance_credits ?? 0) }}</dd></div><div><dt>{{ __('purchase_v2.allowance') }}</dt><dd>{{ number_format($selected['preview'][$channel]['subscription']) }}</dd></div><div><dt>{{ __('purchase_v2.result_subscription') }}</dt><dd>{{ number_format($selected['preview'][$channel]['subscription']) }}</dd></div><div><dt>{{ __('purchase_v2.retained_addons') }}</dt><dd>{{ number_format($selected['preview'][$channel]['addon']) }}</dd></div><div><dt>{{ __('purchase_v2.result_total') }}</dt><dd>{{ number_format($selected['preview'][$channel]['total']) }}</dd></div></dl></div>@endforeach</div>
                    <p>{{ __('purchase_v2.switch_help') }}</p>
                @elseif($kind === 'storage')<p>{{ __('purchase_v2.switch_help') }}</p>@if($state['used_bytes'] > $selected['quota'] * 1048576)<p class="v2-account-notice">{{ __('purchase_v2.quota_warning') }}</p>@endif
                @else<p>{{ __('purchase_v2.addon_help') }}</p>@endif
                <div><label class="v2-account-label" for="purchase-method">{{ __('account_v2.payment_method') }}</label><select id="purchase-method" class="form-select" wire:model.live="paymentMethod">@foreach($selected['methods'] as $method)<option value="{{ $method['code'] }}">{{ $method['name'] }}</option>@endforeach</select></div>
                @if($selected['total'])
                    <dl class="v2-account-breakdown">
                        <div><dt>{{ __('purchase_v2.subtotal') }}</dt><dd dir="auto">{{ $selected['price']['display_label'] }}</dd></div>
                        <div><dt>{{ __('purchase_v2.fees') }}</dt><dd dir="auto">{{ $selected['fees']['display_label'] }}</dd></div>
                        <div><dt>{{ __('purchase_v2.total') }}</dt><dd dir="auto">{{ $selected['total']['display_label'] }}</dd></div>
                    </dl>
                @endif
                <small>{{ __('purchase_v2.quote_help') }}</small>
                @foreach(['checkout','paymentMethod','selectedId'] as $field)@error($field)<p class="v2-account-error" role="alert">{{ $message }}</p>@enderror @endforeach
                <div class="v2-account-actions"><button class="btn btn-info" wire:click="purchase" wire:loading.attr="disabled" @disabled(!$selected['supported'] || !$selected['methods'] || $data['blocked'] || !$data['access']['allowed'] || $data['pendingUrl'])><span wire:loading.remove wire:target="purchase">{{ __('purchase_v2.pay') }}</span><span wire:loading wire:target="purchase">{{ __('purchase_v2.opening') }}</span></button></div>
            </section>
        @endif
    @endif
</div>
