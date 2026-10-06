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
    ],
    'quote_valid_days' => (int) env('CRM_QUOTE_VALID_DAYS', 14),
];
