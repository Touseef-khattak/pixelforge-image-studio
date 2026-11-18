<?php

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function validPayload(array $overrides = []): array
{
    return array_merge(['prompt' => 'a red fox', 'size' => '1024x1024', 'quality' => 'low'], $overrides);
}

it('stores a queued generation with its estimate and dispatches the submit job', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/generations', validPayload(['reference_images' => ['https://img.test/a.png']]))
        ->assertRedirect();

    $generation = Generation::sole();
    expect($generation)
        ->user_id->toBe($user->id)
        ->status->toBe(Generation::QUEUED)
        ->reference_images->toBe(['https://img.test/a.png'])
        ->estimated_min_nanos->toBeInt()->toBeGreaterThan(0)
        ->and($generation->estimated_max_nanos)->toBeGreaterThan($generation->estimated_min_nanos);
    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
});

it('requires authentication', function () {
    Queue::fake();

    $this->post('/generations', validPayload())->assertRedirect('/login');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects invalid input without creating anything', function (array $overrides, string $field) {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post('/generations', validPayload($overrides))
        ->assertSessionHasErrors($field);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'unknown size' => [['size' => '10x10'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'too many references' => [['reference_images' => array_fill(0, 5, 'https://img.test/a.png')], 'reference_images'],
    'non-http reference' => [['reference_images' => ['ftp://img.test/a.png']], 'reference_images.0'],
]);
