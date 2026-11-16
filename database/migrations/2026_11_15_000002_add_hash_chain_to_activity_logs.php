<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A hash chain makes silent edits detectable. Each entry stores the hash
     * of the previous entry for the same room plus its own HMAC.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->string('prev_hash', 64)->nullable()->after('message');
            $table->string('hash', 64)->nullable()->after('prev_hash');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropColumn(['prev_hash', 'hash']);
        });
    }
};
