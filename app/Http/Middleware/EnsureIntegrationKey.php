<?php

namespace App\Http\Middleware;

use App\Models\IntegrationKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets HR2, HR3 and HR4 call the HIMS API with their key.
 * Accepts "Authorization: Bearer <key>", "X-Integration-Key: <key>" or HR3's older "X-HR3-Key".
 * Usage: ->middleware('integration.key') or ->middleware('integration.key:HR3') for one system only.
 */
class EnsureIntegrationKey
{
    public function handle(Request $request, Closure $next, ?string $only = null): Response
    {
        $key = (string) ($request->header('X-Integration-Key') ?: ($request->bearerToken() ?: $request->header('X-HR3-Key')));
        $partner = IntegrationKey::partnerFor($key);

        if ($partner === null) {
            return response()->json([
                'error' => 'Invalid or missing integration key. Send it as "Authorization: Bearer <key>" or in the X-Integration-Key header.',
            ], 401);
        }
        if ($only !== null && $partner !== $only) {
            return response()->json(['error' => "This endpoint is only for {$only}."], 403);
        }

        $request->attributes->set('partner', $partner);

        return $next($request);
    }
}
