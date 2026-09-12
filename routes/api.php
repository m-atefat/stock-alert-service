<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PriceAlertController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/alerts', [PriceAlertController::class, 'index']);
    Route::post('/alerts', [PriceAlertController::class, 'store']);
    Route::delete('/alerts/{alertId}', [PriceAlertController::class, 'destroy']);
});
