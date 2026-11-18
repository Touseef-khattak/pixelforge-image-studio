<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGeneration;
use App\Pricing\PricingService;
use App\Pricing\Rates;
use Illuminate\Http\RedirectResponse;

class GenerationController extends Controller
{
    public function store(StoreGenerationRequest $request, PricingService $pricing): RedirectResponse
    {
        $data = $request->validated();
        $references = $data['reference_images'] ?? [];

        $estimate = $pricing->estimate(
            mb_strlen($data['prompt']),
            $data['quality'],
            $data['size'],
            count($references),
            Rates::published(),
        );

        $generation = $request->user()->generations()->create([
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $references,
            'estimated_min_nanos' => $estimate->chargeMin,
            'estimated_max_nanos' => $estimate->chargeMax,
        ]);

        SubmitGeneration::dispatch($generation->id);

        return back()->with('status', 'Generation queued.');
    }
}
