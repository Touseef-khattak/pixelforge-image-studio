<?php

use App\Support\Money;

it('parses Pixelforge usd strings into exact nano-dollars', function (string $usd, int $expected) {
    expect(Money::parseUsd($usd))->toBe($expected);
})->with([
    'nine decimals' => ['0.038325000', 38_325_000],
    'zero' => ['0.000000000', 0],
    'no decimals' => ['2', 2_000_000_000],
    'short fraction' => ['1.5', 1_500_000_000],
    'smallest unit' => ['0.000000001', 1],
]);

it('rejects usd strings it cannot represent exactly', function (string $usd) {
    Money::parseUsd($usd);
})->with(['too precise' => ['0.0000000001'], 'negative' => ['-1.00'], 'text' => ['abc']])
    ->throws(InvalidArgumentException::class);

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
