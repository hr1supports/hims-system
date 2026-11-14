<?php

use App\Http\Controllers\CareersPageController;
use App\Http\Controllers\HimsApiController;
use Illuminate\Support\Facades\Route;

// The HIMS app itself: the pages in public/ call /backend/api.php (same address as before Laravel).
Route::get('/', fn () => response()->file(public_path('index.html'), ['Cache-Control' => 'no-cache']));
Route::match(['get', 'post', 'options'], '/backend/api.php', HimsApiController::class);

// Careers page with link-preview tags (LinkedIn / Facebook) for each job.
Route::get('/careers.html', CareersPageController::class);
