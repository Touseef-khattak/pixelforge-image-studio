<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // UUID doubles as the Idempotency-Key we send to Pixelforge.
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Null until Pixelforge accepts the request (webhooks can race the create response).
            $table->string('provider_generation_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images')->nullable();

            // queued, processing, succeeded, failed
            $table->string('status')->default('queued');

            // Money is integer nano-dollars (1 USD = 1e9).
            $table->unsignedBigInteger('estimated_min_nanos');
            $table->unsignedBigInteger('estimated_max_nanos');
            $table->unsignedBigInteger('provider_cost_nanos')->nullable();
            $table->unsignedBigInteger('charged_nanos')->nullable();

            $table->json('usage')->nullable();
            $table->json('rates')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
