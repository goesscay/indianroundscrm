<?php

use App\Http\Controllers\Crm\ActivityController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\RsqController;
use App\Http\Controllers\Crm\SupplierMailController;
use Illuminate\Support\Facades\Route;

// Public: Resend event webhook (no session)
Route::post('/webhooks/resend', [SupplierMailController::class, 'resendWebhook']);

Route::middleware('auth:sanctum')->group(function () {
    // ---- Leads (static paths first so they win over /leads/{lead_id})
    Route::get('/leads/duplicate-check', [LeadController::class, 'duplicateCheck'])->middleware('perm:leads.create');
    Route::get('/leads/calendar', [LeadController::class, 'calendar'])->middleware('perm:calendar.view');
    Route::get('/leads/filters', [LeadController::class, 'filterOptions']);
    Route::middleware('perm:leads.create')->post('/leads', [LeadController::class, 'store']);
    Route::get('/leads', [LeadController::class, 'index']);
    Route::get('/leads/{lead_id}', [LeadController::class, 'show']);
    Route::middleware('perm:leads.edit,leads.edit_limited')->patch('/leads/{lead_id}', [LeadController::class, 'update']);
    Route::middleware('perm:leads.delete')->delete('/leads/{lead_id}', [LeadController::class, 'destroy']);
    Route::middleware('perm:leads.reassign')->post('/leads/{lead_id}/reassign', [LeadController::class, 'reassign']);

    Route::get('/leads/{lead_id}/notes', [LeadController::class, 'notes'])->middleware('perm:leads.notes');
    Route::post('/leads/{lead_id}/notes', [LeadController::class, 'addNote'])->middleware('perm:leads.notes');
    Route::delete('/notes/{nid}', [LeadController::class, 'deleteNote'])->middleware('perm:leads.notes');
    Route::get('/leads/{lead_id}/contact-events', [LeadController::class, 'contacts'])->middleware('perm:leads.contact_events');
    Route::post('/leads/{lead_id}/contact-events', [LeadController::class, 'logContact'])->middleware('perm:leads.contact_events');
    Route::get('/followups/upcoming', [LeadController::class, 'upcomingFollowups']);

    // ---- RSQ requests
    Route::middleware('perm:rsq.request,rsq.manage')->post('/rsq-requests', [RsqController::class, 'create']);
    Route::middleware('perm:rsq.manage')->get('/rsq-requests', [RsqController::class, 'index']);
    Route::get('/rsq-requests/{lead_id}', [RsqController::class, 'show'])->middleware('perm:rsq.request,rsq.manage');

    // ---- Supplier response tracker
    Route::middleware('perm:supplier_dashboard.view')->get('/lead-suppliers/dashboard/summary', [RsqController::class, 'dashboard']);
    Route::get('/lead-suppliers/{lead_id}', [RsqController::class, 'leadSuppliers'])->middleware('perm:suppliers.view,suppliers.manage');
    Route::middleware('perm:suppliers.manage')->patch('/lead-suppliers/{tracker_id}', [RsqController::class, 'updateSupplier']);
    Route::middleware('perm:suppliers.manage')->post('/lead-suppliers/{tracker_id}/followup', [RsqController::class, 'followup']);

    // ---- Supplier mail
    Route::middleware('perm:supplier_mail.send')->post('/supplier-mail/send', [SupplierMailController::class, 'send']);
    Route::middleware('perm:supplier_mail.send')->get('/supplier-mail/preview/{lead_id}', [SupplierMailController::class, 'preview']);
    Route::get('/supplier-mail/{lead_id}', [SupplierMailController::class, 'history'])->middleware('perm:suppliers.view,supplier_mail.send');

    // ---- Dashboard & reporting
    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->middleware('perm:dashboard.view');
    Route::middleware('perm:dashboard.leaderboard')->get('/dashboard/leaderboard', [DashboardController::class, 'leaderboard']);
    Route::get('/notifications', [DashboardController::class, 'notifications']);
    Route::middleware('perm:activity.view')->get('/activity', [DashboardController::class, 'activity']);
    Route::middleware('perm:activity.view')->get('/activity/facets', [DashboardController::class, 'activityFacets']);

    // ---- Activities (tasks, calls, meetings, emails, follow-ups); static paths before {id}
    Route::get('/activities/calendar', [ActivityController::class, 'calendar'])->middleware('perm:activities.view');
    Route::get('/activities/options', [ActivityController::class, 'options'])->middleware('perm:activities.view,activities.create,activities.manage');
    Route::get('/activities', [ActivityController::class, 'index'])->middleware('perm:activities.view');
    Route::post('/activities', [ActivityController::class, 'store'])->middleware('perm:activities.create');
    Route::get('/activities/{id}', [ActivityController::class, 'show'])->middleware('perm:activities.view');
    Route::patch('/activities/{id}', [ActivityController::class, 'update'])->middleware('perm:activities.create,activities.manage');
    Route::delete('/activities/{id}', [ActivityController::class, 'destroy'])->middleware('perm:activities.create,activities.manage');
    Route::get('/leads/{lead_id}/activities', [ActivityController::class, 'forLead'])->middleware('perm:activities.view');
});
