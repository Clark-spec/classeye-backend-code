<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Application validation is not a security boundary: direct database
     * writes must also respect the domain rules. PostgreSQL is the production
     * engine, so the constraints are added there and skipped for SQLite tests.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_capacity_positive CHECK (capacity > 0)');
        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_people_count_non_negative CHECK (people_count >= 0)');
        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_floor_range CHECK (floor BETWEEN 1 AND 10)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_capacity_positive');
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_people_count_non_negative');
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_floor_range');
    }
};
