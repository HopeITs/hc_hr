<?php

namespace App\Http\Controllers;

use App\Models\Center;
use App\Models\City;
use App\Models\Employee;
use App\Models\Program;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

/**
 * The public page that presents the recruitment module (/{locale}/recruitment-system).
 * Signed-out visitors see it, so it shows only organisation-wide totals, never a
 * person; the totals are cached for an hour to keep the page off the database.
 */
class RecruitmentSystemController extends Controller
{
    public function __invoke(): View
    {
        $stats = Cache::remember('recruitment-system:stats', now()->addHour(), fn () => [
            'cities' => City::count(),
            'centers' => Center::count(),
            'programs' => Program::count(),
            'employees' => Employee::completeRecords()->count(),
        ]);

        return view('marketing.recruitment-system', ['stats' => $stats]);
    }
}
