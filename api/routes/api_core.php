<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\RoleController;
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
    Route::get('/sessions', [SessionController::class, 'all'])->middleware('perm:security.view');
    Route::post('/sessions/{jti}/revoke', [SessionController::class, 'revoke']);

    // ---- Users + security audit ----
    Route::get('/users', [UserController::class, 'index'])->middleware('perm:users.view,users.manage');
    Route::middleware('perm:users.manage')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{uid}', [UserController::class, 'update']);
        Route::delete('/users/{uid}', [UserController::class, 'destroy']);
        Route::post('/users/{uid}/force-reset', [UserController::class, 'forceReset']);
        Route::post('/users/{uid}/unlock', [UserController::class, 'unlock']);
        Route::post('/users/{uid}/status', [UserController::class, 'status']);
        Route::post('/users/{uid}/logout', [UserController::class, 'logoutUser']);
    });
    Route::get('/security-audit-log', [UserController::class, 'securityAuditLog'])->middleware('perm:security.view');

    // ---- Roles & permissions ----
    Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('perm:roles.manage,users.manage');
    Route::get('/roles', [RoleController::class, 'index'])->middleware('perm:roles.manage,users.manage');
    Route::middleware('perm:roles.manage')->group(function () {
        Route::post('/roles', [RoleController::class, 'store']);
        Route::patch('/roles/{id}', [RoleController::class, 'update']);
        Route::delete('/roles/{id}', [RoleController::class, 'destroy']);
        Route::get('/roles/{id}/users', [RoleController::class, 'users']);
    });

    // ---- Master data ----
    Route::get('/lead-options', [MasterDataController::class, 'leadOptions'])->middleware('perm:leads.create,leads.edit,leads.edit_limited');
    Route::get('/master', [MasterDataController::class, 'masterIndex'])->middleware('perm:master.view,master.manage');
    Route::post('/master', [MasterDataController::class, 'masterStore'])->middleware('perm:master.manage');
    Route::delete('/master/{mid}', [MasterDataController::class, 'masterDestroy'])->middleware('perm:master.manage');

    Route::get('/destination-cities', [MasterDataController::class, 'citiesIndex'])->middleware('perm:master.view,master.manage');
    Route::get('/destination-cities/{destination}', [MasterDataController::class, 'citiesShow'])->middleware('perm:master.view,master.manage');
    Route::put('/destination-cities/{destination}', [MasterDataController::class, 'citiesUpdate'])->middleware('perm:master.manage');

    Route::get('/allocations', [MasterDataController::class, 'allocationsIndex'])->middleware('perm:master.view,allocations.manage');
    Route::post('/allocations', [MasterDataController::class, 'allocationsStore'])->middleware('perm:allocations.manage');
    Route::delete('/allocations/{aid}', [MasterDataController::class, 'allocationsDestroy'])->middleware('perm:allocations.manage');

    Route::get('/suppliers', [MasterDataController::class, 'suppliersIndex'])->middleware('perm:suppliers.view,suppliers.manage');
    Route::post('/suppliers', [MasterDataController::class, 'suppliersStore'])->middleware('perm:suppliers.manage');
    Route::delete('/suppliers/{sid}', [MasterDataController::class, 'suppliersDestroy'])->middleware('perm:suppliers.manage');
});
