<nav class="v2-account-nav" aria-label="{{ __('account_v2.account') }}">
    <a wire:navigate href="{{ route('app.v2.home', ['locale' => app()->getLocale()]) }}"><i class="ri-arrow-left-line" aria-hidden="true"></i> {{ __('account_v2.back') }}</a>
    <a wire:navigate href="{{ route('app.v2.profile', ['locale' => app()->getLocale()]) }}" @if($active === 'profile') aria-current="page" @endif>{{ __('Profile') }}</a>
    <a wire:navigate href="{{ route('app.v2.billing', ['locale' => app()->getLocale()]) }}" @if($active === 'billing') aria-current="page" @endif>{{ __('Billing') }}</a>
</nav>
@if(auth('app')->check())
    <p class="v2-account-label" data-account-identity>{{ __('account_v2.customer_id') }} <b dir="ltr">#{{ auth('app')->id() }}</b> · <span dir="auto">{{ '@'.auth('app')->user()->username }}</span></p>
@endif
