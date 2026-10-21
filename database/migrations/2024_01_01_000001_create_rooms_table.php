<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedTinyInteger('floor');
            $table->string('building', 50)->default('Main Building');
            $table->unsignedSmallInteger('capacity')->default(40);
            $table->unsignedSmallInteger('people_count')->default(0);
            $table->boolean('is_maintenance')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
