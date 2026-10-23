<?php

namespace App\Models;

use Closure;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasLocalePreference
{
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    protected $fillable = ['email', 'password', 'employee_id'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * The language mail to this user is rendered in: the interface language
     * they last worked in (RememberUserLocale). Null (not seen since the
     * column was added) falls back to the current locale, which outside a
     * request is the app default.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale ?: null;
    }

    /**
     * Answers derived from this user's roles and grants, kept for the life of
     * this instance (one request). A profile page asks the same policy
     * questions hundreds of times; each used to go back to the database.
     */
    private array $requestMemo = [];

    /** The registrar generation the memo and loaded roles/permissions belong to. */
    private ?int $memoGeneration = null;

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** @template T @param \Closure(): T $resolve @return T */
    public function remember(string $key, Closure $resolve): mixed
    {
        $this->dropStaleAccess();

        return array_key_exists($key, $this->requestMemo)
            ? $this->requestMemo[$key]
            : $this->requestMemo[$key] = $resolve();
    }

    public function forgetRemembered(): void
    {
        $this->requestMemo = [];
    }

    /**
     * Include the application's active-role flag whenever roles are eager loaded.
     *
     * Spatie's default relationship only exposes its own pivot keys. Without
     * this override, activeRole() cannot inspect is_active on a loaded roles
     * collection and may incorrectly fall back to the first assigned role.
     */
    public function roles(): BelongsToMany
    {
        $registrar = app(PermissionRegistrar::class);
        $relation = $this->morphToMany(
            config('permission.models.role'),
            'model',
            config('permission.table_names.model_has_roles'),
            config('permission.column_names.model_morph_key'),
            $registrar->pivotRole,
        )->withPivot('is_active');

        if (! $registrar->teams) {
            return $relation;
        }

        $teamsKey = $registrar->teamsKey;
        $relation->withPivot($teamsKey);
        $teamField = config('permission.table_names.roles').'.'.$teamsKey;

        return $relation->wherePivot($teamsKey, getPermissionsTeamId())
            ->where(fn (Builder $query): Builder => $query
                ->whereNull($teamField)
                ->orWhere($teamField, getPermissionsTeamId()));
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class, 'user_id');
    }

    public function accessibleFolders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class);
    }

    /**
     * Users who should receive org-wide database notifications.
     * Scoped to active employees; optionally limited to a single city.
     */
    public static function broadcastRecipientsQuery(?int $cityId = null): Builder
    {
        return static::query()
            ->whereNotNull('employee_id')
            ->whereHas('profile', function ($query) use ($cityId) {
                $query->where('is_active', true);

                if ($cityId !== null) {
                    $query->where('city_id', $cityId);
                }
            });
    }

    /**
     * Queue database notifications in chunks instead of loading every user at once.
     */
    public static function sendBroadcast(Notification $notification, ?int $cityId = null): void
    {
        static::broadcastRecipientsQuery($cityId)->chunkById(100, function ($users) use ($notification) {
            \Illuminate\Support\Facades\Notification::send($users, $notification);
        });
    }

    public function activeRole(): ?Role
    {
        // Loaded once; setActiveRole() and Spatie's assign/remove keep the
        // loaded relation current, so this never outlives a role change.
        $this->dropStaleAccess();

        return $this->loadMissing('roles')->roles
            ->firstWhere(fn ($r) => (bool) ($r->pivot->is_active ?? false));
    }

    public function myRole(): ?string
    {
        return $this->activeRole()?->name
            ?? $this->roles->first()?->name
            ?? null;
    }

    public function activeRoleSlug(): ?string
    {
        $name = $this->myRole();

        return $name ? Str::slug($name) : null;
    }

    public function activeRoleIs(string ...$roles): bool
    {
        $activeRole = strtoupper(trim($this->myRole() ?? ''));

        foreach ($roles as $r) {
            $normalized = strtoupper(trim($r));

            if ($normalized === 'HR' && Str::startsWith($activeRole, 'HR-')) {
                return true;
            }

            if ($activeRole === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * True only when the role the user is *currently operating under* is a
     * super-admin role.
     *
     * Spatie's hasRole('super-admin') answers "has this user ever been granted
     * super-admin", which is the wrong question in an application where users
     * hold several roles and explicitly switch between them. Someone who once
     * held super-admin and is now signed in as HR-ALP would pass that check and
     * receive nationwide archive access. Always use this instead.
     */
    public function isActiveAdministrator(): bool
    {
        return $this->activeRoleIs('super-admin');
    }

    /**
     * May the role this user is currently operating under see pay figures?
     *
     * Two separate refusals, and both matter:
     *
     * - config('salary.blind_roles') names the roles that are refused outright
     *   however many permissions they hold. super-admin is there because it is
     *   the developers' role: it exists to debug the system, including on
     *   copies of production data, and confidentiality of pay does not survive
     *   a role that both sees everything and is handed out to whoever is
     *   working on the code this month.
     *
     * - Everyone else has to be HR. Pay is HR's file; general-manager and
     *   manager can edit an employee without it, and an employee's own salary
     *   reaches them on their contract, not through this application.
     *
     * Answers only the role question. City scope is the caller's to add, the
     * same way it already does for every other HR-only view.
     */
    public function canViewSalary(): bool
    {
        if ($this->activeRoleIs(...config('salary.blind_roles', []))) {
            return false;
        }

        return $this->activeRoleIs('HR');
    }

    /**
     * Check direct grants plus the currently active role only.
     *
     * Spatie's default check combines every assigned role, which is unsafe for
     * this application because users explicitly switch between active roles.
     */
    public function hasActivePermission(string $permission): bool
    {
        if ($this->activeRoleIs(...config('access-control.administrator_roles', []))) {
            return true;
        }

        return $this->hasExplicitPermission($permission);
    }

    /**
     * The same check without the administrator bypass: a direct grant or the
     * active role's own permission, and nothing else.
     *
     * For acts that must be granted on purpose to whoever performs them —
     * exporting candidate data, erasing it — where "administrator" cannot mean
     * "may do this". Salary is the precedent (canViewSalary refuses
     * super-admin by name); this generalises it to a permission.
     */
    public function hasExplicitPermission(string $permission): bool
    {
        $this->dropStaleAccess();

        if ($this->loadMissing('permissions')->permissions->contains('name', $permission)) {
            return true;
        }

        return $this->activeRole()?->hasPermissionTo($permission) ?? false;
    }

    /**
     * The cities this user's active role is scoped to, or null when the role
     * sees every city: super-admin, general-manager, and HR-SYR (headquarters).
     *
     * A regional role may cover several cities (HR-HMS covers Homs and Homs
     * Rural, see City::slugsForHrRole). An empty array means a regional role
     * whose city does not exist, which sees nothing rather than everything.
     *
     * @return array<int, int>|null
     */
    public function activeCityIds(): ?array
    {
        $roleName = strtoupper(trim($this->myRole() ?? ''));

        if (! Str::startsWith($roleName, 'HR-') || $roleName === 'HR-SYR') {
            return null;
        }

        return City::query()
            ->whereIn('slug', City::slugsForHrRole($roleName))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The role's own city — the one named after "HR-" — or null when the role
     * sees every city. Recruitment routing needs the single home governorate;
     * for access checks use activeCityIds() / canAccessCity(), which also
     * cover the extra cities a regional role administers.
     */
    public function activeCityId(): ?int
    {
        $roleName = strtoupper(trim($this->myRole() ?? ''));

        if (! Str::startsWith($roleName, 'HR-') || $roleName === 'HR-SYR') {
            return null;
        }

        $slug = Str::after($roleName, 'HR-');

        return $this->remember('city-id:'.$slug, fn (): ?int => City::where('slug', $slug)->value('id'));
    }

    /** May the active role read and write records belonging to $cityId? */
    public function canAccessCity(?int $cityId): bool
    {
        $cityIds = $this->activeCityIds();

        return $cityIds === null || in_array((int) $cityId, $cityIds, true);
    }

    public function inactiveRoles()
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->filter(fn ($r) => empty($r->pivot->is_active));
        }

        return $this->roles()->wherePivot('is_active', false)->get();
    }

    public function hasMultipleRoles(): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->count() > 1;
        }

        return $this->roles()->limit(2)->count() > 1;
    }

    public function setActiveRole(int $roleId): void
    {
        DB::transaction(function () use ($roleId) {
            if (! $this->roles()->where('roles.id', $roleId)->exists()) {
                abort(403, 'Role does not belong to this user.');
            }

            $ids = $this->roles()->pluck('roles.id')->all();

            if (! empty($ids)) {
                $this->roles()->updateExistingPivot($ids, ['is_active' => false]);
            }

            $this->roles()->updateExistingPivot($roleId, ['is_active' => true]);
            $this->forgetRemembered();

            if ($this->relationLoaded('roles')) {
                $this->roles->each(function ($r) use ($roleId) {
                    $r->pivot->is_active = (int) ($r->id === $roleId);
                });
            }
        });

    }

    public function initials(): string
    {
        $display = $this->profile?->name ?: '';

        return Str::of($display)
            ->trim()
            ->explode(' ')
            ->take(2)
            ->map(fn ($w) => Str::substr($w, 0, 1))
            ->implode('');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user')
            ->logOnly([
                'email',
                'employee_id',
                'email_verified_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * A grant or revocation anywhere clears Spatie's cache; from then on the
     * memo and the loaded roles and permissions may describe the old state.
     */
    private function dropStaleAccess(): void
    {
        $generation = \App\Support\Permissions\PermissionCacheGeneration::current();

        if ($this->memoGeneration !== $generation) {
            $this->memoGeneration = $generation;
            $this->requestMemo = [];
            $this->unsetRelation('roles');
            $this->unsetRelation('permissions');
        }
    }
}
