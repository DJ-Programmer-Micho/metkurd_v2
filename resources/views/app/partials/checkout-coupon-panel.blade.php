<div class="border rounded-3 p-3 mt-3">
    <div class="fw-semibold mb-2">{{ __('Coupon Code') }}</div>
    <div class="input-group">
        <input type="text"
               class="form-control"
               wire:model.defer="couponCode"
               maxlength="80"
               placeholder="{{ __('Enter coupon code') }}">
        <button type="button"
                class="btn btn-outline-primary"
                wire:click="applyCoupon"
                @disabled($processing)>
            {{ __('Apply') }}
        </button>
        @if($couponCode !== '' || $couponPreview !== [])
            <button type="button"
                    class="btn btn-outline-secondary"
                    wire:click="clearCoupon"
                    @disabled($processing)>
                {{ __('Clear') }}
            </button>
        @endif
    </div>

    @if($couponMessage !== '')
        <div class="alert alert-{{ $couponMessageType }} mt-3 mb-0 py-2">
            {{ $couponMessage }}
        </div>
    @endif

    @if($couponPreview !== [])
        <div class="mt-3 small">
            <div class="d-flex justify-content-between gap-3">
                <span class="text-muted">{{ __('Coupon') }}</span>
                <span class="fw-semibold">{{ $couponPreview['code'] ?? '' }}</span>
            </div>
            <div class="d-flex justify-content-between gap-3 mt-2">
                <span class="text-muted">{{ __('Original Amount') }}</span>
                <span>{{ data_get($couponPreview, 'original_display.iqd_label', data_get($couponPreview, 'original_amount_iqd')) }}</span>
            </div>
            <div class="d-flex justify-content-between gap-3 mt-2">
                <span class="text-muted">{{ __('Discount') }}</span>
                <span class="text-success">-{{ data_get($couponPreview, 'discount_display.iqd_label', data_get($couponPreview, 'discount_amount_iqd')) }}</span>
            </div>
            <div class="d-flex justify-content-between gap-3 mt-2 pt-2 border-top">
                <span class="fw-semibold">{{ __('Final Amount') }}</span>
                <span class="fw-semibold">{{ data_get($couponPreview, 'final_display.iqd_label', data_get($couponPreview, 'final_amount_iqd')) }}</span>
            </div>

            @if((bool) data_get($couponPreview, 'final_display.has_localized_estimate', false))
                <div class="text-muted mt-2">
                    {{ __('Estimated local display: :amount', ['amount' => data_get($couponPreview, 'final_display.display_label')]) }}
                </div>
            @endif
        </div>
    @endif
</div>
