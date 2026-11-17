<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The small card shown when the pointer rests on a person's name (<x-person>,
 * resources/js/person-link.js). Directory tier only (access-policy.md §4.2),
 * the same check as the profile drawer: never pay, documents or a private
 * contact. Someone the viewer may not see answers 404, like one who does not exist.
 */
class EmployeeCardController extends Controller
{
    public function __invoke(Employee $employee): JsonResponse
    {
        Gate::authorize('viewDirectory', $employee);

        $position = Contract::query()
            ->activeNow()
            ->where('employee_id', $employee->id)
            ->with(['position.department', 'position.center'])
            ->latest()
            ->first()
            ?->position;

        return response()->json([
            'name' => (string) $employee->name,
            'avatar' => $employee->avatar_url,
            'title' => $position?->title,
            'details' => array_values(array_filter([
                $position?->department?->name,
                $position?->center?->name,
                $employee->city?->name,
            ])),
        ]);
    }
}
