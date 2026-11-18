<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory, HasUuids;

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'estimated_min_nanos' => 'integer',
            'estimated_max_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charged_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Fail a generation that never produced an image. It costs nothing, so no ledger entry is
     * written and charged_nanos stays null. A final generation is never changed.
     */
    public function markFailed(string $code, string $message): void
    {
        if ($this->isFinal()) {
            return;
        }

        $this->update([
            'status' => self::FAILED,
            'error_code' => $code,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    /** succeeded and failed are final; a generation never leaves them. */
    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }
}
