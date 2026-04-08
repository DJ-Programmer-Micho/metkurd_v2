<?php

namespace App\Enums;

enum PaymentPurposeType: string
{
    case SERVICE_PLAN = 'service_plan';
    case STORAGE_PLAN = 'storage_plan';
    case CREDIT_PRODUCT = 'credit_product';
}
