<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstructorController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/alerts', [AlertController::class, 'index']);
    Route::get('/instructors', [InstructorController::class, 'index']);
    Route::get('/rooms/{room}/logs', [RoomController::class, 'logs']);

    Route::apiResource('rooms', RoomController::class);
    Route::apiResource('schedules', ScheduleController::class);
});
