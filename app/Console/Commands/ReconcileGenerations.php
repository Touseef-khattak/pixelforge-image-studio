<?php

namespace App\Console\Commands;

use App\Actions\ApplyGenerationUpdate;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;

class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile {--minutes=2 : Only poll generations with no update for this long}';

    protected $description = 'Poll Pixelforge for generations still unfinished, in case a webhook was lost';

    public function handle(PixelforgeClient $client, ApplyGenerationUpdate $apply): int
    {
        $stale = now()->subMinutes((int) $this->option('minutes'));

        // Rows without a provider id are still being submitted by SubmitGeneration, which has its own retries.
        $pending = Generation::query()
            ->whereNotIn('status', [Generation::SUCCEEDED, Generation::FAILED])
            ->whereNotNull('provider_generation_id')
            ->where('updated_at', '<=', $stale)
            ->get();

        foreach ($pending as $generation) {
            try {
                // Same action the webhook uses, so polling can never charge differently or twice.
                $apply($client->getGeneration($generation->provider_generation_id));
            } catch (PixelforgeException $e) {
                $this->warn("{$generation->id}: {$e->errorCode}");
            }
        }

        $this->info("Checked {$pending->count()} generation(s).");

        return self::SUCCESS;
    }
}
