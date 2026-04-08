<?php

namespace App\Enums;

enum PaymentWebhookProcessingStatus: string
{
    case RECEIVED = 'received';
    case PROCESSED = 'processed';
    case IGNORED = 'ignored';
    case DUPLICATE = 'duplicate';
    case FAILED = 'failed';
}
