<?php

namespace App\Pricing;

/** Up-front price range, in nano-dollars. Reasoning tokens are only known after completion. */
final readonly class Estimate
{
    public function __construct(
        public int $providerMin,
        public int $providerMax,
        public int $chargeMin,
        public int $chargeMax,
    ) {}
}
