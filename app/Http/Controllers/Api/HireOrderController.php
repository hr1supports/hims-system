<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vacancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * HR3 only.
 * POST /api/v1/hire-orders            send a hire order (becomes a Pending vacancy in HIMS)
 * GET  /api/v1/hire-orders/{reference} check it: Pending (waiting), Open (published), Closed (declined)
 */
class HireOrderController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:150'],
            'department' => ['required', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(Vacancy::TYPES)],
            'slots' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:5000'],
            'qualifications' => ['nullable', 'string', 'max:5000'],
            'requested_by' => ['nullable', 'string', 'max:150'],
        ]);

        if (Vacancy::query()->where('source', 'HR3')->where('source_ref', $data['reference'])->exists()) {
            return response()->json(['error' => 'This hire order was already received.'], 409);
        }

        $row = [
            'title' => trim($data['title']),
            'department' => trim($data['department']),
            'employment_type' => $data['type'] ?? 'Full-time',
            'slots' => $data['slots'] ?? 1,
            'deadline' => $data['deadline'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => 'Pending',
            'source' => 'HR3',
            'source_ref' => $data['reference'],
            'requested_by' => $data['requested_by'] ?? null,
        ];
        if (! empty($data['qualifications']) && Schema::hasColumn('vacancies', 'qualifications')) {
            $lines = preg_split('/\r\n|\r|\n/', $data['qualifications']);
            $lines = array_slice(array_values(array_filter(array_map(fn ($l) => mb_substr(trim(ltrim(trim($l), '-*• ')), 0, 200), $lines))), 0, 30);
            $row['qualifications'] = implode("\n", $lines);
        }
        $vacancy = Vacancy::query()->create($row);

        return response()->json(['id' => $vacancy->id, 'status' => 'Pending', 'message' => 'Hire order received. HR1 will review it.'], 201);
    }

    public function show(string $reference)
    {
        $vacancy = Vacancy::query()->where('source', 'HR3')->where('source_ref', $reference)
            ->first(['id', 'source_ref as reference', 'title', 'department', 'slots', 'status', 'deadline']);
        if (! $vacancy) {
            return response()->json(['error' => 'Hire order not found.'], 404);
        }

        return response()->json(['record' => $vacancy]);
    }
}
