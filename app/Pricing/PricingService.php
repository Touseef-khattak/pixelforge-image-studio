<?php

namespace App\Pricing;

/**
 * The only place that does cost arithmetic. All values are integer nano-dollars.
 */
final class PricingService
{
    public function __construct(private readonly int $markupPercent = 25) {}

    /** What Pixelforge charges us for the given usage. */
    public function providerCost(TokenUsage $usage, Rates $rates): int
    {
        return $usage->inputText * $rates->inputText
            + $usage->inputImage * $rates->inputImage
            + $usage->output * $rates->output
            + $usage->reasoning * $rates->reasoning;
    }

    /** What the user pays: provider cost plus markup, rounded up so we never undercharge. */
    public function charge(int $providerCostNanos): int
    {
        $multiplier = 100 + $this->markupPercent;

        return intdiv($providerCostNanos * $multiplier + 99, 100);
    }
}
