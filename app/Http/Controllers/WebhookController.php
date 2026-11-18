<?php

namespace App\Http\Controllers;

use App\Actions\ApplyGenerationUpdate;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function __invoke(Request $request, ApplyGenerationUpdate $apply): Response
    {
        // Verify against the raw bytes before trusting or parsing anything.
        $valid = WebhookSignature::isValid(
            $request->getContent(),
            $request->header('Pixelforge-Signature'),
            (string) config('pixelforge.webhook_secret'),
            config('pixelforge.webhook_tolerance'),
        );

        abort_unless($valid, 401, 'Invalid signature.');

        $event = json_decode($request->getContent(), true);

        abort_unless(is_array($event) && isset($event['id'], $event['type']), 400, 'Malformed event.');

        try {
            // Recording the event and applying it commit together. If applying fails the event row
            // rolls back too, we answer 5xx, and Pixelforge's retry gets a clean second attempt.
            DB::transaction(function () use ($event, $apply) {
                $record = WebhookEvent::create([
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'payload' => $event,
                ]);

                if (isset($event['data']['object']) && str_starts_with($event['type'], 'generation.')) {
                    $apply($event['data']['object']);
                }

                $record->update(['processed_at' => now()]);
            });
        } catch (UniqueConstraintViolationException) {
            // Already processed (Pixelforge delivers at least once). Acknowledge so it stops retrying.
        }

        return response()->noContent();
    }
}
