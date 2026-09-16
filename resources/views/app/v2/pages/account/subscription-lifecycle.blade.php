@php($lifecycle = app(\App\Services\Billing\SubscriptionLifecycleView::class)->customer(auth('app')->user(), $lifecycleKind ?? 'service'))
@if($lifecycle['renewal'] !== 'off' || $lifecycle['changing_to'] || $lifecycle['old_pending'] || $lifecycle['previous_ended'])
<div class="v2-account-notice" aria-live="polite">
    @if(in_array($lifecycle['renewal'], ['pending', 'requested', 'confirmed']))
        <strong>{{ __('subscription_lifecycle.'.$lifecycle['renewal']) }}</strong>
        <p>{{ __('subscription_lifecycle.'.($lifecycle['renewal'] === 'confirmed' ? 'renewal_off' : 'awaiting_provider')) }}</p>
        @if($lifecycle['until'])<p>{{ __('subscription_lifecycle.access_until', ['date' => \Illuminate\Support\Carbon::parse($lifecycle['until'])->translatedFormat('M d, Y H:i')]) }}</p>@endif
    @elseif($lifecycle['renewal'] === 'issue')
        <strong>{{ __('subscription_lifecycle.issue') }}</strong>
        @if($lifecycle['until'])<p>{{ __('subscription_lifecycle.access_until', ['date' => \Illuminate\Support\Carbon::parse($lifecycle['until'])->translatedFormat('M d, Y H:i')]) }}</p>@endif
    @elseif($lifecycle['renewal'] === 'renewing' && $lifecycle['until'])
        <p>{{ __('subscription_lifecycle.renews', ['date' => \Illuminate\Support\Carbon::parse($lifecycle['until'])->translatedFormat('M d, Y H:i')]) }}</p>
    @endif
    @if($lifecycle['changing_to'])<p dir="auto">{{ __('subscription_lifecycle.changing', ['plan' => $lifecycle['changing_to']]) }}</p>@endif
    @if($lifecycle['old_pending'])<p dir="auto">{{ __('subscription_lifecycle.old_pending', ['plan' => $lifecycle['old_pending']]) }}</p>@endif
    @if($lifecycle['previous_ended'])<p dir="auto">{{ __('subscription_lifecycle.ended', ['plan' => $lifecycle['previous_ended']]) }}</p>@endif
</div>
@endif
