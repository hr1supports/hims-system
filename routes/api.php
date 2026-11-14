<?php

use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\HireOrderController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\VacancyController;
use Illuminate\Support\Facades\Route;

/*
| HIMS integration API for HR2, HR3 and HR4  (all under /api/v1)
| Send your key as   Authorization: Bearer <key>   or   X-Integration-Key: <key>
| The Super Admin creates each system's key in HIMS > File Exchange > Connections.
*/
Route::prefix('v1')->middleware('throttle:120,1')->group(function () {
    // Public: open job postings (same list as the careers page).
    Route::get('/vacancies', [VacancyController::class, 'index']);

    Route::middleware('integration.key')->group(function () {
        Route::get('/ping', PingController::class);

        // Files: HR2/HR3/HR4 send files to HIMS, and pick up files HIMS sent them.
        Route::get('/files', [FileController::class, 'index']);
        Route::post('/files', [FileController::class, 'store']);
        Route::get('/files/{id}', [FileController::class, 'download'])->whereNumber('id');

        // Requests: HR2/HR3/HR4 send requests; an HR Officer approves or rejects them in HIMS.
        Route::get('/requests', [RequestController::class, 'index']);
        Route::post('/requests', [RequestController::class, 'store']);
        Route::get('/requests/{reference}', [RequestController::class, 'show']);
    });

    // HR3 only: hire orders (become Pending vacancies an HR Officer publishes).
    Route::middleware('integration.key:HR3')->group(function () {
        Route::post('/hire-orders', [HireOrderController::class, 'store']);
        Route::get('/hire-orders/{reference}', [HireOrderController::class, 'show']);
    });
});
