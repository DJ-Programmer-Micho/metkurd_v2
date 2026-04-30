<?php

namespace App\Listeners\Payments;

use App\Domain\Payments\Actions\FulfillAddonCredits;
use App\Domain\Payments\Actions\FulfillPlanSubscription;
use App\Domain\Payments\Actions\FulfillStorageSubscription;
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

        // Checkout mode affects provider flow only; fulfillment remains purchase-type based.
        foreach ([$this->plans, $this->storage, $this->addons] as $handler) {
            if ($handler->supports($payment->purchase_type)) {
                $handler->handle($payment);

                return;
            }
        }
    }
}
