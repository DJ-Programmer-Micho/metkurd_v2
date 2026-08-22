<?php

namespace App\Http\Controllers\App\Services;

class LeoRenderController extends QasrRenderController
{
    /** @return array<int,string> */
    protected function toolCodes(): array
    {
        return ['leo'];
    }
}
