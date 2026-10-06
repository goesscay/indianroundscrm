<?php

/** Business details printed on quotes (and later invoices). Set the CRM_COMPANY_* values in .env. */
return [
    'company' => [
        'name' => env('CRM_COMPANY_NAME', 'Indian Rounds'),
        'tagline' => env('CRM_COMPANY_TAGLINE', 'Travel & Tours'),
        'address' => env('CRM_COMPANY_ADDRESS', ''),
        'phone' => env('CRM_COMPANY_PHONE', ''),
        'email' => env('CRM_COMPANY_EMAIL', 'enquiry@indianrounds.in'),
        'website' => env('CRM_COMPANY_WEBSITE', 'www.indianrounds.in'),
        'gstin' => env('CRM_COMPANY_GSTIN', ''),
        'state' => env('CRM_COMPANY_STATE', ''),
        'sac' => env('CRM_COMPANY_SAC', '998555'),   // tour operator services; confirm with your accountant
        'bank' => env('CRM_COMPANY_BANK', ''),       // printed on invoices, e.g. "HDFC Bank, A/c 1234, IFSC HDFC0001"
    ],
    'invoice_due_days' => (int) env('CRM_INVOICE_DUE_DAYS', 7),
    'balance_days_before_travel' => (int) env('CRM_BALANCE_DAYS_BEFORE_TRAVEL', 15),
    // Automatic e-mail reminders for overdue invoices (daily job). Off until CRM_AUTO_REMINDERS=true so no customer is mailed by surprise.
    'reminders' => [
        'auto' => filter_var(env('CRM_AUTO_REMINDERS', false), FILTER_VALIDATE_BOOL),
        'first_after_days' => (int) env('CRM_REMINDER_FIRST_AFTER_DAYS', 1),   // days overdue before the first one
        'repeat_days' => (int) env('CRM_REMINDER_REPEAT_DAYS', 7),             // gap between reminders
        'max' => (int) env('CRM_REMINDER_MAX', 3),                             // reminders per invoice, manual and automatic together
    ],
    'quote_valid_days' => (int) env('CRM_QUOTE_VALID_DAYS', 14),
];
