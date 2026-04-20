<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Resources\Mobile\MobileTtsVoiceResource;
use App\Services\Mobile\MobileTtsVoiceAssetService;
use App\Services\Mobile\MobileTtsVoiceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileTtsVoicesController extends MobileApiController
{
    public function __construct(
        protected MobileTtsVoiceCatalog $voices,
        protected MobileTtsVoiceAssetService $assets,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->appContext($request, 'tts');
        $customer = $this->customer($request);
        $validated = $request->validate([
            'tool_code' => ['nullable', 'string', 'in:tts,ftts'],
        ]);

        $voices = $this->voices->voicesForCustomer($customer, $validated['tool_code'] ?? null);

        return response()->json([
            'data' => [
                'voices' => MobileTtsVoiceResource::collection($voices)->resolve($request),
            ],
        ]);
    }

    public function avatar(Request $request, string $speakerId)
    {
        $this->appContext($request, 'tts');
        $customer = $this->customer($request);
        $voice = $this->voices->findVoiceForCustomer($customer, $speakerId);

        abort_unless($voice, 404);

        return $this->assets->avatarResponse($voice);
    }
}
