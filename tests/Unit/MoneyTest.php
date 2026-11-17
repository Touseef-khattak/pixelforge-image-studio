<?php

use App\Support\Money;

it('formats nano-dollars with at least two decimals', function (int $nanos, string $expected) {
    expect(Money::format($nanos))->toBe($expected);
})->with([
    'zero' => [0, '$0.00'],
    'whole dollars' => [2_000_000_000, '$2.00'],
    'cents' => [1_500_000_000, '$1.50'],
    'sub-cent keeps precision' => [373_875_000, '$0.373875'],
    'smallest unit' => [1, '$0.000000001'],
    'negative' => [-250_000_000, '-$0.25'],
]);
