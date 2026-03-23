<?php

namespace App\Http\Controllers;

use App\Models\MlJob;

abstract class Controller
{
    protected function abortUnlessCustomerCanAccessJob(MlJob $job): void
    {
        $customer = auth('app')->user();

        abort_unless(
            $customer && $customer->canAccessMlJob($job),
            403,
            'You do not have access to this service.'
        );
    }
}
