<?php

namespace App\Pixelforge;

/**
 * Verifies the `Pixelforge-Signature` header: `t=<timestamp>,v1=<hex hmac>` where the HMAC is
 * SHA-256 over "<timestamp>.<raw body>" keyed with the endpoint's whsec_ secret.
 */
final class WebhookSignature
{
    public static function isValid(string $rawBody, ?string $header, string $secret, int $toleranceSeconds, ?int $now = null): bool
    {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        [$timestamp, $signatures] = self::parse($header);

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        $signedAt = ctype_digit($timestamp) ? (int) $timestamp : strtotime($timestamp);

        if ($signedAt === false || abs(($now ?? time()) - $signedAt) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: ?string, 1: list<string>} */
    private static function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
