<?php

namespace App\Http\Controllers\App\Services;

class F5ttsSpeakerAssetController extends XttsSpeakerAssetController
{
    protected string $previewFolder = 'ftts';

    protected string $voiceAssetCachePrefix = 'ftts-speaker-asset:';
}
