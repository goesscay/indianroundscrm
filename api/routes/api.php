<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Core: auth, sessions, users, security audit log, master data, suppliers, allocations.
require __DIR__.'/api_core.php';

// Leads / CRM half (Backend-B): require its route file here, e.g.
require __DIR__.'/api_leads.php';

// Supplier portal (separate sign-in, no ERP access).
require __DIR__.'/api_portal.php';

// Internal team chat (direct messages + admin-created groups).
require __DIR__.'/api_chat.php';

// Real-time chat: private channel definitions + auth endpoint (POST /api/broadcasting/auth, Sanctum SPA session).
require __DIR__.'/channels.php';
Broadcast::routes(['middleware' => ['auth:sanctum']]);
