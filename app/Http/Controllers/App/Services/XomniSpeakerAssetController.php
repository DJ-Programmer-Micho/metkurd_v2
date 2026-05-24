<?php

namespace App\Http\Controllers\App\Services;

class XomniSpeakerAssetController extends XttsSpeakerAssetController
{
    protected string $previewFolder = 'omni';

    protected string $voiceAssetCachePrefix = 'xomni-speaker-asset:';
}
