<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Only these attributes may be mass assigned. Encapsulation: controllers
     * cannot accidentally overwrite the primary key or timestamps.
     */
    protected $fillable = [
        'name',
        'staff_id',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The "hashed" cast automatically hashes passwords when they are assigned.
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * Authorization helper used by RoomPolicy to expose admin-only actions.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
