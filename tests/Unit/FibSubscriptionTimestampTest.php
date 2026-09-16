<?php

use App\Domain\Payments\Support\FibSubscriptionTimestamp;

it('normalizes explicitly supported subscription dates to UTC', function ($input, $expected) {
    expect(FibSubscriptionTimestamp::parse($input)?->format('Y-m-d H:i:s.vP'))->toBe($expected);
})->with([
    [1755588900190, '2025-08-19 07:35:00.190+00:00'],
    ['1755588900190', '2025-08-19 07:35:00.190+00:00'],
    ['2025-08-19T10:35:00.190+03:00', '2025-08-19 07:35:00.190+00:00'],
    ['2025-08-19T07:35:00Z', '2025-08-19 07:35:00.000+00:00'],
    ['2025-08-19 07:35:00', '2025-08-19 07:35:00.000+00:00'],
    ['2025-08-19', '2025-08-19 00:00:00.000+00:00'],
    [null, null], ['', null], ['tomorrow', null], ['not-a-date', null],
    ['2025-02-30T00:00:00Z', null], ['2025-08-19T25:00:00Z', null],
    [1755588900, null], ['1755588900', null], [999999999999999999, null],
    ['8900-01-01T00:00:00Z', null], ['2038-01-19T03:14:08Z', null],
    [true, null], [[], null], [1755588900190.5, null],
]);

it('keeps production event source literals within the existing schema', function () {
    $root = dirname(__DIR__, 2);
    $count = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        preg_match_all('/(?:[\'"]source[\'"]\s*=>|\$source\s*=|source\s*:)\s*[\'"]([^\'"]+)[\'"]/', $code, $matches);
        preg_match_all('/[\'"](scheduled_[a-z_]+)[\'"]/', $code, $scheduled);
        foreach (array_merge($matches[1], $scheduled[1]) as $source) {
            expect(strlen($source), $file->getFilename().' source '.$source)->toBeLessThanOrEqual(40);
            $count++;
        }
    }
    expect($count)->toBeGreaterThan(50);
    expect(file_get_contents($root.'/database/migrations/2026_04_16_100100_create_payment_events_table.php'))->toContain("string('source', 40)");
});
