<?php

use App\Http\Controllers\Crm\ActivityController;
use App\Http\Controllers\Crm\CustomerController;
use App\Http\Controllers\Crm\DealController;
use App\Http\Controllers\Crm\LeadConvertController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\RsqController;
use App\Http\Controllers\Crm\MailAdminController;
use App\Http\Controllers\Crm\SupplierMailController;
use App\Http\Controllers\Crm\SupplierReplyController;
use Illuminate\Support\Facades\Route;

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

    // ---- Supplier replies (Microsoft 365 mailbox). Static paths before {id}.
    Route::get('/leads/{lead_id}/supplier-replies', [SupplierReplyController::class, 'forLead'])->middleware('perm:rsq.request,rsq.manage');
    Route::middleware('perm:rsq.manage')->get('/supplier-replies', [SupplierReplyController::class, 'index']);
    Route::post('/supplier-replies/read-all', [SupplierReplyController::class, 'markAllRead']);
    Route::get('/supplier-replies/attachments/{id}/download', [SupplierReplyController::class, 'download'])->middleware('perm:rsq.request,rsq.manage');
    Route::post('/supplier-replies/{id}/read', [SupplierReplyController::class, 'markRead'])->middleware('perm:rsq.request,rsq.manage');
    Route::middleware('perm:rsq.manage')->post('/supplier-replies/{id}/link', [SupplierReplyController::class, 'link']);
    Route::middleware('perm:rsq.manage')->post('/supplier-replies/{id}/ignore', [SupplierReplyController::class, 'ignore']);
    Route::middleware('perm:mail.manage')->get('/mail/status', [MailAdminController::class, 'status']);
    Route::middleware('perm:mail.manage')->post('/mail/poll', [MailAdminController::class, 'poll']);

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

    // ---- Lead -> Customer + Deal conversion
    Route::get('/leads/{lead_id}/convert-preview', [LeadConvertController::class, 'preview'])->middleware('perm:leads.convert');
    Route::post('/leads/{lead_id}/convert', [LeadConvertController::class, 'convert'])->middleware('perm:leads.convert');

    // ---- Customers (static paths before {id})
    Route::get('/customers/options', [CustomerController::class, 'options'])->middleware('perm:customers.view,customers.create');
    Route::get('/customers/duplicate-check', [CustomerController::class, 'duplicateCheck'])->middleware('perm:customers.create,customers.edit');
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('perm:customers.view');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('perm:customers.create');
    Route::get('/customers/{id}', [CustomerController::class, 'show'])->middleware('perm:customers.view');
    Route::patch('/customers/{id}', [CustomerController::class, 'update'])->middleware('perm:customers.edit');
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])->middleware('perm:customers.delete');
    Route::get('/customers/{id}/notes', [CustomerController::class, 'notes'])->middleware('perm:customers.view');
    Route::post('/customers/{id}/notes', [CustomerController::class, 'addNote'])->middleware('perm:customers.edit');
    Route::get('/customers/{id}/activities', [CustomerController::class, 'activities'])->middleware('perm:customers.view');

    // ---- Deals
    Route::get('/deals/options', [DealController::class, 'options'])->middleware('perm:deals.view,deals.create');
    Route::get('/deals', [DealController::class, 'index'])->middleware('perm:deals.view');
    Route::post('/deals', [DealController::class, 'store'])->middleware('perm:deals.create');
    Route::get('/deals/{id}', [DealController::class, 'show'])->middleware('perm:deals.view');
    Route::patch('/deals/{id}', [DealController::class, 'update'])->middleware('perm:deals.edit');
    Route::delete('/deals/{id}', [DealController::class, 'destroy'])->middleware('perm:deals.delete');
    Route::get('/deals/{id}/notes', [DealController::class, 'notes'])->middleware('perm:deals.view');
    Route::post('/deals/{id}/notes', [DealController::class, 'addNote'])->middleware('perm:deals.edit');
    Route::get('/deals/{id}/activities', [DealController::class, 'activities'])->middleware('perm:deals.view');
    Route::delete('/crm-notes/{id}', [DealController::class, 'deleteNote'])->middleware('perm:customers.edit,deals.edit');
});
