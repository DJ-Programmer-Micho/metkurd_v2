<?php

namespace App\Contracts\Payments;

use App\Enums\PaymentPurposeType;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;

interface PaymentProviderInterface
{
    public function driver(): string;

    public function hasValidConfiguration(?PaymentMethod $method = null): bool;

    /**
     * @return array<int, string>
     */
    public function configurationIssues(?PaymentMethod $method = null): array;

    public function isCheckoutReady(?PaymentMethod $method = null): bool;

    public function recurringStrategy(PaymentMethod $method, PaymentPurposeType $purposeType): string;

    /**
     * @param  array<string, mixed>  $purpose
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function initializeCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        Customer $customer,
        array $purpose,
        array $options = [],
    ): array;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function synchronizeCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function cancelCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function refundCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeWebhookPayload(array $payload): array;

    public function validateWebhookSignature(Request $request, ?PaymentMethod $method = null): ?bool;
}
