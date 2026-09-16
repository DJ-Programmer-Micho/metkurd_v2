<?php
use Livewire\Attributes\Layout;
new #[Layout('app::v2.layouts.app')] class extends \App\Livewire\Account\PaymentPage {};
?>
<div class="v2-account v2-payment" data-v2-payment>
    @php($checkout = $this->checkout)
    <x-slot:title>{{ __('payment_v2.title') }} | MetKurd</x-slot:title>
    @include('app.v2.pages.account.navigation', ['active' => 'payment'])
    <header class="v2-account-heading"><span class="v2-account-eyebrow">{{ __('account_v2.account') }}</span><h1>{{ __('payment_v2.title') }}</h1><p>{{ __('payment_v2.'.$checkout['stateLabel']) }}</p></header>
    <div class="v2-payment-columns">
        <section class="v2-account-panel"><h2>{{ __('payment_v2.summary') }}</h2>
            <dl class="v2-account-breakdown">
                <div><dt>{{ __('payment_v2.purchase') }}</dt><dd dir="auto">{{ $checkout['name'] }}</dd></div>
                @if($checkout['cycle'])<div><dt>{{ __('purchase_v2.interval') }}</dt><dd>{{ __('purchase_v2.'.$checkout['cycle']) }}</dd></div>@endif
                <div><dt>{{ __('purchase_v2.mode') }}</dt><dd>{{ __('purchase_v2.'.$checkout['mode']) }}</dd></div>
                <div><dt>{{ __('payment_v2.amount') }}</dt><dd dir="ltr">{{ $checkout['amount'] }} {{ $checkout['currency'] }}</dd></div>
                <div><dt>{{ __('account_v2.payment_method') }}</dt><dd>{{ $checkout['method'] }}</dd></div>
                <div><dt>{{ __('payment_v2.created') }}</dt><dd dir="ltr">{{ $checkout['created'] }}</dd></div>
                @if($checkout['expires'] && $checkout['state'] !== 'completed')<div><dt>{{ __('payment_v2.expires') }}</dt><dd dir="ltr">{{ $checkout['expires'] }}</dd></div>@endif
                @foreach(['app' => 'account_v2.app_credits', 'api' => 'account_v2.api_credits', 'credits' => 'account_v2.addon_credits', 'quota' => 'purchase_v2.quota'] as $key => $label)
                    @if($checkout[$key] !== null)<div><dt>{{ __($label) }}</dt><dd dir="ltr">{{ number_format((int) $checkout[$key]) }} {{ $key === 'quota' ? 'MB' : '' }}</dd></div>@endif
                @endforeach
            </dl>
        </section>
        @if($checkout['state'] === 'awaiting')
            <section class="v2-account-panel v2-payment-action {{ $checkout['links'] ? 'has-app-links' : '' }}" data-v2-payment-controls>
                <h2>{{ __('payment_v2.pay_fib') }}</h2>
                @if($checkout['qr'])
                    <div class="v2-payment-qr-primary">
                        <p>{{ __('payment_v2.scan_app') }}</p>
                        <img class="v2-payment-qr" src="{{ $checkout['qr'] }}" alt="{{ __('payment_v2.scan') }}">
                    </div>
                @endif
                @if($checkout['links'])
                    <div class="v2-payment-app-links">
                        <p>{{ __('payment_v2.continue_app') }}</p>
                        <div class="v2-account-actions">
                            @foreach($checkout['links'] as $name => $url)
                                <a class="btn btn-info v2-payment-app-link" href="{{ $url }}" target="_blank" rel="noopener noreferrer">
                                    {{ __('payment_v2.pay_app') }}@if($name !== 'app') · {{ __('payment_v2.open_'.$name) }}@endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if($checkout['code'] !== '')
                    <div class="v2-payment-code-box">
                        <h3>{{ __('payment_v2.code') }}</h3>
                        <code class="v2-payment-code" dir="ltr" data-v2-payment-code>{{ $checkout['code'] }}</code>
                        <button type="button" class="btn btn-outline-info" data-v2-copy-code
                            data-copy-label="{{ __('payment_v2.copy_code') }}" data-copied-label="{{ __('payment_v2.copied') }}"
                            data-copy-failed="{{ __('payment_v2.copy_failed') }}">{{ __('payment_v2.copy_code') }}</button>
                        <span class="v2-payment-copy-feedback" role="status" data-v2-copy-feedback></span>
                    </div>
                @endif
                @if($checkout['qr'] && $checkout['links'])
                    <details class="v2-payment-mobile-qr">
                        <summary>{{ __('payment_v2.show_qr') }}</summary>
                        <p>{{ __('payment_v2.scan_app') }}</p>
                        <img class="v2-payment-qr" src="{{ $checkout['qr'] }}" alt="{{ __('payment_v2.scan') }}">
                    </details>
                @endif
                @if(!$checkout['qr'] && !$checkout['links'] && $checkout['code'] === '')
                    <p role="status">{{ __('payment_v2.no_link') }}</p>
                @endif
                @if($checkout['expiresAt'])
                    <p class="v2-payment-countdown" data-v2-countdown data-expires="{{ $checkout['expiresAt'] }}" data-server-now="{{ $checkout['serverNow'] }}"
                        data-checking="{{ __('payment_v2.checking_expiry') }}">
                        {{ __('payment_v2.expires_in') }} <bdi dir="ltr" data-v2-time>{{ sprintf('%02d:%02d', intdiv(max(0, $checkout['expiresAt'] - $checkout['serverNow']), 60), max(0, $checkout['expiresAt'] - $checkout['serverNow']) % 60) }}</bdi>
                    </p>
                @endif
            </section>
        @endif
    </div>
    <section class="v2-account-panel v2-payment-status" @if($checkout['poll']) wire:poll.10s="pollStatus" @endif>
        <span class="v2-account-badge {{ $checkout['state'] === 'completed' ? 'is-good' : '' }}" role="status">{{ __('payment_v2.'.$checkout['stateLabel']) }}</span>
        <p class="v2-payment-explanation">{{ __($checkout['canAbandon'] ? 'payment_v2.attention_help' : ($checkout['operatorReviewOnly'] ? 'payment_v2.operator_review_help' : 'payment_v2.'.$checkout['state'].'_help')) }}</p>
        <ol class="v2-payment-timeline" aria-label="{{ __('payment_v2.progress') }}">
            @foreach(in_array($checkout['state'], ['review','failed','expired','canceled','refunded']) ? ['created','awaiting',$checkout['state']] : ['created','awaiting','confirming','completed'] as $step)
                <li class="{{ $checkout['state'] === $step ? 'is-current' : '' }}" @if($checkout['state'] === $step) aria-current="step" @endif>{{ __('payment_v2.'.($checkout['state'] === $step ? $checkout['stateLabel'] : $step)) }}</li>
            @endforeach
        </ol>
        @if($checkout['canAbandon'])
            <div class="v2-account-actions">
                <button type="button" class="btn btn-outline-warning" wire:click="abandonCheckout" wire:loading.attr="disabled" data-v2-confirm="{{ __('payment_v2.abandon_confirm') }}">{{ __('payment_v2.abandon') }}</button>
                <a wire:navigate class="btn btn-link" href="{{ $checkout['returnUrl'] }}">{{ __('payment_v2.back_options') }}</a>
            </div>
        @elseif(in_array($checkout['state'], ['failed','expired','canceled','refunded']))
            <a wire:navigate href="{{ $checkout['returnUrl'] }}" class="btn btn-info">{{ __('payment_v2.start_new') }}</a>
        @elseif(in_array($checkout['state'], ['awaiting','confirming','review']))
            <button wire:click="refreshStatus" wire:loading.attr="disabled" class="btn btn-outline-info">{{ __($checkout['operatorReviewOnly'] ? 'payment_v2.refresh_review' : 'payment_v2.refresh') }}</button>
            @if(!$checkout['poll'] && $checkout['state'] === 'awaiting')<p>{{ __('payment_v2.paused') }}</p>@endif
        @endif
        <span wire:loading wire:target="pollStatus,refreshStatus,abandonCheckout" role="status">{{ __('account_v2.updating') }}</span>
        @error('status')<p class="v2-account-error" role="alert">{{ $message }}</p>@enderror
    </section>
    <div class="v2-account-actions"><a wire:navigate href="{{ route('app.v2.billing', ['locale' => app()->getLocale()]) }}" class="btn btn-outline-info">{{ __('purchase_v2.history') }}</a><a wire:navigate href="{{ $checkout['returnUrl'] }}" class="btn btn-outline-info">{{ __('payment_v2.purchases') }}</a><a wire:navigate href="{{ route('app.v2.home', ['locale' => app()->getLocale()]) }}" class="btn btn-link">{{ __('account_v2.back') }}</a></div>
</div>
