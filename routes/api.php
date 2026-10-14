<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\RobotController;
use App\Http\Controllers\RobotNotificationController;
use App\Http\Controllers\ScrapeController;
use App\Http\Controllers\StoreProfileController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/categories', CategoryController::class);
Route::get('/deals', [DealController::class, 'index']);
Route::get('/deals/{deal}', [DealController::class, 'show']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/scrape', ScrapeController::class)->middleware('throttle:20,1');
    Route::get('/notifications', [RobotNotificationController::class, 'index']);
    Route::post('/notifications/read-all', [RobotNotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [RobotNotificationController::class, 'markRead']);
    Route::get('/robots', [RobotController::class, 'index']);
    Route::post('/robots', [RobotController::class, 'store']);
    Route::post('/robots/preview', [RobotController::class, 'preview'])->middleware('throttle:20,1');
    Route::delete('/store-profiles', [StoreProfileController::class, 'destroy']);
    Route::get('/robots/{robot}', [RobotController::class, 'show']);
    Route::post('/robots/{robot}/preview', [RobotController::class, 'repreview'])->middleware('throttle:20,1');
    Route::get('/robots/{robot}/snapshot', [RobotController::class, 'snapshot']);
    Route::patch('/robots/{robot}', [RobotController::class, 'update']);
    Route::delete('/robots/{robot}', [RobotController::class, 'destroy']);
    Route::post('/robots/{robot}/run', [RobotController::class, 'run']);
    Route::get('/robots/{robot}/prices', [RobotController::class, 'prices']);
    Route::get('/robots/{robot}/stats', [RobotController::class, 'stats']);
    Route::get('/my-deals', [DealController::class, 'myDeals']);
    Route::post('/deals', [DealController::class, 'store']);
    Route::put('/deals/{deal}', [DealController::class, 'update']);
    Route::delete('/deals/{deal}', [DealController::class, 'destroy']);
});
