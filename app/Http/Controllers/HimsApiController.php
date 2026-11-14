<?php

namespace App\Http\Controllers;

use App\Support\LegacyBackend;

/**
 * /backend/api.php — the API the HIMS pages call (?resource=...).
 * Runs the HIMS backend (app/Legacy/backend/api.php) inside Laravel. That file sends its own
 * JSON response and ends the request.
 */
class HimsApiController extends Controller
{
    public function __invoke(): never
    {
        LegacyBackend::boot();
        require LegacyBackend::path('api.php');
        exit;
    }
}
