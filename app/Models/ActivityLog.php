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
