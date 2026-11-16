<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'floor',
        'building',
        'capacity',
        'people_count',
        'is_maintenance',
    ];

    protected $casts = [
        'floor' => 'integer',
        'capacity' => 'integer',
        'people_count' => 'integer',
        'is_maintenance' => 'boolean',
    ];

    /**
     * The status is computed from the room data, never stored in the database.
     * This is an accessor: callers read $room->status like a normal attribute.
     */
    protected $appends = ['status'];

    public function getStatusAttribute(): string
    {
        if ($this->is_maintenance) {
            return 'maintenance';
        }

        return $this->people_count > 0 ? 'occupied' : 'empty';
    }

    /**
     * Encapsulation: the over-capacity rule lives inside the model instead of
     * being duplicated in controllers, resources and the React app.
     */
    public function isOverCapacity(): bool
    {
        return $this->people_count > $this->capacity;
    }

    /**
     * A room owns many activity log entries. Newest entries come first.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->latest();
    }

    /**
     * Query scopes keep filtering logic reusable and composable. They are the
     * abstraction layer between controllers and raw SQL.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term): void {
            $query->whereLike('name', "%{$term}%", caseSensitive: false)
                ->orWhereLike('building', "%{$term}%", caseSensitive: false);
        });
    }

    /**
     * Status filtering is done in SQL so it composes with pagination/search.
     * Maintenance takes priority over the people count, matching the accessor.
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'maintenance' => $query->where('is_maintenance', true),
            'occupied' => $query->where('is_maintenance', false)->where('people_count', '>', 0),
            'empty' => $query->where('is_maintenance', false)->where('people_count', 0),
            default => $query,
        };
    }

    public function scopeFloor(Builder $query, int $floor): Builder
    {
        return $floor > 0 ? $query->where('floor', $floor) : $query;
    }

    public function scopeOverCapacity(Builder $query): Builder
    {
        return $query->whereColumn('people_count', '>', 'capacity');
    }
}
