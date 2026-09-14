<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\TimeSlotController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/me', [AuthController::class, 'me'])->name('me');

    Route::get('resources', [ResourceController::class, 'index'])->name('resources.index');
    Route::get('resources/{resource}', [ResourceController::class, 'show'])->name('resources.show');
    Route::get('resources/{resource}/time-slots', [TimeSlotController::class, 'index'])->name('resources.time-slots.index');
    Route::get('time-slots/{time_slot}', [TimeSlotController::class, 'show'])->name('time-slots.show');

    Route::middleware('admin')->group(function () {
        Route::post('resources', [ResourceController::class, 'store'])->name('resources.store');
        Route::put('resources/{resource}', [ResourceController::class, 'update'])->name('resources.update');
        Route::patch('resources/{resource}', [ResourceController::class, 'update']);
        Route::delete('resources/{resource}', [ResourceController::class, 'destroy'])->name('resources.destroy');

        Route::post('resources/{resource}/time-slots', [TimeSlotController::class, 'store'])->name('resources.time-slots.store');
        Route::put('time-slots/{time_slot}', [TimeSlotController::class, 'update'])->name('time-slots.update');
        Route::patch('time-slots/{time_slot}', [TimeSlotController::class, 'update']);
        Route::delete('time-slots/{time_slot}', [TimeSlotController::class, 'destroy'])->name('time-slots.destroy');
    });
});
