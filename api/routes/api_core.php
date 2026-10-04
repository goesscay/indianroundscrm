<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => ['service' => 'Indian Rounds CRM', 'status' => 'ok']);

// ---- Auth (public) ----
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-reset');
Route::get('/auth/verify-reset-token/{token}', [AuthController::class, 'verifyResetToken'])->middleware('throttle:auth-reset');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-reset');

Route::middleware('auth:sanctum')->group(function () {
    // ---- Auth (session) ----
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // ---- Sessions ----
    Route::get('/sessions/mine', [SessionController::class, 'mine']);
    Route::get('/sessions', [SessionController::class, 'all'])->middleware('role:admin');
    Route::post('/sessions/{jti}/revoke', [SessionController::class, 'revoke']);

    // ---- Users + security audit (admin) ----
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{uid}', [UserController::class, 'update']);
        Route::delete('/users/{uid}', [UserController::class, 'destroy']);
        Route::post('/users/{uid}/force-reset', [UserController::class, 'forceReset']);
        Route::post('/users/{uid}/unlock', [UserController::class, 'unlock']);
        Route::post('/users/{uid}/status', [UserController::class, 'status']);
        Route::post('/users/{uid}/logout', [UserController::class, 'logoutUser']);
        Route::get('/security-audit-log', [UserController::class, 'securityAuditLog']);
    });

    // ---- Master data ----
    Route::get('/master', [MasterDataController::class, 'masterIndex']);
    Route::post('/master', [MasterDataController::class, 'masterStore'])->middleware('role:admin');
    Route::delete('/master/{mid}', [MasterDataController::class, 'masterDestroy'])->middleware('role:admin');

    Route::get('/destination-cities', [MasterDataController::class, 'citiesIndex']);
    Route::get('/destination-cities/{destination}', [MasterDataController::class, 'citiesShow']);
    Route::put('/destination-cities/{destination}', [MasterDataController::class, 'citiesUpdate'])->middleware('role:admin');

    Route::get('/allocations', [MasterDataController::class, 'allocationsIndex']);
    Route::post('/allocations', [MasterDataController::class, 'allocationsStore'])->middleware('role:admin');
    Route::delete('/allocations/{aid}', [MasterDataController::class, 'allocationsDestroy'])->middleware('role:admin');

    Route::get('/suppliers', [MasterDataController::class, 'suppliersIndex']);
    Route::post('/suppliers', [MasterDataController::class, 'suppliersStore'])->middleware('role:admin,operations');
    Route::delete('/suppliers/{sid}', [MasterDataController::class, 'suppliersDestroy'])->middleware('role:admin,operations');
});
