<?php

use App\Pixelforge\WebhookSignature;

const SECRET = 'whsec_test';
const NOW = 1_800_000_000;
const BODY = '{"id":"evt_1","type":"generation.succeeded"}';

function signedHeader(string $body = BODY, int $timestamp = NOW, string $secret = SECRET): string
{
    return "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

it('accepts a correctly signed body', function () {
    expect(WebhookSignature::isValid(BODY, signedHeader(), SECRET, 300, NOW))->toBeTrue();
});

it('rejects a tampered body', function () {
    expect(WebhookSignature::isValid(BODY.' ', signedHeader(), SECRET, 300, NOW))->toBeFalse();
});

it('rejects a signature made with another secret', function () {
    expect(WebhookSignature::isValid(BODY, signedHeader(secret: 'whsec_other'), SECRET, 300, NOW))->toBeFalse();
});

it('rejects a stale or future timestamp', function (int $offset) {
    $header = signedHeader(timestamp: NOW + $offset);

    expect(WebhookSignature::isValid(BODY, $header, SECRET, 300, NOW))->toBeFalse();
})->with([-301, 301, -86_400]);

it('accepts a timestamp within tolerance', function () {
    expect(WebhookSignature::isValid(BODY, signedHeader(timestamp: NOW - 299), SECRET, 300, NOW))->toBeTrue();
});

it('rejects missing or malformed headers and an empty secret', function (?string $header, string $secret) {
    expect(WebhookSignature::isValid(BODY, $header, $secret, 300, NOW))->toBeFalse();
})->with([
    'no header' => [null, SECRET],
    'empty header' => ['', SECRET],
    'garbage' => ['nonsense', SECRET],
    'no signature' => ['t='.NOW, SECRET],
    'no timestamp' => ['v1=abc', SECRET],
    'unset secret' => [null, ''],
]);

it('accepts any of several v1 signatures, to allow secret rotation', function () {
    $header = 't='.NOW.',v1=deadbeef,v1='.hash_hmac('sha256', NOW.'.'.BODY, SECRET);

    expect(WebhookSignature::isValid(BODY, $header, SECRET, 300, NOW))->toBeTrue();
});
