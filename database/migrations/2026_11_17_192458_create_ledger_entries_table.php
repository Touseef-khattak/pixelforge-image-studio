<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Null for entries not tied to a generation (e.g. a credit purchase).
            $table->foreignUuid('generation_id')->nullable()->constrained()->cascadeOnDelete();

            // charge: user was billed for a generation; topup: user bought credits.
            $table->string('type');

            // Positive nano-dollars: the amount charged to / credited to the user.
            $table->unsignedBigInteger('amount_nanos');
            // What Pixelforge charged us for the same generation (0 for topups).
            $table->unsignedBigInteger('provider_cost_nanos')->default(0);

            $table->timestamp('created_at')->useCurrent();

            // A generation can be charged at most once, no matter how many events arrive.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
