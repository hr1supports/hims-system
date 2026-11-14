<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Re-send data HIMS could not deliver to HR2 / HR3 / HR4 / Administrators (for example when they were offline).
// Run by hand, or every few minutes with the Laravel scheduler.
Artisan::command('hims:push-retry {partner?}', function (?string $partner = null) {
    $result = \App\Services\PartnerPush::retry($partner ? strtoupper($partner) : null);
    $this->info("Sent {$result['sent']}, still failing {$result['failed']}.");
})->purpose('Re-send pending HIMS deliveries to the partner systems');
