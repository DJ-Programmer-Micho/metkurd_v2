<?php

namespace App\Http\Controllers\App\Services;

/** Serves only canonical Vector 2.0 jobs; legacy Vector stays on clone_xomni. */
class VectorV2RenderController extends CloneXttsRenderController
{
    protected string $toolCode = 'vector-v2';

    protected string $streamFailureLog = 'VECTOR_V2_STREAM_FAIL';
}
