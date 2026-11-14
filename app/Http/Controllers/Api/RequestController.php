<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HR Requests from HR2 / HR3 / HR4 (shown on HIMS > HR Requests; an HR Officer decides).
 * GET  /api/v1/requests              your requests (?status=Pending|Approved|Rejected)
 * GET  /api/v1/requests/{reference}  one request, with the decision and the HR Officer's remark
 * POST /api/v1/requests              send a request
 */
class RequestController extends Controller
{
    private const COLUMNS = [
        'id', 'reference', 'request_type as type', 'title', 'employee_code as employeeCode', 'employee_name as employeeName',
        'details', 'hours', 'request_date as date', 'requested_by as requestedBy', 'status', 'remark',
        'decided_at as decidedAt', 'created_at as receivedAt',
    ];

    public function index(Request $request)
    {
        $partner = $request->attributes->get('partner');
        $request->validate(['status' => ['nullable', Rule::in(['Pending', 'Approved', 'Rejected'])]]);
        $query = IntegrationRequest::query()->where('partner', $partner)->orderByDesc('id')->limit(300);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json(['partner' => $partner, 'records' => $query->get(self::COLUMNS)]);
    }

    public function show(Request $request, string $reference)
    {
        $partner = $request->attributes->get('partner');
        $row = IntegrationRequest::query()->where('partner', $partner)->where('reference', $reference)->first(self::COLUMNS);
        if (! $row) {
            return response()->json(['error' => 'Request not found.'], 404);
        }

        return response()->json(['partner' => $partner, 'record' => $row]);
    }

    public function store(Request $request)
    {
        $partner = $request->attributes->get('partner');
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:60'],
            'type' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:150'],
            'employee_code' => ['nullable', 'string', 'max:40'],
            'employee_name' => ['nullable', 'string', 'max:150'],
            'details' => ['nullable', 'string', 'max:2000'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'requested_by' => ['nullable', 'string', 'max:150'],
        ]);
        if (IntegrationRequest::query()->where('partner', $partner)->where('created_at', '>', now()->subHour())->count() >= 300) {
            return response()->json(['error' => 'Too many requests sent in the last hour. Try again later.'], 429);
        }
        if (IntegrationRequest::query()->where('partner', $partner)->where('reference', $data['reference'])->exists()) {
            return response()->json(['error' => 'A request with this reference was already received.'], 409);
        }

        $row = IntegrationRequest::query()->create([
            'partner' => $partner,
            'reference' => $data['reference'],
            'request_type' => $data['type'],
            'title' => $data['title'],
            'employee_code' => $data['employee_code'] ?? null,
            'employee_name' => $data['employee_name'] ?? null,
            'details' => $data['details'] ?? null,
            'hours' => isset($data['hours']) ? round((float) $data['hours'], 2) : null,
            'request_date' => $data['date'] ?? null,
            'requested_by' => $data['requested_by'] ?? null,
            'status' => 'Pending',
        ]);

        return response()->json(['id' => $row->id, 'status' => 'Pending', 'message' => "Request received from {$partner}. An HR Officer will review it."], 201);
    }
}
