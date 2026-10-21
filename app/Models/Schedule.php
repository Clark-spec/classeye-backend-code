<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    use HasFactory;

    /**
     * Canonical day order used by the API and the React schedule board.
     */
    public const DAYS = ['M', 'T', 'W', 'TH', 'F', 'SAT'];

    protected $fillable = [
        'user_id',
        'room_id',
        'subject_code',
        'descriptive_title',
        'days',
        'start_time',
        'end_time',
        'units',
    ];

    protected $casts = [
        'days' => 'array',
        'units' => 'integer',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Always return the days in school-week order, regardless of input order.
     *
     * @return array<int, string>
     */
    public function orderedDays(): array
    {
        $days = $this->days ?? [];

        return array_values(array_intersect(self::DAYS, $days));
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term): void {
            $query->whereLike('subject_code', "%{$term}%", caseSensitive: false)
                ->orWhereLike('descriptive_title', "%{$term}%", caseSensitive: false)
                ->orWhereHas('instructor', function (Builder $query) use ($term): void {
                    $query->whereLike('name', "%{$term}%", caseSensitive: false);
                })
                ->orWhereHas('room', function (Builder $query) use ($term): void {
                    $query->whereLike('name', "%{$term}%", caseSensitive: false);
                });
        });
    }

    public function scopeDay(Builder $query, string $day): Builder
    {
        $day = strtoupper(trim($day));

        return in_array($day, self::DAYS, true) ? $query->whereJsonContains('days', $day) : $query;
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $instructorId > 0 ? $query->where('user_id', $instructorId) : $query;
    }

    public function scopeForRoom(Builder $query, int $roomId): Builder
    {
        return $roomId > 0 ? $query->where('room_id', $roomId) : $query;
    }

    /**
     * Teachers only see their own teaching load; admins see the whole school.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('user_id', $user->id);
    }
}
