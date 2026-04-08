<?php

namespace App\Http\Controllers\Payments;

use App\Services\Payments\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AreebaWebhookController
{
    public function __invoke(Request $request, PaymentWebhookService $webhooks): JsonResponse
    {
        $event = $webhooks->handle('areeba', $request);

        return response()->json([
            'ok' => true,
            'event_id' => $event->id,
            'status' => $event->processing_status,
        ], (int) ($event->response_code ?? 200));
    }
}
