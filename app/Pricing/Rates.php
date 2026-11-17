<?php

namespace App\Pricing;

/**
 * Price per single token, in nano-dollars.
 * A published rate of $X per 1M tokens equals X * 1000 nano-dollars per token.
 */
final readonly class Rates
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /** Pixelforge's published rates: $5, $10, $40 and $12.50 per 1M tokens. */
    public static function published(): self
    {
        return new self(
            inputText: 5_000,
            inputImage: 10_000,
            output: 40_000,
            reasoning: 12_500,
        );
    }
}
