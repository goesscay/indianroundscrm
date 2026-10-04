<?php

use App\Http\Controllers\Crm\AiController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\RsqController;
use App\Http\Controllers\Crm\SupplierMailController;
use Illuminate\Support\Facades\Route;

// Public: Resend event webhook (no session)
Route::post('/webhooks/resend', [SupplierMailController::class, 'resendWebhook']);

Route::middleware('auth:sanctum')->group(function () {
    // ---- Leads (static paths first so they win over /leads/{lead_id})
    Route::get('/leads/duplicate-check', [LeadController::class, 'duplicateCheck']);
    Route::get('/leads/calendar', [LeadController::class, 'calendar']);
    Route::middleware('role:sales,admin,operations')->post('/leads', [LeadController::class, 'store']);
    Route::get('/leads', [LeadController::class, 'index']);
    Route::get('/leads/{lead_id}', [LeadController::class, 'show']);
    Route::patch('/leads/{lead_id}', [LeadController::class, 'update']);
    Route::middleware('role:admin')->delete('/leads/{lead_id}', [LeadController::class, 'destroy']);
    Route::middleware('role:admin,operations')->post('/leads/{lead_id}/reassign', [LeadController::class, 'reassign']);

    Route::get('/leads/{lead_id}/notes', [LeadController::class, 'notes']);
    Route::post('/leads/{lead_id}/notes', [LeadController::class, 'addNote']);
    Route::delete('/notes/{nid}', [LeadController::class, 'deleteNote']);
    Route::get('/leads/{lead_id}/contact-events', [LeadController::class, 'contacts']);
    Route::post('/leads/{lead_id}/contact-events', [LeadController::class, 'logContact']);
    Route::get('/followups/upcoming', [LeadController::class, 'upcomingFollowups']);

    // ---- RSQ requests
    Route::middleware('role:admin,operations,sales')->post('/rsq-requests', [RsqController::class, 'create']);
    Route::middleware('role:admin,operations')->get('/rsq-requests', [RsqController::class, 'index']);
    Route::get('/rsq-requests/{lead_id}', [RsqController::class, 'show']);

    // ---- Supplier response tracker
    Route::middleware('role:admin,operations')->get('/lead-suppliers/dashboard/summary', [RsqController::class, 'dashboard']);
    Route::get('/lead-suppliers/{lead_id}', [RsqController::class, 'leadSuppliers']);
    Route::middleware('role:admin,operations')->patch('/lead-suppliers/{tracker_id}', [RsqController::class, 'updateSupplier']);
    Route::middleware('role:admin,operations')->post('/lead-suppliers/{tracker_id}/followup', [RsqController::class, 'followup']);

    // ---- Supplier mail
    Route::middleware('role:admin,operations')->post('/supplier-mail/send', [SupplierMailController::class, 'send']);
    Route::middleware('role:admin,operations')->get('/supplier-mail/preview/{lead_id}', [SupplierMailController::class, 'preview']);
    Route::get('/supplier-mail/{lead_id}', [SupplierMailController::class, 'history']);

    // ---- Dashboard & reporting
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::middleware('role:admin')->get('/dashboard/leaderboard', [DashboardController::class, 'leaderboard']);
    Route::get('/notifications', [DashboardController::class, 'notifications']);
    Route::middleware('role:admin')->get('/activity', [DashboardController::class, 'activity']);
    Route::middleware('role:admin')->get('/activity/facets', [DashboardController::class, 'activityFacets']);

    // ---- AI
    Route::post('/ai/itinerary', [AiController::class, 'itinerary']);
    Route::post('/ai/quote-recommend', [AiController::class, 'quoteRecommend']);
});
