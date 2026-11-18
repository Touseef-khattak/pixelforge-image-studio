<?php

namespace App\Pricing;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /** @param  array<string, int>  $usage  a generation's `usage` object */
    public static function fromApi(array $usage): self
    {
        return new self(
            inputText: $usage['input_text_tokens'],
            inputImage: $usage['input_image_tokens'],
            output: $usage['output_tokens'],
            reasoning: $usage['reasoning_tokens'],
        );
    }
}
