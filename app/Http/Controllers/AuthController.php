<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::where('staff_id', $credentials['staff_id'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'staff_id' => ['Invalid Staff ID or password.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken('class-eye')->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function forgotPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $name = trim((string) preg_replace('/\s+/', ' ', $validated['name']));

        $matches = User::where('role', 'teacher')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get();

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                'name' => ['No teacher account matches that full name.'],
            ]);
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'name' => ['More than one teacher has that name. Please ask an admin to reset your password.'],
            ]);
        }

        $user = $matches->first();
        $user->password = $validated['password'];
        $user->save();
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password reset successfully. You can sign in with your new password.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
