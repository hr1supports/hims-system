<?php

// HIMS settings. Values come from .env so nothing secret is stored in the code.
return [
    // Where uploaded files are kept (outside public/, so the web server never serves them directly).
    'resume_dir' => env('HR3_UPLOAD_DIR', storage_path('app/uploads/resumes')),
    'exchange_dir' => env('HIMS_EXCHANGE_DIR', storage_path('app/uploads/exchange')),
    'receipt_dir' => env('HIMS_RECEIPT_DIR', storage_path('app/uploads/receipts')),

    'timezone' => env('HR3_TIMEZONE', 'Asia/Manila'),

    // Optional fixed keys for the partner systems. Normally the Super Admin creates keys in
    // HIMS > File Exchange > Connections instead (stored hashed in the integration_keys table).
    'partner_keys' => [
        'HR2' => env('HR2_INTEGRATION_KEY'),
        'HR3' => env('HR3_INTEGRATION_KEY'),
        'HR4' => env('HR4_INTEGRATION_KEY'),
        'ADMIN' => env('ADMIN_INTEGRATION_KEY'), // the Administrators group's system
    ],

    'partners' => ['HR2', 'HR3', 'HR4', 'ADMIN'],

    // HIMS sends data to the partners (File Exchange > Connections > "Data HIMS sends").
    // Addresses and keys are saved there by the Super Admin; these are only the time limits.
    'push_connect_timeout' => (int) env('HIMS_PUSH_CONNECT_TIMEOUT', 5),
    'push_timeout' => (int) env('HIMS_PUSH_TIMEOUT', 10),
    'exchange_max_kb' => 10240,
];
