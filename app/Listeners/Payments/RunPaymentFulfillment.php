<?php

namespace App\Listeners\Payments;

use App\Domain\Payments\Actions\FulfillAddonCredits;
use App\Domain\Payments\Actions\FulfillPlanSubscription;
use App\Domain\Payments\Actions\FulfillStorageSubscription;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Models\Payment;
use App\Events\Payments\PaymentConfirmed;

class RunPaymentFulfillment
{
    public function __construct(
        protected FulfillPlanSubscription $plans,
        protected FulfillStorageSubscription $storage,
        protected FulfillAddonCredits $addons,
    ) {
    }

    public function handle(PaymentConfirmed $event): void
    {
        $payment = Payment::query()->findOrFail($event->paymentId);

        if ($payment->payment_mode === PaymentMode::RECURRING) {
            foreach ([$this->plans, $this->storage] as $handler) {
                if ($handler->supports($payment->purchase_type)) {
                    $handler->handle($payment);

                    return;
                }
            }
        }

        if ($this->addons->supports($payment->purchase_type)) {
            $this->addons->handle($payment);
        }
    }
}
