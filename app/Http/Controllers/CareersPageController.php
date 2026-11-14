<?php

namespace App\Http\Controllers;

use App\Support\LegacyBackend;

/** /careers.html — the public careers page with a link preview for each job. */
class CareersPageController extends Controller
{
    public function __invoke(): never
    {
        LegacyBackend::boot();
        require_once LegacyBackend::path('config.php');
        require_once LegacyBackend::path('hiring.php');
        require_once LegacyBackend::path('share-preview.php');
        himsCareersPage();
        exit;
    }
}
