<?php

namespace App\Livewire\Dashboard;

use App\Models\Badge;
use App\Models\Center;
use App\Models\Checklist;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\News;
use App\Models\Position;
use App\Models\Program;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Component;

class Index extends Component
{
    public $employee;

    public $contract;

    public $positionTitle;

    public $departmentName;

    public $joinedOn;

    public $workEmail;

    public function getTodayEventsProperty()
    {
        $today = now()->timezone(config('app.timezone'))->startOfDay();
        $todayDate = $today->toDateString();
        $locale = app()->getLocale();
        $titleCol = $locale === 'ar' ? 'title_ar' : 'title_en';
        $deptCol = $locale === 'ar' ? 'name_ar' : 'name_en';
        $employeeId = $this->employee->id ?? null;
        $cityId = $this->employee->city_id ?? null;
        $centerId = $this->contract->position->center_id ?? null;
        $programId = $this->contract->position->program_id ?? null;

        $birthdays = Employee::query()
            ->whereNotNull('dob')
            ->whereDay('dob', $today->day)
            ->whereMonth('dob', $today->month)
            ->with(['media', 'contracts.position.department'])
            ->get()
            ->map(function ($emp) use ($titleCol, $deptCol, $today) {
                $contract = Contract::with(['position.department'])
                    ->where('employee_id', $emp->id)
                    ->orderByDesc('start_date')
                    ->first();

                $emp->positionTitle = $contract?->position?->$titleCol ?? null;
                $emp->departmentName = $contract?->position?->department?->$deptCol ?? null;
                $emp->event_type = 'birthday';
                $emp->event_title = 'Happy Birthday!';
                $emp->event_description = trim(($emp->positionTitle ? $emp->positionTitle.' · ' : '').($emp->departmentName ?? ''));
                $emp->event_timestamp = $today;

                return $emp;
            });

        $newsItems = News::query()
            ->where(function ($q) use ($todayDate) {
                $q->whereDate('event_date', $todayDate)->orWhereDate('published_at', $todayDate);
            })
            ->where(function ($q) use ($cityId, $centerId, $programId, $employeeId) {
                $q->whereNull('newsable_type')
                    ->orWhere(function ($q2) use ($employeeId) {
                        $q2->where('newsable_type', Employee::class)->where('newsable_id', $employeeId);
                    })
                    ->orWhere(function ($q2) use ($cityId) {
                        $q2->where('newsable_type', City::class)->where('newsable_id', $cityId);
                    })
                    ->orWhere(function ($q2) use ($centerId) {
                        $q2->where('newsable_type', Center::class)->where('newsable_id', $centerId);
                    })
                    ->orWhere(function ($q2) use ($programId) {
                        $q2->where('newsable_type', Program::class)->where('newsable_id', $programId);
                    });
            })
            ->where(function ($q) {
                $q->whereNull('display_until')->orWhere('display_until', '>=', now()->startOfDay());
            })
            ->with('media')
            ->get()
            ->map(function ($news) {
                $timestamp = $news->event_date ?? $news->published_at;
                if (! $timestamp instanceof Carbon) {
                    $timestamp = Carbon::parse($timestamp);
                }

                $news->event_type = 'news';
                $news->event_title = $news->title;
                $news->event_description = $news->description;
                $news->event_timestamp = $timestamp;
                $news->image_url = $news->getFirstMediaUrl('news-image') ?: '/images/news-default.png';

                return $news;
            });

        return $birthdays->merge($newsItems)->sortByDesc('event_timestamp')->values();
    }

