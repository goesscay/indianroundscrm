<?php

use Illuminate\Support\Facades\Route;

// Core: auth, sessions, users, security audit log, master data, suppliers, allocations.
require __DIR__.'/api_core.php';

// Leads / CRM half (Backend-B): require its route file here, e.g.
require __DIR__.'/api_leads.php';
