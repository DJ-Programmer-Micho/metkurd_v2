<?php

use App\Services\Payments\PaymentFeeCalculator;

it('calculates FIB and Areeba fees in IQD correctly', function () {
    $calculator = app(PaymentFeeCalculator::class);

    $fib = $calculator->quote('fib', 100000);
    $areebaLocal = $calculator->quote('areeba', 100000, 'local');
    $areebaInternational = $calculator->quote('areeba', 100000, 'international');

    expect($fib['provider_fee_amount_iqd'])->toBe(1000)
        ->and($fib['gross_amount_iqd'])->toBe(100000)
        ->and($fib['net_amount_iqd'])->toBe(99000)
        ->and($areebaLocal['provider_fee_amount_iqd'])->toBe(1264)
        ->and($areebaLocal['gross_amount_iqd'])->toBe(100000)
        ->and($areebaLocal['net_amount_iqd'])->toBe(98736)
        ->and($areebaInternational['provider_fee_amount_iqd'])->toBe(3646)
        ->and($areebaInternational['gross_amount_iqd'])->toBe(100000)
        ->and($areebaInternational['net_amount_iqd'])->toBe(96354);
});
