<?php

namespace App\Policies;

use App\Domain\Payments\Models\Payment;
use App\Models\Customer;

class PaymentPolicy
{
    public function view(Customer $customer, Payment $payment): bool
    {
        return (int) $customer->id === (int) $payment->customer_id;
    }

    public function update(Customer $customer, Payment $payment): bool
    {
        return $this->view($customer, $payment);
    }
}
