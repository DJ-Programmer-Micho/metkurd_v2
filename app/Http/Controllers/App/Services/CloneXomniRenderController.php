<?php

namespace App\Http\Controllers\App\Services;

class CloneXomniRenderController extends CloneXttsRenderController
{
    protected string $toolCode = 'clone_xomni';

    protected string $streamFailureLog = 'CLONE_XOMNI_STREAM_FAIL';
}
