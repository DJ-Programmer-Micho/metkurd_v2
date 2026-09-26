<?php

namespace App\Http\Controllers\App\Services;

use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;

class XomniSpeakerAssetController extends XttsSpeakerAssetController
{
    protected string $previewFolder = 'omni';

    protected string $voiceAssetCachePrefix = 'xomni-speaker-asset:';

    protected function voiceAssetData(string $voiceCode): array
    {
        $customer = auth('app')->user();
        abort_unless($customer, 404);

        // The same preview serves App and API customers; neither channel grants the other.
        $appAccess = $customer->canAccessTool('xomni') || $customer->canAccessTool('zeta');
        $apiAccess = in_array('v2:speech', app(ApiCatalog::class)->scopes($customer), true)
            && ($customer->isAllowed('xomni.generate', 'api') || $customer->isAllowed('xomni-v2.generate', 'api') || $customer->isAllowed('zeta.generate', 'api'));
        abort_unless($appAccess || $apiAccess, 403);
        abort_unless(collect(app(OmniSpeakerCatalog::class)->forCustomer($customer, app()->getLocale()))
            ->pluck('speakers')->flatten(1)->contains('code', $voiceCode), 404);

        return parent::voiceAssetData($voiceCode);
    }
}
