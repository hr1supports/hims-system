<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** GET /api/v1/ping — lets a partner system check its key works. */
class PingController extends Controller
{
    public function __invoke(Request $request)
    {
        return response()->json([
            'partner' => $request->attributes->get('partner'),
            'system' => 'HIMS (HR1)',
            'message' => 'Connected to HIMS.',
            'time' => now(config('hims.timezone'))->toIso8601String(),
        ]);
    }
}
