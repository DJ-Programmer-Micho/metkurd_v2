<?php

namespace App\Http\Controllers\App\Services;

class XomniRenderController extends XttsRenderController
{
    protected string $toolCode = 'xomni';

    protected string $streamFailureLog = 'XOMNI_STREAM_FAIL';
}
