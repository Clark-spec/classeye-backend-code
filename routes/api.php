<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\InstructorController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/alerts', [AlertController::class, 'index']);
    Route::get('/instructors', [InstructorController::class, 'index']);
    Route::get('/rooms/{room}/logs', [RoomController::class, 'logs']);

    // Advisory AI insights. Read-only and available without an API key.
    Route::get('/insights', [InsightsController::class, 'index']);
    Route::get('/insights/rooms/{room}', [InsightsController::class, 'show']);

    // Optional assistant. Falls back to local templates if no provider works.
    Route::post('/assistant', AssistantController::class)->middleware('throttle:ai');

    Route::apiResource('rooms', RoomController::class);
    Route::apiResource('schedules', ScheduleController::class);
});
