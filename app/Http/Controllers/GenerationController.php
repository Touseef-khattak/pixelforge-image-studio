<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Pricing\Estimate;
use App\Pricing\PricingService;
use App\Pricing\Rates;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection(
                $request->user()->generations()->latest()->limit(50)->get()
            )->resolve(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Generations/Create', [
            'sizes' => StoreGenerationRequest::SIZES,
            'qualities' => StoreGenerationRequest::QUALITIES,
            'maxReferences' => 4,
            'markupPercent' => config('pixelforge.markup_percent'),
        ]);
    }

    /** Price range for the form, computed by the same service that prices the stored generation. */
    public function estimate(Request $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', Rule::in(StoreGenerationRequest::SIZES)],
            'quality' => ['required', Rule::in(StoreGenerationRequest::QUALITIES)],
            'reference_count' => ['nullable', 'integer', 'min:0', 'max:4'],
        ]);

        $estimate = $this->estimateFor($pricing, $data['prompt'] ?? '', $data['quality'], $data['size'], (int) ($data['reference_count'] ?? 0));

        return response()->json([
            'min' => Money::format($estimate->chargeMin),
            'max' => Money::format($estimate->chargeMax),
        ]);
    }

    public function store(StoreGenerationRequest $request, PricingService $pricing): RedirectResponse
    {
        $data = $request->validated();
        $references = $data['reference_images'] ?? [];

        $estimate = $this->estimateFor($pricing, $data['prompt'], $data['quality'], $data['size'], count($references));

        $generation = $request->user()->generations()->create([
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $references,
            'estimated_min_nanos' => $estimate->chargeMin,
            'estimated_max_nanos' => $estimate->chargeMax,
        ]);

        SubmitGeneration::dispatch($generation->id);

        return to_route('generations.index')->with('status', 'Generation queued.');
    }

    private function estimateFor(PricingService $pricing, string $prompt, string $quality, string $size, int $references): Estimate
    {
        return $pricing->estimate(mb_strlen($prompt), $quality, $size, $references, Rates::published());
    }
}
