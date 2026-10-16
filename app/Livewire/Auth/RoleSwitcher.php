<?php

namespace App\Livewire\Auth;

use App\Livewire\Concerns\WithFeedback;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class RoleSwitcher extends Component
{
    use WithFeedback;

    public bool $onlySwitchables = false;

    public ?string $activeRoleName = null;

    public array $inactive = [];

    public array $all = [];

    public array $roles = [];

    public function mount(bool $onlySwitchables = false): void
    {
        $this->onlySwitchables = $onlySwitchables;
        $this->refreshRoles();
    }

    #[On('refresh-role-switcher')]
    public function refreshRoles(): void
    {
        $user = Auth::user();
        if (! $user) {
            $this->activeRoleName = null;
            $this->inactive = $this->all = $this->roles = [];

            return;
        }

        $rows = $user->roles()
            ->select('roles.id', 'roles.name')
            ->withPivot('is_active')
            ->get();

        $active = $rows->firstWhere('pivot.is_active', true);
        $this->activeRoleName = $active?->name;

        $this->inactive = $rows
            ->where('pivot.is_active', false)
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])
            ->values()
            ->all();

        $this->all = $rows
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])
            ->values()
            ->all();

        $pool = $this->onlySwitchables ? $this->inactive : $this->all;
        $labels = array_map(fn ($r) => $r['name'], $pool);

        if ($this->activeRoleName) {
            $labels = array_values(array_filter($labels, function ($label) {
                return strcasecmp($label, (string) $this->activeRoleName) !== 0;
            }));
        }
        $this->roles = $labels;
    }

    public function switchTo(string|int $target): void
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $roles = $user->roles()->select('roles.id', 'roles.name')->withPivot('is_active')->get();

        if (is_numeric($target)) {
            $role = $roles->firstWhere('id', (int) $target);
        } else {
            $needle = Str::lower(trim((string) $target));
            $role = $roles->first(function ($r) use ($needle) {
                return Str::lower((string) $r->name) === $needle;
            });
        }

        abort_unless($role, 403);

        DB::transaction(function () use ($user, $role) {
            $ids = $user->roles()->pluck('roles.id')->all();
            if ($ids) {
                $user->roles()->updateExistingPivot($ids, ['is_active' => false]);
            }
            $user->roles()->updateExistingPivot($role->id, ['is_active' => true]);
        });

        session(['active_role_id' => (int) $role->id]);

        $this->dispatch('role-switched', role: (string) $role->name);
        $this->toast(__('general.role-switched'));

        $this->refreshRoles();

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.role-switcher', [
            'roles' => $this->roles,
        ]);
    }
}
