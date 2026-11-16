<?php

use App\Http\Controllers\ApiLinkController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\ReadOnlyApiController;
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

Route::prefix('v1')->group(function (): void {
    Route::post('/login', [AuthController::class, 'apiLogin'])
        ->middleware('throttle:5,1')
        ->name('api.v1.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])
            ->name('api.v1.logout');
        Route::post('/api-link', [ApiLinkController::class, 'store'])
            ->middleware('throttle:api-link')
            ->name('api.v1.api-link.store');
        Route::post('/api-link/regenerate', [ApiLinkController::class, 'regenerate'])
            ->middleware('throttle:api-link')
            ->name('api.v1.api-link.regenerate');
        Route::delete('/api-link', [ApiLinkController::class, 'destroy'])
            ->middleware('throttle:api-link')
            ->name('api.v1.api-link.destroy');

        Route::get('/', [ReadOnlyApiController::class, 'apiIndex'])
            ->name('api.v1.index');
        Route::get('/all', [ReadOnlyApiController::class, 'all'])
            ->name('api.v1.all');

        foreach ([
            'categories',
            'deals',
            'comments',
            'votes',
            'robots',
            'robot-runs',
            'store-profiles',
            'robot-notifications',
        ] as $resource) {
            Route::get('/'.$resource, [ReadOnlyApiController::class, 'index'])
                ->defaults('resource', $resource)
                ->name('api.v1.'.$resource.'.index');

            Route::get('/'.$resource.'/{id}', [ReadOnlyApiController::class, 'show'])
                ->whereNumber('id')
                ->defaults('resource', $resource)
                ->name('api.v1.'.$resource.'.show');
        }

        Route::get('/users', [ReadOnlyApiController::class, 'index'])
            ->defaults('resource', 'users')
            ->name('api.v1.users.index');
        Route::get('/users/{id}', [ReadOnlyApiController::class, 'show'])
            ->whereNumber('id')
            ->defaults('resource', 'users')
            ->name('api.v1.users.show');
    });
});

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
