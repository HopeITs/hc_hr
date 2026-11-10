<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\HasDashboardLayout;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\News;
use App\Models\Program;
use App\Models\User;
use App\Services\WorkInbox;
use App\Support\Dashboard\DashboardLayout;
use Carbon\Carbon;
use Livewire\Component;
use Throwable;

/**
 * The dashboard: a home screen of tiles the person arranges (DashboardLayout,
 * resources/js/dashboard/home-screen.js). This component draws the grid and
 * nothing else: each widget tile is its own lazy Livewire component
 * (DashboardLayout::COMPONENTS) that loads its data when it comes into view
 * and shows a failure inside itself. A hidden tile is not drawn at all, so
 * its data is never read.
 */
class Index extends Component
{
    use HandlesLoadFailure;
    use HasDashboardLayout;

    public $employee;

    public $contract;

    public $positionTitle;

    public $departmentName;

    public $joinedOn;

    public $workEmail;

    /**
     * Sections whose load failed during THIS request (card key => true). Not a
     * public property: it is recomputed on every render, and a retry simply re-renders.
     *
     * @var array<string, bool>
     */
    protected array $failedSections = [];

    /** @var array<string, mixed> Per-request memo so a card that reads $this->x after render() does not query twice. */
    protected array $memo = [];

    /** ?customise=1 (the command palette's "Customise dashboard"): the page opens in edit mode. */
    protected bool $customise = false;

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
                $emp->event_title = __('dashboard.happy-birthday');
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

    public function getUpcomingBirthdaysProperty()
    {
        $today = now()->timezone(config('app.timezone'))->startOfDay();
        $start = $today->copy()->addDay();
        $end = $today->copy()->addDays(7);
        $locale = app()->getLocale();
        $titleCol = $locale === 'ar' ? 'title_ar' : 'title_en';
        $deptCol = $locale === 'ar' ? 'name_ar' : 'name_en';

        return Employee::query()
            ->where('is_active', true)
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
                $emp->upcomingDob = $dob->copy()->locale(app()->getLocale())->translatedFormat('d M');
                $emp->daysLeftText = trans_choice('dashboard.in-days', (int) $daysLeft).((int) $daysLeft === 0 ? ' 🎂' : '');

                return $emp;
            })
            ->sortBy(fn ($emp) => Carbon::parse($emp->dob)->setYear(now()->year)->dayOfYear)
            ->values();
    }

    public function mount(): void
    {
        $this->customise = request()->boolean('customise');

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
            $this->joinedOn = $this->contract?->start_date
                ? Carbon::parse($this->contract->start_date)->locale(app()->getLocale())->translatedFormat('d M Y')
                : '—';
            $this->workEmail = $user->email ?? '—';
        } else {
            $this->positionTitle = $this->departmentName = $this->joinedOn = $this->workEmail = '—';
        }
    }

    public function render()
    {
        $user = auth()->user();
        $tiles = app(DashboardLayout::class);
        $layout = $this->dashboardLayout($tiles);
        $shown = array_column(array_filter($layout, fn (array $item): bool => ! $item['hidden']), 'key');

        // Kept in the layout but not drawn, nor offered in Add: a widget that has
        // no component yet, and "my leaves" for someone without a leave balance
        // (its card reads one; today's dashboard left it out the same way).
        $absent = array_values(array_filter(
            array_column($layout, 'key'),
            fn (string $key): bool => $tiles->isWidget($key) && $tiles->component($key) === null,
        ));

        if (in_array('my-leaves', $shown, true)
            && ! $this->section('leaves', fn () => $this->employee?->availableLeaves()?->count() > 0, false)
            && ! isset($this->failedSections['leaves'])) {
            $absent[] = 'my-leaves';
        }

        // The unused todayEvents list (36 queries for 100 employees: N+1 per
        // birthday) used to be computed here and never rendered; the
        // news.today-events-card component loads its own data.
        return view('livewire.dashboard.index', [
            'layout' => $layout,
            'tiles' => $tiles,
            'apps' => $tiles->apps($user),
            'absent' => $absent,
            // The page each shown widget opens on double-click (only pages the role's menu has).
            'pages' => array_filter(array_combine($shown, array_map(fn (string $key): ?string => $tiles->page($user, $key), $shown))),
            'inboxCount' => in_array(DashboardLayout::APP_PREFIX.'nav.inbox', $shown, true) ? $this->inboxCount($user) : 0,
            'customise' => $this->customise,
            'failedSections' => $this->failedSections,
        ]);
    }

    /**
     * Run one card's loader once per request. A failure is reported, marked on
     * $failedSections (the view swaps that card for x-ui.load-error) and returns
     * the fallback, so a broken card never blanks or 500s the whole dashboard.
     */
    protected function section(string $key, callable $loader, mixed $fallback): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $result = $this->guardedLoad($loader, $fallback);

        if ($this->loadFailed) {
            $this->failedSections[$key] = true;
        }

        // guardedLoad() resets the flag on success; keep it true if any section failed.
        $this->loadFailed = $this->failedSections !== [];

        return $this->memo[$key] = $result;
    }

    /** The inbox shortcut's badge: the same scoped count as the sidebar's (WorkInbox). */
    private function inboxCount(User $user): int
    {
        try {
            return app(WorkInbox::class)->shared($user)['counts']['all'];
        } catch (Throwable $e) {
            // A badge must not take the dashboard down with it.
            report($e);

            return 0;
        }
    }
}
