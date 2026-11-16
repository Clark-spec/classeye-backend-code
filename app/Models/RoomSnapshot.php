<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One point-in-time occupancy measurement. Snapshots are append-only: they
 * give the insights engine a real time series instead of parsed log strings.
 */
class RoomSnapshot extends Model
{
    protected $fillable = [
        'room_id',
        'capacity',
        'people_count',
        'is_maintenance',
        'recorded_at',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'people_count' => 'integer',
        'is_maintenance' => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
