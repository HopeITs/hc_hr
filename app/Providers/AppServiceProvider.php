<?php

namespace App\Providers;

use App\Http\Middleware\ActiveLivewirePermissionMiddleware;
use App\Livewire\Features\SupportToasts;
use App\Models\Badge;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\DefaultDeliverable;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\User;
use App\Observers\ContractObserver;
use App\Observers\EmployeeObserver;
use App\Policies\BadgePolicy;
use App\Policies\CenterPolicy;
use App\Policies\ContractPolicy;
use App\Policies\DefaultDeliverablePolicy;
use App\Policies\DigitalIdPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\PositionPolicy;
use App\Services\ActivityLogger;
use App\Services\Assistant\ChatModel;
use App\Services\Assistant\ModelRouter;
use App\Services\SalaryCipher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityLogger::class, function ($app) {
            return new ActivityLogger;
        });

        // Singleton so the Encrypter - and the "this host cannot read the
        // figures" warning that goes with it - is built once per request
        // rather than once per contract row rendered.
        $this->app->singleton(SalaryCipher::class);

        // The assistant asks whichever configured AI provider answers first.
        $this->app->bind(ChatModel::class, ModelRouter::class);

        // Sends or flashes the toasts raised through WithFeedback::toast().
        // Registered while booting, not in boot(): Livewire attaches the hooks
        // it knows about during its own boot, so one added afterwards is never
        // called, and this one has to run ahead of Livewire's event hook.
        $this->app->booting(fn () => Livewire::componentHook(SupportToasts::class));
    }

    public function boot(): void
    {
        // Applies wherever Rules\Password::defaults() is used (registration,
        // reset, and the settings screen).
        //
        // 12 characters rather than 8: length is what defeats offline cracking,
        // and a passphrase is easier to remember than a short string with
        // symbol requirements bolted on. No character-class rules, which push
        // users toward predictable substitutions; uncompromised() checks the
        // password against the Have I Been Pwned k-anonymity range API instead,
        // which catches the passwords that are actually being sprayed.
        PasswordRule::defaults(fn () => $this->app->isProduction()
            ? PasswordRule::min(12)->max(255)->uncompromised()
            : PasswordRule::min(12)->max(255));

        Gate::policy(Badge::class, BadgePolicy::class);
        Gate::policy(Center::class, CenterPolicy::class);
        Gate::policy(Contract::class, ContractPolicy::class);
        // An amendment is part of its contract: the same policy decides both.
        Gate::policy(ContractAmendment::class, ContractPolicy::class);
        Gate::policy(DefaultDeliverable::class, DefaultDeliverablePolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
        Gate::policy(Position::class, PositionPolicy::class);

        // Public digital-ID card management (access-policy.md §9 A2D-Q5).
        Gate::define('digital-id.view', [DigitalIdPolicy::class, 'view']);
        Gate::define('digital-id.manage', [DigitalIdPolicy::class, 'manage']);
        Gate::define('digital-id.report-lost', [DigitalIdPolicy::class, 'reportLost']);

        // D3 / DEC-08: in-app feedback is read by the IT team only.
        Gate::define('feedback.read', fn (User $user): bool => $user->canReadFeedback());

        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/livewire/update', $handle)
                ->middleware(['web', ActiveLivewirePermissionMiddleware::class])
                ->prefix(LaravelLocalization::setLocale());
        });

        Blade::directive('glyph', function ($expr) {
            return '<?php echo app(\App\Support\Arabic::class)->glyphs('.$expr.'); ?>';
        });

        Builder::macro('whereLikeAny', function ($fields, string $search) {
            $fields = Arr::wrap($fields);
            if ($search === '' || $search === null) {
                return $this;
            }

            return $this->where(function ($q) use ($fields, $search) {
                foreach ($fields as $field) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            });
        });

        Builder::macro('search', function ($fields, $string) {
            if (! $string) {
                return $this;
            }

            $fields = Arr::wrap($fields);

            return $this->where(function ($query) use ($fields, $string) {
                foreach ($fields as $field) {
                    $query->orWhere($field, 'like', '%'.$string.'%');
                }

            });

        });
        Builder::macro('findBySlug', function ($fields, $string) {
            if (! $string) {
                return $this;
            }

            $fields = Arr::wrap($fields);

            return $this->where(function ($query) use ($fields, $string) {
                foreach ($fields as $field) {
                    $query->orWhere($field, 'like', '%'.$string.'%');
                }

            });

        });

        Blade::if('activerole', function (...$roles) {
            $user = auth()->user();

            return $user?->activeRoleIs(...$roles) ?? false;
        });

        Blade::if('notactiverole', function (...$roles) {
            return auth()->check() && ! auth()->user()->activeRoleIs(...$roles);
        });

        Blade::if('activepermission', function (string $permission) {
            return auth()->user()?->hasActivePermission($permission) ?? false;
        });

        Contract::observe(ContractObserver::class);
        Employee::observe(EmployeeObserver::class);
    }
}
