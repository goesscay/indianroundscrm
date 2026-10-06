<?php

use App\Http\Controllers\Crm\ActivityController;
use App\Http\Controllers\Crm\CustomerController;
use App\Http\Controllers\Crm\CustomerReplyController;
use App\Http\Controllers\Crm\DealController;
use App\Http\Controllers\Crm\ForecastController;
use App\Http\Controllers\Crm\AccountController;
use App\Http\Controllers\Crm\FinanceController;
use App\Http\Controllers\Crm\HomeController;
use App\Http\Controllers\Crm\InvoiceController;
use App\Http\Controllers\Crm\JournalController;
use App\Http\Controllers\Crm\PackageController;
use App\Http\Controllers\Crm\PayablesController;
use App\Http\Controllers\Crm\ReportsController;
use App\Http\Controllers\Crm\ReceiptController;
use App\Http\Controllers\Crm\QuoteController;
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
    Route::get('/dashboard/overview', [HomeController::class, 'overview'])->middleware('perm:dashboard.view');
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
    Route::get('/customers/{id}/replies', [CustomerReplyController::class, 'customer'])->middleware('perm:customers.view');
    Route::get('/deals/{id}/replies', [CustomerReplyController::class, 'deal'])->middleware('perm:deals.view');
    Route::get('/quotes/{id}/replies', [CustomerReplyController::class, 'quote'])->middleware('perm:quotes.view');
    Route::get('/invoices/{id}/replies', [CustomerReplyController::class, 'invoice'])->middleware('perm:invoices.view');
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

    // ---- Forecasts (financial-year quarters)
    Route::get('/forecasts', [ForecastController::class, 'show'])->middleware('perm:forecasts.view');
    Route::get('/forecasts/deals', [ForecastController::class, 'dealsList'])->middleware('perm:forecasts.view');
    Route::put('/forecasts/targets', [ForecastController::class, 'setTarget'])->middleware('perm:forecasts.manage_targets');

    // ---- Packages (catalogue)
    Route::get('/packages/options', [PackageController::class, 'options'])->middleware('perm:packages.view,packages.manage,quotes.create');
    Route::get('/packages', [PackageController::class, 'index'])->middleware('perm:packages.view,packages.manage,quotes.create');
    Route::post('/packages', [PackageController::class, 'store'])->middleware('perm:packages.manage');
    Route::get('/packages/{id}', [PackageController::class, 'show'])->middleware('perm:packages.view,packages.manage,quotes.create');
    Route::get('/packages/{id}/price', [PackageController::class, 'price'])->middleware('perm:packages.view,packages.manage,quotes.create');
    Route::patch('/packages/{id}', [PackageController::class, 'update'])->middleware('perm:packages.manage');
    Route::delete('/packages/{id}', [PackageController::class, 'destroy'])->middleware('perm:packages.manage');

    // ---- Quotes
    Route::get('/quotes', [QuoteController::class, 'index'])->middleware('perm:quotes.view');
    Route::post('/quotes', [QuoteController::class, 'store'])->middleware('perm:quotes.create');
    Route::get('/quotes/{id}', [QuoteController::class, 'show'])->middleware('perm:quotes.view');
    Route::patch('/quotes/{id}', [QuoteController::class, 'update'])->middleware('perm:quotes.create');
    Route::delete('/quotes/{id}', [QuoteController::class, 'destroy'])->middleware('perm:quotes.delete,quotes.create');
    Route::post('/quotes/{id}/revise', [QuoteController::class, 'revise'])->middleware('perm:quotes.create');
    Route::post('/quotes/{id}/status', [QuoteController::class, 'setStatus'])->middleware('perm:quotes.send');
    Route::get('/quotes/{id}/pdf', [QuoteController::class, 'pdf'])->middleware('perm:quotes.view');
    Route::get('/quotes/{id}/email-preview', [QuoteController::class, 'emailPreview'])->middleware('perm:quotes.send');
    Route::post('/quotes/{id}/send', [QuoteController::class, 'send'])->middleware('perm:quotes.send');

    // ---- Invoices, receipts, credit notes
    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('perm:invoices.view');
    Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('perm:invoices.create');
    Route::post('/quotes/{id}/invoice', [InvoiceController::class, 'fromQuote'])->middleware('perm:invoices.create');
    Route::get('/invoices/{id}', [InvoiceController::class, 'show'])->middleware('perm:invoices.view');
    Route::patch('/invoices/{id}', [InvoiceController::class, 'update'])->middleware('perm:invoices.create');
    Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy'])->middleware('perm:invoices.delete,invoices.create');
    Route::post('/invoices/{id}/issue', [InvoiceController::class, 'issue'])->middleware('perm:invoices.issue');
    Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel'])->middleware('perm:invoices.issue');
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf'])->middleware('perm:invoices.view');
    Route::get('/invoices/{id}/email-preview', [InvoiceController::class, 'emailPreview'])->middleware('perm:invoices.issue');
    Route::post('/invoices/{id}/send', [InvoiceController::class, 'send'])->middleware('perm:invoices.issue');
    Route::post('/invoices/{id}/receipts', [InvoiceController::class, 'addReceipt'])->middleware('perm:receipts.record');
    Route::post('/invoices/{id}/credit-notes', [InvoiceController::class, 'addCreditNote'])->middleware('perm:invoices.credit');
    Route::post('/invoices/{id}/refunds', [InvoiceController::class, 'addRefund'])->middleware('perm:invoices.credit');

    Route::get('/receipts', [ReceiptController::class, 'index'])->middleware('perm:receipts.view');
    Route::get('/receipts/{id}', [ReceiptController::class, 'show'])->middleware('perm:receipts.view');
    Route::post('/customers/{id}/advances', [ReceiptController::class, 'addAdvance'])->middleware('perm:receipts.record');
    Route::post('/receipts/{id}/apply', [ReceiptController::class, 'apply'])->middleware('perm:receipts.record');
    Route::post('/receipts/{id}/cancel', [ReceiptController::class, 'cancel'])->middleware('perm:receipts.cancel');
    Route::get('/receipts/{id}/pdf', [ReceiptController::class, 'pdf'])->middleware('perm:receipts.view');
    Route::get('/receipts/{id}/email-preview', [ReceiptController::class, 'emailPreview'])->middleware('perm:receipts.record');
    Route::post('/receipts/{id}/send', [ReceiptController::class, 'send'])->middleware('perm:receipts.record');

    // ---- Accounts (finance): chart, banking, journal, ledger, trial balance
    Route::get('/finance/dashboard', [FinanceController::class, 'dashboard'])->middleware('perm:finance.view');
    Route::get('/finance/settings', [FinanceController::class, 'settings'])->middleware('perm:finance.view');
    Route::put('/finance/settings', [FinanceController::class, 'updateSettings'])->middleware('perm:finance.manage');
    Route::get('/finance/trial-balance', [FinanceController::class, 'trialBalance'])->middleware('perm:finance.view');
    Route::get('/finance/ledger/{id}', [FinanceController::class, 'ledger'])->middleware('perm:finance.view');

    Route::get('/finance/accounts', [AccountController::class, 'index'])->middleware('perm:finance.view,finance.journal');
    Route::post('/finance/accounts', [AccountController::class, 'store'])->middleware('perm:finance.manage');
    Route::patch('/finance/accounts/{id}', [AccountController::class, 'update'])->middleware('perm:finance.manage');
    Route::delete('/finance/accounts/{id}', [AccountController::class, 'destroy'])->middleware('perm:finance.manage');

    Route::get('/finance/banks', [AccountController::class, 'banks'])->middleware('perm:finance.view');
    Route::get('/finance/bank-options', [AccountController::class, 'bankOptions'])->middleware('perm:finance.view,receipts.record');
    Route::post('/finance/banks', [AccountController::class, 'storeBank'])->middleware('perm:finance.manage');
    Route::patch('/finance/banks/{id}', [AccountController::class, 'updateBank'])->middleware('perm:finance.manage');

    Route::get('/finance/journal', [JournalController::class, 'index'])->middleware('perm:finance.view');
    Route::post('/finance/journal', [JournalController::class, 'store'])->middleware('perm:finance.journal');
    Route::get('/finance/journal/{id}', [JournalController::class, 'show'])->middleware('perm:finance.view');
    Route::post('/finance/journal/{id}/reverse', [JournalController::class, 'reverse'])->middleware('perm:finance.journal');

    // ---- Accounts (part B): supplier bills and payments, other money in/out, reports, statements
    Route::get('/finance/bills', [PayablesController::class, 'bills'])->middleware('perm:finance.view');
    Route::post('/finance/bills', [PayablesController::class, 'storeBill'])->middleware('perm:finance.payables');
    Route::get('/finance/bills/{id}', [PayablesController::class, 'showBill'])->middleware('perm:finance.view');
    Route::post('/finance/bills/{id}/cancel', [PayablesController::class, 'cancelBill'])->middleware('perm:finance.payables');
    Route::post('/finance/bills/{id}/payments', [PayablesController::class, 'payBill'])->middleware('perm:finance.payables');
    Route::get('/finance/supplier-payments', [PayablesController::class, 'payments'])->middleware('perm:finance.view');
    Route::get('/finance/supplier-payments/{id}', [PayablesController::class, 'showPayment'])->middleware('perm:finance.view');
    Route::post('/finance/supplier-payments/{id}/apply', [PayablesController::class, 'applyAdvance'])->middleware('perm:finance.payables');
    Route::post('/finance/supplier-payments/{id}/cancel', [PayablesController::class, 'cancelPayment'])->middleware('perm:finance.payables');
    Route::post('/finance/suppliers/{id}/advances', [PayablesController::class, 'advance'])->middleware('perm:finance.payables');
    Route::get('/finance/money', [PayablesController::class, 'money'])->middleware('perm:finance.view');
    Route::post('/finance/money', [PayablesController::class, 'storeMoney'])->middleware('perm:finance.payables');
    Route::post('/finance/money/{id}/cancel', [PayablesController::class, 'cancelMoney'])->middleware('perm:finance.payables');

    Route::get('/finance/reports/profit-loss', [ReportsController::class, 'profitLoss'])->middleware('perm:finance.view');
    Route::get('/finance/reports/balance-sheet', [ReportsController::class, 'balanceSheet'])->middleware('perm:finance.view');
    Route::get('/finance/reports/receivables-ageing', [ReportsController::class, 'receivablesAgeing'])->middleware('perm:finance.view');
    Route::get('/finance/reports/payables-ageing', [ReportsController::class, 'payablesAgeing'])->middleware('perm:finance.view');
    Route::get('/finance/reports/gst-summary', [ReportsController::class, 'gstSummary'])->middleware('perm:finance.view');
    Route::get('/finance/party-ledger', [ReportsController::class, 'partyLedger'])->middleware('perm:finance.view');
    Route::get('/deals/{id}/books', [ReportsController::class, 'dealBooks'])->middleware('perm:finance.view');
});
