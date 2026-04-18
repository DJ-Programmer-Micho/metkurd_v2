<?php

use App\Services\Payments\PaymentFeeCalculator;

it('calculates FIB and Areeba fees in IQD correctly', function () {
    $calculator = app(PaymentFeeCalculator::class);

    $fib = $calculator->quote('fib', 100000);
    $areebaLocal = $calculator->quote('areeba', 100000, 'local');
    $areebaInternational = $calculator->quote('areeba', 100000, 'international');

    expect($fib['provider_fee_amount_iqd'])->toBe(1011)
        ->and($fib['gross_amount_iqd'])->toBe(101011)
        ->and($fib['surcharge_amount_iqd'])->toBe(1011)
        ->and($fib['net_amount_iqd'])->toBe(100000)
        ->and(data_get($fib, 'fee_breakdown.pass_to_customer'))->toBeTrue()
        ->and($areebaLocal['provider_fee_amount_iqd'])->toBe(1264)
        ->and($areebaLocal['gross_amount_iqd'])->toBe(100000)
        ->and($areebaLocal['net_amount_iqd'])->toBe(98736)
        ->and($areebaInternational['provider_fee_amount_iqd'])->toBe(3646)
        ->and($areebaInternational['gross_amount_iqd'])->toBe(100000)
        ->and($areebaInternational['net_amount_iqd'])->toBe(96354);
});

it('rounds fib pass-through pricing up to the next whole IQD when needed', function () {
    $quote = app(PaymentFeeCalculator::class)->quote('fib', 30000);

    expect($quote['gross_amount_iqd'])->toBe(30304)
        ->and($quote['provider_fee_amount_iqd'])->toBe(304)
        ->and($quote['net_amount_iqd'])->toBe(30000);
});
