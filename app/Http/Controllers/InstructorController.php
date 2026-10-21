<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstructorController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $instructors = User::query()
            ->where('role', 'teacher')
            ->orderBy('name')
            ->get();

        return UserResource::collection($instructors);
    }
}
