<?php

namespace App\Http\Controllers\App\Services;

class F5ttsRenderController extends XttsRenderController
{
    protected string $toolCode = 'ftts';

    protected string $streamFailureLog = 'F5TTS_STREAM_FAIL';
}
