<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activity logs are human-readable prose. Forecasting needs numbers, so
     * every occupancy change also writes an immutable snapshot row.
     */
    public function up(): void
    {
        Schema::create('room_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('people_count');
            $table->boolean('is_maintenance')->default(false);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['room_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_snapshots');
    }
};
