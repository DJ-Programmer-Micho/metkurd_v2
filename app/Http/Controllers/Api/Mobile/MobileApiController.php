<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Mobile\MobileAppCatalog;
use Illuminate\Http\Request;

abstract class MobileApiController extends Controller
{
    protected function customer(Request $request): Customer
    {
        $customer = $request->user();

        abort_unless($customer instanceof Customer, 401, 'Unauthenticated.');

        return $customer;
    }

    /**
     * @return array<string, mixed>
     */
    protected function appContext(Request $request, string $app): array
    {
        $customer = $this->customer($request);

        abort_if((int) ($customer->status ?? 0) !== 1, 423, 'This account is inactive. Please use the website for support.');
        abort_unless($customer->hasCompletedVerification(), 403, 'Complete your account verification before using this mobile API.');

        $catalog = app(MobileAppCatalog::class);
        $context = $catalog->for($app);

        abort_if(! $context, 404, 'Mobile app scope not found.');
        abort_unless($catalog->tokenCanAccessApp($request, $app), 403, 'Token is not authorized for this mobile app.');
        abort_unless($catalog->customerCanAccess($customer, $app), 403, 'Your current subscription does not allow this mobile app.');

        return $context;
    }
}
