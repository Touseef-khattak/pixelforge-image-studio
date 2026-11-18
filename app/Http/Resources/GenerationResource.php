<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $imageExpired = $this->image_url_expires_at?->isPast() ?? false;

        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'status' => $this->status,
            'is_active' => ! $this->isFinal(),
            'estimate' => [
                'min' => Money::format($this->estimated_min_nanos),
                'max' => Money::format($this->estimated_max_nanos),
            ],
            // Null while still unknown; a failed generation is always free.
            'charged' => match (true) {
                $this->charged_nanos !== null => Money::format($this->charged_nanos),
                $this->status === Generation::FAILED => Money::format(0),
                default => null,
            },
            'image_url' => $this->status === Generation::SUCCEEDED && ! $imageExpired ? $this->image_url : null,
            'image_expired' => $this->status === Generation::SUCCEEDED && $imageExpired,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
