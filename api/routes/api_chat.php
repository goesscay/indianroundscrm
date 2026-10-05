<?php

use App\Http\Controllers\Chat\ChatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'perm:chat.use'])->prefix('chat')->group(function () {
    Route::get('/users', [ChatController::class, 'users']);
    Route::get('/unread-count', [ChatController::class, 'unreadCount']);
    Route::get('/conversations', [ChatController::class, 'conversations']);
    Route::post('/conversations/direct', [ChatController::class, 'direct']);
    Route::get('/conversations/{id}/messages', [ChatController::class, 'messages']);
    Route::post('/conversations/{id}/messages', [ChatController::class, 'send'])->middleware('throttle:chat-send');
    Route::post('/conversations/{id}/read', [ChatController::class, 'markRead']);
    Route::delete('/messages/{id}', [ChatController::class, 'deleteMessage']);

    Route::middleware('perm:chat.create_groups')->group(function () {
        Route::post('/groups', [ChatController::class, 'createGroup']);
        Route::patch('/groups/{id}', [ChatController::class, 'updateGroup']);
        Route::delete('/groups/{id}', [ChatController::class, 'deleteGroup']);
    });
});
