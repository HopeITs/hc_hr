<?php

namespace App\Providers;

use App\Http\Middleware\ActiveLivewirePermissionMiddleware;
use App\Models\Badge;
use App\Models\Center;
use App\Models\Contract;
use App\Models\DefaultDeliverable;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Observers\ContractObserver;
use App\Observers\EmployeeObserver;
use App\Policies\BadgePolicy;
use App\Policies\CenterPolicy;
use App\Policies\ContractPolicy;
use App\Policies\DefaultDeliverablePolicy;
use App\Policies\EmployeePolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\PositionPolicy;
use App\Services\ActivityLogger;
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
        Gate::policy(DefaultDeliverable::class, DefaultDeliverablePolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
        Gate::policy(Position::class, PositionPolicy::class);

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
