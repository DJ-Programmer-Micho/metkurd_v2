<?php

namespace App\Services\Billing;

/** Only fixed, non-sensitive operator diagnostics may be exposed by this command. */
class PaymentHistoryResetRefused extends \RuntimeException {}
