<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Resources\Mobile\MobileAccountUsageResource;
use App\Services\Mobile\MobileAccountUsageSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileAccountUsageController extends MobileApiController
{
    public function __construct(
        protected MobileAccountUsageSummaryService $summary,
    ) {
    }

    public function show(Request $request): MobileAccountUsageResource|JsonResponse
    {
        $customer = $this->customer($request);

        if ((int) ($customer->status ?? 0) !== 1) {
            return response()->json([
                'message' => __('This account is inactive. Please use the website for support.'),
            ], 423);
        }

        if (! $customer->hasCompletedVerification()) {
            return response()->json([
                'message' => __('Complete your account verification on the website before using the mobile apps.'),
            ], 403);
        }

        return new MobileAccountUsageResource($this->summary->forCustomer($customer));
    }
}
