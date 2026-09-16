<?php
use Livewire\Attributes\Layout;
new #[Layout('app::v2.layouts.app')] class extends \App\Livewire\Account\V2BillingPage {};
?>
<div class="v2-account">
    <x-slot:title>{{ __('account_v2.billing_title') }} | MetKurd</x-slot:title>
    @include('app.v2.pages.account.navigation', ['active' => 'billing'])
    <header class="v2-account-heading"><span class="v2-account-eyebrow">{{ __('account_v2.account') }}</span><h1>{{ __('account_v2.billing_title') }}</h1><p>{{ __('account_v2.billing_intro') }}</p></header>
    @php
        $data = $this->dashboard;
    @endphp
    @if(!$data['available'])
        <section class="v2-account-panel" role="alert"><h2>{{ __('account_v2.unavailable') }}</h2><p>{{ __('account_v2.retry_help') }}</p><button class="btn btn-info" wire:click="$refresh" wire:loading.attr="disabled">{{ __('account_v2.retry') }}</button></section>
    @else
        @php
            $state = $data['state']; $plan = $state['current_plan']; $sub = $state['subscription']; $end = $state['period_ends_at']; $termEnded = $end && $end->isPast();
            $timeline = $data['timeline']; $metric = $chartMetric === 'jobs' ? 'jobs' : 'credits';
            $peak = max(1, (int) $timeline->max($metric)); $toolPeak = max(1, (int) $data['tools']->max('credits'));
        @endphp
        @include('app.v2.pages.account.agreement-status', ['state' => $state])
        @include('app.v2.pages.account.subscription-lifecycle')
        <div class="v2-account-overview">
            <section class="v2-account-panel v2-account-plan">
                <span class="v2-account-label">{{ __('Current Plan') }}</span><h2 dir="auto">{{ $plan->name }}</h2>
                <span class="v2-account-badge {{ $state['cancellation_scheduled'] ? 'is-pending' : 'is-good' }}">{{ $data['complimentary'] ? __('account_v2.complimentary') : ($plan->is_free ? __('Free') : ($state['cancellation_scheduled'] ? __('account_v2.cancel_pending') : __('account_v2.active'))) }}</span>
                @if(!$plan->is_free)
                    @if($state['externally_managed'] ?? false)<p class="v2-account-notice">{{ __('agreement.customer_help') }}</p>@endif
                    <p>{{ $this->billingCycleLabel(data_get($sub?->meta, 'billing_cycle')) ?? __('account_v2.term') }}</p>
                    <p>{{ __($termEnded ? 'account_v2.recorded_end' : 'account_v2.access_until') }} <strong dir="ltr">{{ $end ? $this->formatTimestamp(($state['externally_managed'] ?? false) ? $end->copy()->subSecond() : $end) : __('account_v2.unknown') }}</strong></p>
                    @if($termEnded)<p class="v2-account-notice">{{ __('account_v2.term_ended') }}</p>@endif
                    <small>{{ ($state['externally_managed'] ?? false) ? __('agreement.external') : ($data['complimentary'] ? __('account_v2.internal_access') : ($state['cancellation_scheduled'] ? __('account_v2.cancel_pending') : ($sub?->auto_renew ? __('account_v2.auto_renew_on') : __('account_v2.manual_renewal')))) }}</small>
                @endif
                <div class="v2-account-actions"><a href="{{ route('app.v2.subscription-plans', ['locale' => app()->getLocale()]) }}" class="btn btn-outline-info btn-sm">{{ __('account_v2.manage_plan') }}</a>
                @if($state['cancelable'] && !$data['complimentary'])<button class="btn btn-link btn-sm" wire:click="$set('confirmCancellation', true)">{{ __('account_v2.cancel_renewal') }}</button>@endif</div>
                @if($confirmCancellation)
                    <div class="v2-account-notice" role="group" aria-label="{{ __('account_v2.cancel_renewal') }}"><p>{{ __('account_v2.cancel_help') }}</p><button class="btn btn-outline-warning btn-sm" wire:click="cancelSubscription" wire:loading.attr="disabled" wire:target="cancelSubscription">{{ __('account_v2.confirm_cancel') }}</button> <button class="btn btn-link btn-sm" wire:click="$set('confirmCancellation', false)">{{ __('account_v2.keep_plan') }}</button></div>
                @endif
                @error('cancellation')<p class="v2-account-error" role="alert">{{ $message }}</p>@enderror
            </section>
            @foreach(['app' => 'app_credits', 'api' => 'api_credits'] as $channel => $label)
                <section class="v2-account-panel"><span class="v2-account-label">{{ __('account_v2.'.$label) }}</span><div class="v2-account-number" data-wallet="{{ $channel }}" dir="ltr">{{ number_format($data['wallets'][$channel]['balance']) }}</div><small>{{ __('account_v2.available_credits') }}</small><div class="v2-account-divider"></div>
                    <dl class="v2-account-breakdown"><div><dt>{{ __('account_v2.subscription_credits') }}</dt><dd dir="ltr">{{ number_format($data['wallets'][$channel]['subscription']) }}</dd></div><div><dt>{{ __('account_v2.addon_credits') }}</dt><dd dir="ltr">{{ number_format($data['wallets'][$channel]['addon']) }}</dd></div></dl>
                    @if($channel === 'api')<a wire:navigate href="{{ route('app.v2.api', ['locale' => app()->getLocale()]) }}">{{ __('account_v2.api_access') }}</a>@endif
                </section>
            @endforeach
        </div>
        <section class="v2-account-panel v2-account-filters">
            <div class="v2-account-section-heading"><div><h2>{{ __('account_v2.usage') }}</h2><p>{{ __('account_v2.filter_help') }}</p></div><button class="btn btn-outline-info btn-sm" wire:click="resetFilters">{{ __('Reset') }}</button></div>
            <div class="v2-account-filter-grid">
                <div><label for="v2-period" class="v2-account-label">{{ __('Period') }}</label><select id="v2-period" class="form-select" wire:model.live="periodPreset">@foreach(['daily', 'weekly', 'monthly', 'custom'] as $value)<option value="{{ $value }}">{{ __('account_v2.'.$value) }}</option>@endforeach</select></div>
                <div><label for="v2-group" class="v2-account-label">{{ __('account_v2.group') }}</label><select id="v2-group" class="form-select" wire:model.live="groupBy">@foreach(['day', 'week', 'month'] as $value)<option value="{{ $value }}">{{ __('account_v2.'.$value) }}</option>@endforeach</select></div>
                <div><label for="v2-tool" class="v2-account-label">{{ __('Tool') }}</label><select id="v2-tool" class="form-select" wire:model.live="toolFilter">@foreach($toolOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                <div><label for="v2-status" class="v2-account-label">{{ __('Status') }}</label><select id="v2-status" class="form-select" wire:model.live="statusFilter"><option value="all">{{ __('account_v2.all_statuses') }}</option>@foreach(['done', 'failed', 'queued', 'running', 'saving', 'canceled'] as $value)<option value="{{ $value }}">{{ $this->customerStatus($value) }}</option>@endforeach</select></div>
                <div><label for="v2-from" class="v2-account-label">{{ __('account_v2.from') }}</label><input id="v2-from" type="date" class="form-control" wire:model.live="dateFrom" dir="ltr"></div>
                <div><label for="v2-to" class="v2-account-label">{{ __('account_v2.to') }}</label><input id="v2-to" type="date" class="form-control" wire:model.live="dateTo" dir="ltr"></div>
                <div class="v2-account-search"><label for="v2-search" class="v2-account-label">{{ __('Search') }}</label><input id="v2-search" class="form-control" wire:model.live.debounce.400ms="search" placeholder="{{ __('account_v2.search_help') }}" dir="auto"></div>
            </div><div wire:loading role="status">{{ __('account_v2.updating') }}</div>
        </section>
        <div class="v2-account-period-summary"><span><strong>{{ number_format($data['stats']['period_credits_spent']) }}</strong> {{ __('account_v2.credits_used') }}</span><span><strong>{{ number_format($data['stats']['jobs_count']) }}</strong> {{ __('account_v2.jobs') }}</span><span><strong>{{ number_format($data['stats']['jobs_success']) }}</strong> {{ __('account_v2.completed') }}</span></div>
        <div class="v2-account-grid v2-account-charts">
            <section class="v2-account-panel"><div class="v2-account-section-heading"><h2>{{ __('account_v2.over_time') }}</h2><select class="form-select v2-account-metric" wire:model.live="chartMetric" aria-label="{{ __('account_v2.chart_metric') }}"><option value="credits">{{ __('account_v2.credits_used') }}</option><option value="jobs">{{ __('account_v2.jobs') }}</option></select></div>
                @if($timeline->isEmpty())<p class="v2-account-empty">{{ __('account_v2.no_usage') }}</p>@else
                    <div class="v2-account-timeline" role="list" aria-label="{{ __('account_v2.over_time') }}">
                    @foreach($timeline as $point)<div class="v2-account-chart-column" role="listitem"><span class="v2-account-chart-value" dir="ltr">{{ number_format($point[$metric]) }}</span><div class="v2-account-chart-track"><div class="v2-account-chart-bar" style="height:{{ max(1, round($point[$metric] / $peak * 100)) }}%" title="{{ $point['period'] }} · {{ number_format($point[$metric]) }} {{ __('account_v2.'.($metric === 'jobs' ? 'jobs' : 'credits_used')) }}"></div></div><time dir="ltr">{{ $point['period'] }}</time></div>@endforeach
                    </div>
                @endif
            </section>
            <section class="v2-account-panel"><h2>{{ __('account_v2.by_service') }}</h2><p>{{ __('account_v2.credits_used') }}</p>
                <div class="v2-account-tools">@forelse($data['tools'] as $tool)<div><div class="v2-account-section-heading"><span dir="auto">{{ $tool['label'] }}</span><strong dir="ltr">{{ number_format($tool['credits']) }}</strong></div><div class="v2-account-tool-track"><div style="width:{{ round($tool['credits'] / $toolPeak * 100) }}%" title="{{ $tool['label'] }} · {{ number_format($tool['credits']) }}"></div></div><small>{{ number_format($tool['jobs']) }} {{ __('account_v2.jobs') }}</small></div>@empty<p class="v2-account-empty">{{ __('account_v2.no_usage') }}</p>@endforelse</div>
            </section>
        </div>
        <section class="v2-account-panel"><h2>{{ __('account_v2.usage_history') }}</h2><div class="v2-account-table-wrap"><table class="v2-account-table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Tool') }}</th><th>{{ __('account_v2.reference') }}</th><th>{{ __('Status') }}</th><th>{{ __('Credits') }}</th><th>{{ __('account_v2.channel') }}</th></tr></thead><tbody>
            @forelse($data['jobs'] as $job)<tr wire:key="v2-billing-job-{{ $job->id }}"><td dir="ltr">{{ $this->formatTimestamp($job->created_at) }}</td><td dir="auto">{{ $this->toolLabel($job->job_kind) }}</td><td><span class="v2-account-reference" dir="ltr" title="{{ $job->id }}">{{ $job->id }}</span></td><td>{{ $this->customerStatus($job->status) }}</td><td dir="ltr">{{ number_format($job->credits_charged) }}</td><td>{{ in_array($job->id, $data['apiJobs'], true) ? __('account_v2.api') : __('account_v2.app') }}</td></tr>@empty<tr><td colspan="6" class="v2-account-empty">{{ __('account_v2.no_usage') }}</td></tr>@endforelse
        </tbody></table></div>{{ $data['jobs']->links('app.v2.pages.account.pagination') }}</section>
        <section class="v2-account-panel"><h2>{{ __('account_v2.payment_history') }}</h2><p>{{ __('account_v2.payment_help') }}</p><div class="v2-account-table-wrap"><table class="v2-account-table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('account_v2.product') }}</th><th>{{ __('Amount') }}</th><th>{{ __('account_v2.payment_method') }}</th><th>{{ __('account_v2.payment_type') }}</th><th>{{ __('Status') }}</th><th>{{ __('account_v2.reference') }}</th></tr></thead><tbody>
            @forelse($data['payments'] as $payment)<tr wire:key="v2-billing-payment-{{ $payment['key'] }}"><td dir="ltr">{{ $this->formatTimestamp($payment['date']) }}</td><td dir="auto">{{ $payment['product'] }}</td><td dir="auto">{{ $payment['amount'] }}</td><td>{{ $payment['method'] }}</td><td>{{ $payment['flow'] }}</td><td>{{ $this->customerStatus($payment['status']) }}</td><td>@if($payment['url'])<a href="{{ $payment['url'] }}">{{ __('account_v2.view_payment') }}</a>@else<span dir="ltr">{{ $payment['key'] }}</span>@endif</td></tr>@empty<tr><td colspan="7" class="v2-account-empty">{{ __('account_v2.no_payments') }}</td></tr>@endforelse
        </tbody></table></div>{{ $data['payments']->links('app.v2.pages.account.pagination') }}</section>
        <section class="v2-account-panel"><h2>{{ __('account_v2.credit_activity') }}</h2><p>{{ __('account_v2.ledger_help') }}</p><div class="v2-account-table-wrap"><table class="v2-account-table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('account_v2.channel') }}</th><th>{{ __('account_v2.bucket') }}</th><th>{{ __('account_v2.activity') }}</th><th>{{ __('Credits') }}</th></tr></thead><tbody>
            @forelse($data['ledger'] as $entry)<tr wire:key="v2-ledger-{{ $entry->id }}"><td dir="ltr">{{ $this->formatTimestamp($entry->created_at) }}</td><td>{{ $entry->wallet_type === 'api' ? __('account_v2.api') : __('account_v2.app') }}</td><td>{{ $entry->bucket === 'addon' ? __('account_v2.addon_credits') : __('account_v2.subscription_credits') }}</td><td>{{ $entry->credits_delta >= 0 ? __('account_v2.credits_added') : __('account_v2.credits_used') }}</td><td dir="ltr">{{ $entry->credits_delta > 0 ? '+' : '' }}{{ number_format($entry->credits_delta) }}</td></tr>@empty<tr><td colspan="5" class="v2-account-empty">{{ __('account_v2.no_credits') }}</td></tr>@endforelse
        </tbody></table></div>{{ $data['ledger']->links('app.v2.pages.account.pagination') }}</section>
    @endif
</div>
