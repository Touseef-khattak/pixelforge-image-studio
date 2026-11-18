<?php

namespace App\Pixelforge;

use RuntimeException;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Worth trying again later: rate and concurrency limits, overload, and server errors.
     * A daily quota will not clear within a retry window, so it is permanent for this attempt.
     */
    public function isRetryable(): bool
    {
        if ($this->errorCode === 'daily_limit_exceeded') {
            return false;
        }

        return $this->status === 429 || $this->status >= 500;
    }
}
