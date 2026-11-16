<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    /**
     * Activity is immutable: it has a created_at column but no updated_at.
     * Setting UPDATED_AT to null tells Eloquent to only maintain created_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'room_id',
        'user_id',
        'message',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Each entry is chained to the previous entry for the same room. The HMAC
     * uses APP_KEY, so someone with database access but without the server
     * secret cannot silently rewrite history.
     */
    protected static function booted(): void
    {
        static::creating(function (ActivityLog $log): void {
            $log->created_at = $log->created_at ?? now();

            $previousHash = static::query()
                ->where('room_id', $log->room_id)
                ->whereNotNull('hash')
                ->orderByDesc('id')
                ->value('hash');

            $log->prev_hash = $previousHash;
            $log->hash = $log->computeHash($previousHash);
        });
    }

    public function computeHash(?string $previousHash): string
    {
        $payload = json_encode([
            'room_id' => (int) $this->room_id,
            'user_id' => $this->user_id !== null ? (int) $this->user_id : null,
            'message' => (string) $this->message,
            'created_at' => $this->created_at?->utc()->format('Y-m-d H:i:s'),
            'prev_hash' => $previousHash,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $payload, (string) config('app.key'));
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Nullable because the user account may be deleted later. We still keep
     * the history thanks to nullOnDelete() in the migration.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
