<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

/**
 * Authorization is a separate concern from CRUD logic. Laravel discovers this
 * policy automatically for the Room model (App\Models\Room -> RoomPolicy).
 *
 * Teachers can view and edit rooms. Only admins may delete rooms, which shows
 * how a policy centralises a business rule in one readable class.
 */
class RoomPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Room $room): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Room $room): bool
    {
        return true;
    }

    public function delete(User $user, Room $room): bool
    {
        return $user->isAdmin();
    }
}
