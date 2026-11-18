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

    /**
     * Build from a generation's `rates` object: decimal strings in USD per 1M tokens, e.g. "12.50".
     *
     * @param  array<string, string>  $rates
     */
    public static function fromApi(array $rates): self
    {
        return new self(
            inputText: self::perTokenNanos($rates['input_text_tokens']),
            inputImage: self::perTokenNanos($rates['input_image_tokens']),
            output: self::perTokenNanos($rates['output_tokens']),
            reasoning: self::perTokenNanos($rates['reasoning_tokens']),
        );
    }

    /** "12.50" USD per 1M tokens -> 12_500 nano-dollars per token, parsed as a string so no float is involved. */
    private static function perTokenNanos(string $usdPerMillion): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $usdPerMillion, $m)) {
            throw new \InvalidArgumentException("Unsupported rate format: {$usdPerMillion}");
        }

        return ((int) $m[1]) * 1_000 + (int) str_pad($m[2] ?? '', 3, '0');
    }

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
