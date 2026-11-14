<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vacancy;
use Illuminate\Support\Facades\Schema;

/** GET /api/v1/vacancies — open job postings (public, same list as the careers page). */
class VacancyController extends Controller
{
    public function index()
    {
        $columns = ['id', 'title', 'department', 'employment_type as type', 'slots', 'description', 'deadline'];
        foreach (['qualifications', 'linkedin_url', 'jobstreet_url'] as $optional) {
            if (Schema::hasColumn('vacancies', $optional)) {
                $columns[] = $optional;
            }
        }
        $query = Vacancy::query()->where('status', 'Open')
            ->where(fn ($q) => $q->whereNull('deadline')->orWhereDate('deadline', '>=', now(config('hims.timezone'))->toDateString()))
            ->orderBy('id');
        if (Schema::hasColumn('vacancies', 'archived')) {
            $query->where('archived', 0);
        }

        return response()->json(['records' => $query->get($columns)]);
    }
}
