@if($state['agreement'] ?? null)
    <section class="v2-account-panel" aria-label="{{ __('agreement.title') }}">
        <h2>{{ __('agreement.title') }}</h2>
        <p>{{ __('agreement.external') }}</p>
        <p>{{ __('agreement.expiry') }}: <span dir="ltr">{{ $state['agreement']->ends_at->copy()->subDay()->format('Y-m-d') }}</span></p>
    </section>
@elseif($state['pending_agreement'] ?? null)
    @php($scheduledAgreement = $state['pending_agreement'])
    <section class="v2-account-panel" aria-label="{{ __('agreement.title') }}">
        <h2>{{ __('agreement.title') }} — <span dir="auto">{{ $scheduledAgreement->servicePlan?->name }}</span></h2>
        <p>{{ __($scheduledAgreement->status === 'requires_review' ? 'agreement.requires_review' : 'agreement.scheduled') }}</p>
        <p>{{ __('agreement.start') }}: <span dir="ltr">{{ $scheduledAgreement->starts_at->format('Y-m-d') }}</span></p>
        <p>{{ __('agreement.expiry') }}: <span dir="ltr">{{ $scheduledAgreement->ends_at->copy()->subDay()->format('Y-m-d') }}</span></p>
    </section>
@endif
