<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('subject_code', 30);
            $table->string('descriptive_title', 120);
            $table->json('days');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('units')->default(3);
            $table->timestamps();

            $table->index(['user_id', 'start_time']);
            $table->index(['room_id', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