    public function getUpcomingEventsProperty()
    {
        $start = now()->timezone(config('app.timezone'))->addDay()->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();

        $employeeId = $this->employee->id ?? null;
        $cityId = $this->employee->city_id ?? null;
        $centerId = $this->contract->position->center_id ?? null;
        $programId = $this->contract->position->program_id ?? null;

        return News::query()
            ->whereBetween('event_date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($q) use ($cityId, $centerId, $programId, $employeeId) {
                $q->whereNull('newsable_type')
                    ->orWhere(function ($q2) use ($employeeId) {
                        $q2->where('newsable_type', Employee::class)->where('newsable_id', $employeeId);
                    })
                    ->orWhere(function ($q2) use ($cityId) {
                        $q2->where('newsable_type', City::class)->where('newsable_id', $cityId);
                    })
                    ->orWhere(function ($q2) use ($centerId) {
                        $q2->where('newsable_type', Center::class)->where('newsable_id', $centerId);
                    })
                    ->orWhere(function ($q2) use ($programId) {
                        $q2->where('newsable_type', Program::class)->where('newsable_id', $programId);
                    });
            })
            ->where(function ($q) {
                $q->whereNull('display_until')->orWhere('display_until', '>=', now()->startOfDay());
            })
            ->get()
            ->map(function ($news) {
                $timestamp = $news->event_date;
                $daysLeft = now()->diffInDays($timestamp, false);

                $news->event_title = $news->title;
                $news->event_description = $news->description;
                $news->event_timestamp = $timestamp;
                $news->image_url = $news->getFirstMediaUrl('news-image') ?: '/images/news-default.png';
                $news->daysLeftText = match ($daysLeft) {
                    1 => 'Tomorrow',
                    default => "In {$daysLeft} days",
                };

                return $news;
            })
            ->sortBy('event_timestamp')
            ->values();
    }

    public function getUpcomingBirthdaysProperty()
    {
        $today = now()->timezone(config('app.timezone'))->startOfDay();
        $start = $today->copy()->addDay();
        $end = $today->copy()->addDays(7);
        $locale = app()->getLocale();
        $titleCol = $locale === 'ar' ? 'title_ar' : 'title_en';
        $deptCol = $locale === 'ar' ? 'name_ar' : 'name_en';

        return Employee::query()
            ->whereNotNull('dob')
            ->get()
            ->filter(function ($emp) use ($start, $end) {
                $dob = Carbon::parse($emp->dob)->setYear(now()->year);
                if ($dob->lt($start)) {
                    $dob->addYear();
                }

                return $dob->isBetween($start, $end, true);
            })
            ->map(function ($emp) use ($titleCol, $deptCol) {
                $contract = Contract::with(['position.department'])
                    ->where('employee_id', $emp->id)
                    ->orderByDesc('start_date')
                    ->first();

                $emp->positionTitle = $contract?->position?->$titleCol ?? null;
                $emp->departmentName = $contract?->position?->department?->$deptCol ?? null;
                $dob = Carbon::parse($emp->dob)->setYear(now()->year);
                if ($dob->lt(now())) {
                    $dob->addYear();
                }
                $daysLeft = now()->diffInDays($dob, false);
                $emp->upcomingDob = $dob->format('d M');
                $emp->daysLeftText = match ($daysLeft) {
                    0 => 'Today 🎂',
                    1 => 'Tomorrow',
                    default => "In {$daysLeft} days",
                };

                return $emp;
            })
            ->sortBy(fn ($emp) => Carbon::parse($emp->dob)->setYear(now()->year)->dayOfYear)
            ->values();
    }

    public function getDeliveredItemsProperty()
    {
        if (! $this->employee) {
            return collect();
        }

        return Checklist::query()
            ->with(['item', 'delivered'])
            ->where('employee_id', $this->employee->id)
            ->where('delivered_status', 'delivered')
            ->orderByDesc('delivered_at')
            ->get();
    }

    public function getMyBadgesProperty()
    {
        if (! $this->employee) {
            return collect();
        }

        return Badge::query()->with('employee')->where('employee_id', $this->employee->id)->orderByDesc('date')->get();
    }

    public function getPendingRequestsProperty()
    {
        if (! $this->employee) {
            return collect();
        }

        return Checklist::with(['item', 'employee'])
            ->where('delivered_id', $this->employee->id)
            ->where('delivered_status', 'pending')
            ->latest()
            ->get()
            ->map(
                fn ($checklist) => [
                    'id' => $checklist->id,
                    'item' => $checklist->item?->name ?? '—',
                    'employee_name' => $checklist->employee?->name ?? '—',
                    'request_date' => $checklist->created_at->format('Y-m-d'),
                    'notes' => $checklist->notes ?? null,
                ],
            );
    }

    public function getTeamMembersProperty()
    {
        if (! $this->contract?->position) {
            return collect();
        }

        $locale = app()->getLocale();
        $titleCol = $locale === 'ar' ? 'title_ar' : 'title_en';
        $deptCol = $locale === 'ar' ? 'name_ar' : 'name_en';

        $position = $this->contract->position;
        $cityId = $position->city_id;
        $programId = $position->program_id;

        $members = collect();

        $ceo = Employee::query()
            ->where('id', 1)
            ->with(['media', 'contracts.position.jobLevel'])
            ->first();

        if ($ceo) {
            $contract = $ceo->latestActiveContract();
            $ceo->positionTitle = 'Chief Executive Officer';
            $members->push($ceo);
        }

        $directManager = null;
        if ($position?->direct_manager_id) {
            $managerPosition = Position::with(['jobLevel', 'activeContracts.employee.media'])->find($position->direct_manager_id);

            if ($managerPosition) {
                $activeContract = $managerPosition
                    ->activeContracts()
                    ->with(['employee.contracts.position.jobLevel'])
                    ->latest('start_date')
                    ->first();

                $directManager = $activeContract?->employee;

                if ($directManager) {
                    $directManager->positionTitle = $activeContract?->position?->$titleCol ?? '—';
                }
            }
        }

        if ($directManager && ! $members->contains('id', $directManager->id)) {
            $members->push($directManager);
        }

        $nameCol = "name_{$locale}";

        $excludeIds = collect([$this->employee?->id, $ceo?->id, $directManager?->id])
            ->filter()
            ->unique()
            ->all();

        // Every employee in the organization: head office (Syria-level
        // positions) first, then the viewer's own city, then the other cities
        // one at a time in city-code order (Aleppo, Homs, Damascus, ...). Each
        // group is ordered by job grade.
        $ownCityId = $this->employee?->city_id;
        $headOfficeCityId = City::query()->where('slug', City::NATIONAL_SLUG)->value('id');

        $allEmployees = Employee::query()
            ->where('is_active', true)
            ->whereHas('contracts')
            ->whereNotIn('id', $excludeIds)
            ->whereNotNull('username')
            ->with(['media', 'account', 'city', 'contracts.position.jobLevel'])
            ->get()
            ->map(function ($emp) use ($nameCol, $titleCol, $ownCityId, $headOfficeCityId) {
                $contract = $emp->contracts->filter(fn ($c) => $c->is_active || (! $c->terminated_at && $c->start_date <= now() && $c->end_date >= now()))->sortByDesc('start_date')->first() ?? $emp->latestActiveContract();

                $emp->displayName = $emp->$nameCol ?? $emp->name_en;
                $emp->positionTitle = $contract?->position?->$titleCol ?? ($contract?->position?->title_en ?? '—');
                $emp->jobLevelGrade = $contract?->position?->jobLevel?->grade ?? 999;
                $emp->groupRank = match (true) {
                    $headOfficeCityId && $contract?->position?->city_id === $headOfficeCityId => 0,
                    $emp->city_id === $ownCityId => 1,
                    default => 2,
                };

                return $emp;
            })
            ->sortBy([
                fn ($a, $b) => $a->groupRank <=> $b->groupRank,
                fn ($a, $b) => $a->groupRank === 2 ? (string) $a->city?->code <=> (string) $b->city?->code : 0,
                fn ($a, $b) => $a->jobLevelGrade <=> $b->jobLevelGrade,
            ])
            ->values();

        // The card links each member's work email (account login) — load it in one query.
        return (new EloquentCollection($members->merge($allEmployees)->unique('id')->values()))
            ->loadMissing('account');
    }

    public function mount(): void
    {
        $locale = app()->getLocale();
        $titleCol = $locale === 'ar' ? 'title_ar' : 'title_en';
        $deptCol = $locale === 'ar' ? 'name_ar' : 'name_en';

        $user = auth()->user();
        if (! $user) {
            abort(403, 'Unauthorized');
        }

        $this->employee = $user
            ->profile()
            ->with(['media', 'city'])
            ->first();

        if ($this->employee) {
            $this->contract = Contract::with(['position.department', 'position.center', 'position.program'])
                ->where('employee_id', $this->employee->id)
                ->orderByDesc('start_date')
                ->first();

            $this->positionTitle = $this->contract?->position?->$titleCol ?? '—';
            $this->departmentName = $this->contract?->position?->department?->$deptCol ?? '—';
            $this->joinedOn = $this->contract?->start_date ? date('d M Y', strtotime($this->contract->start_date)) : '—';
            $this->workEmail = $user->email ?? '—';
        } else {
            $this->positionTitle = $this->departmentName = $this->joinedOn = $this->workEmail = '—';
        }
    }

    public function render()
    {

        return view('livewire.dashboard.index', [
            'employee' => $this->employee,
            'hasLeaves' => $this->employee?->availableLeaves()?->count() > 0,
            'contract' => $this->contract,
            'positionTitle' => $this->positionTitle,
            'departmentName' => $this->departmentName,
            'joinedOn' => $this->joinedOn,
            'workEmail' => $this->workEmail,
            'todayEvents' => $this->todayEvents,
            'upcomingEvents' => $this->getUpcomingEventsProperty(),
            'deliveredItems' => $this->deliveredItems,
            'myBadges' => $this->myBadges,
            'pendingRequests' => $this->pendingRequests,
            'teamMembers' => $this->teamMembers,
        ]);
    }
}
