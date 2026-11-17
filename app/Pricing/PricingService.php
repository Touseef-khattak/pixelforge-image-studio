<?php

namespace App\Pricing;

/**
 * The only place that does cost arithmetic. All values are integer nano-dollars.
 */
final class PricingService
{
    public const TOKENS_PER_REFERENCE_IMAGE = 765;

    /** Output tokens are exact and known up front: quality => size => tokens. */
    private const OUTPUT_TOKENS = [
        'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
        'medium' => ['1024x1024' => 1_100, '1536x1024' => 1_650, '1024x1536' => 1_650],
        'high' => ['1024x1024' => 4_300, '1536x1024' => 6_450, '1024x1536' => 6_450],
    ];

    /** Reasoning tokens are only known after completion: quality => [min, max]. */
    private const REASONING_RANGE = [
        'low' => [0, 500],
        'medium' => [200, 2_000],
        'high' => [1_000, 8_000],
    ];

    public function __construct(private readonly int $markupPercent = 25) {}

    /** What Pixelforge charges us for the given usage. */
    public function providerCost(TokenUsage $usage, Rates $rates): int
    {
        return $usage->inputText * $rates->inputText
            + $usage->inputImage * $rates->inputImage
            + $usage->output * $rates->output
            + $usage->reasoning * $rates->reasoning;
    }

    /**
     * Price range before submitting. Text tokens are estimated as ceil(chars / 4), which Pixelforge
     * says is within +-15% of the real count; reasoning tokens fall in a range set by quality.
     */
    public function estimate(int $promptCharacters, string $quality, string $size, int $referenceImages, Rates $rates): Estimate
    {
        $estimatedText = intdiv($promptCharacters + 3, 4);
        $textMin = intdiv($estimatedText * 85, 100);
        $textMax = intdiv($estimatedText * 115 + 99, 100);

        [$reasoningMin, $reasoningMax] = self::REASONING_RANGE[$quality];
        $output = self::OUTPUT_TOKENS[$quality][$size];
        $images = $referenceImages * self::TOKENS_PER_REFERENCE_IMAGE;

        $min = $this->providerCost(new TokenUsage($textMin, $images, $output, $reasoningMin), $rates);
        $max = $this->providerCost(new TokenUsage($textMax, $images, $output, $reasoningMax), $rates);

        return new Estimate($min, $max, $this->charge($min), $this->charge($max));
    }

    /** What the user pays: provider cost plus markup, rounded up so we never undercharge. */
    public function charge(int $providerCostNanos): int
    {
        $multiplier = 100 + $this->markupPercent;

        return intdiv($providerCostNanos * $multiplier + 99, 100);
    }
}
