<?php

namespace App\Http\Controllers\App\Services;

/**
 * Serves Apollo 2.0 renders only. The underlying storage protocol is shared
 * with Apollo 1.5, while the job/tool authorization boundary is distinct.
 */
class XomniV2RenderController extends XttsRenderController
{
    protected string $toolCode = 'xomni-v2';

    protected string $streamFailureLog = 'XOMNI_V2_STREAM_FAIL';
}
