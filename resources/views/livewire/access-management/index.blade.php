<x-slot:title>
    {{ __('access.title') }}
</x-slot:title>

<div class="@container/access mx-auto w-full max-w-[1800px] space-y-[clamp(1rem,1.5vw,1.5rem)]"
    data-access-mode="{{ $canManageEmployees || $canManageRoles || $canManagePermissions ? 'managed' : 'read-only' }}">
    <header class="rounded-xl border border-neutral-200 border-s-4 border-s-[#be1f24] bg-white px-[clamp(1rem,2vw,1.5rem)] py-[clamp(1rem,1.5vw,1.25rem)] shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[clamp(1.35rem,2vw,1.75rem)] font-bold leading-tight text-neutral-900">
                    {{ __('access.title') }}
                </h1>
            </div>

            <nav class="flex min-h-11 items-center gap-1 rounded-lg border border-neutral-200 bg-neutral-50 p-1"
                aria-label="{{ __('access.title') }}">
                <button type="button" wire:click="showTab('employees')" @class([
                    'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]',
                    'bg-white text-[#be1f24] shadow-sm ring-1 ring-neutral-200' => $activeTab === 'employees',
                    'text-neutral-600 hover:bg-white hover:text-neutral-900' => $activeTab !== 'employees',
                ])>
                    {{ __('access.employees') }}
                </button>
                <button type="button" wire:click="showTab('roles')" @class([
                    'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]',
                    'bg-white text-[#be1f24] shadow-sm ring-1 ring-neutral-200' => $activeTab === 'roles',
                    'text-neutral-600 hover:bg-white hover:text-neutral-900' => $activeTab !== 'roles',
                ])>
                    {{ __('access.roles') }}
                </button>
            </nav>
        </div>
    </header>

    @if (session('access-success'))
        <div x-data="{ visible: true, dismissTimer: null }"
            x-init="dismissTimer = setTimeout(() => visible = false, 5000)"
            x-show="visible"
            x-transition.opacity.duration.200ms
            x-on:beforeunload.window="clearTimeout(dismissTimer)"
            data-testid="access-success"
            class="flex min-h-11 items-center rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-2.5 text-sm font-semibold text-neutral-800"
            role="status">
            {{ session('access-success') }}
        </div>
    @endif

    @if ($activeTab === 'employees')
        <div class="grid min-w-0 gap-[clamp(1rem,1.5vw,1.5rem)] xl:grid-cols-[21rem_minmax(0,1fr)]">
            <section class="@container/directory flex min-w-0 flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
                <div class="space-y-2.5 border-b border-neutral-200 p-3">
                    <label class="sr-only" for="access-employee-search">{{ __('access.search-employees') }}</label>
                    <div class="relative">
                        <input id="access-employee-search" type="search" wire:model.live.debounce.350ms="search"
                            placeholder="{{ __('access.search-employees') }}"
                            class="min-h-11 w-full rounded-lg border-neutral-200 bg-white pe-11 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-[#be1f24] focus:ring-[#be1f24]">

                        @if (filled($search))
                            <button type="button" wire:click="clearSearch"
                                aria-label="{{ __('access.clear-search') }}"
                                title="{{ __('access.clear-search') }}"
                                class="absolute end-1.5 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-md text-neutral-400 transition hover:bg-neutral-100 hover:text-[#be1f24] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#be1f24]">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                                    <path d="M6 6l12 12M18 6 6 18" />
                                </svg>
                            </button>
                        @endif
                    </div>

                    <div class="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2">
                        <select wire:model.live="roleFilter"
                            aria-label="{{ __('access.all-roles') }}"
                            class="min-h-11 min-w-0 rounded-lg border-neutral-200 bg-white text-sm text-neutral-700 focus:border-[#be1f24] focus:ring-[#be1f24]">
                            <option value="all">{{ __('access.all-roles') }}</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="statusFilter"
                            aria-label="{{ __('access.all-statuses') }}"
                            class="min-h-11 min-w-0 rounded-lg border-neutral-200 bg-white text-sm text-neutral-700 focus:border-[#be1f24] focus:ring-[#be1f24]">
                            <option value="all">{{ __('access.all-statuses') }}</option>
                            <option value="active">{{ __('access.active') }}</option>
                            <option value="inactive">{{ __('access.inactive') }}</option>
                        </select>
                    </div>
                </div>

                @php
                    $directoryIsFiltered = filled(trim($search))
                        || $roleFilter !== 'all'
                        || $statusFilter !== 'all';
                @endphp

                <div @class([
                    'divide-y divide-neutral-100 overscroll-contain pe-4',
                    'max-h-[min(62vh,44rem)] overflow-y-auto' => ! $selectedUser,
                    'max-h-[min(62vh,44rem)] overflow-y-auto xl:grid xl:max-h-none xl:flex-1 xl:auto-rows-fr xl:overflow-visible' => $selectedUser && ! $directoryIsFiltered,
                    'max-h-[min(62vh,44rem)] overflow-y-auto xl:max-h-none xl:flex-1 xl:overflow-visible' => $selectedUser && $directoryIsFiltered,
                ])>
                    @forelse ($employees as $user)
                        <button type="button" wire:click="selectUser({{ $user->id }})"
                            wire:key="access-user-{{ $user->id }}" @class([
                                'group relative flex min-h-[4.5rem] w-full items-center gap-3 px-3 py-2.5 text-start transition focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-[#be1f24]',
                                'bg-neutral-50' => $selectedUserId === $user->id,
                                'bg-white hover:bg-neutral-50' => $selectedUserId !== $user->id,
                            ])>
                            @if ($selectedUserId === $user->id)
                                <span class="absolute inset-y-2 start-0 w-0.5 rounded-full bg-[#be1f24]" aria-hidden="true"></span>
                            @endif

                            <img src="{{ $user->profile->avatar_url }}" alt=""
                                class="size-10 shrink-0 rounded-full object-cover ring-1 ring-neutral-200">
                            <span class="min-w-0 flex-1">
                                <span @class([
                                    'block truncate text-sm font-bold',
                                    'text-[#be1f24]' => $selectedUserId === $user->id,
                                    'text-neutral-900' => $selectedUserId !== $user->id,
                                ])>{{ $user->profile->name }}</span>
                                <span class="block truncate text-xs text-neutral-500">{{ $user->email }}</span>
                                <span class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($user->roles->take(2) as $role)
                                        <span class="rounded-md border border-neutral-200 bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold leading-none text-neutral-500">
                                            {{ $role->name }}
                                        </span>
                                    @endforeach
                                </span>
                            </span>
                            <span @class([
                                'size-2 shrink-0 rounded-full',
                                'bg-neutral-500' => $user->profile->is_active,
                                'bg-neutral-300' => ! $user->profile->is_active,
                            ]) aria-hidden="true"></span>
                        </button>
                    @empty
                        <div class="flex flex-col items-center justify-center px-5 py-12 text-center">
                            <svg class="size-7 text-[#be1f24]" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                                stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="6.5" />
                                <path d="m16 16 4 4M8.5 11h5" />
                            </svg>
                            <p class="mt-3 text-sm font-bold text-neutral-900">{{ __('access.pagination.no-results') }}</p>
                            <p class="mt-1 max-w-64 text-xs leading-5 text-neutral-500">{{ __('access.no-results-help') }}</p>

                            @if (filled($search))
                                <button type="button" wire:click="clearSearch"
                                    class="mt-4 min-h-9 rounded-lg border border-neutral-200 bg-white px-3 text-xs font-semibold text-neutral-700 transition hover:border-[#be1f24] hover:text-[#be1f24] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                    {{ __('access.clear-search') }}
                                </button>
                            @endif
                        </div>
                    @endforelse
                </div>

                {{ $employees->links('components.pagination.access-management') }}
            </section>

            <section class="flex min-w-0 flex-col rounded-xl border border-neutral-200 bg-white shadow-sm">
                @if ($selectedUser)
                    <form wire:submit="saveEmployeeAccess">
                        <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 px-[clamp(1rem,2vw,1.5rem)] py-4">
                            @if (filled($selectedUser->profile->username))
                                <a href="{{ route('employees.show', ['employee' => $selectedUser->profile->username]) }}"
                                    aria-label="{{ __('general.view-profile-of', ['name' => $selectedUser->profile->name]) }}"
                                    class="shrink-0 rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                    <img src="{{ $selectedUser->profile->avatar_url }}"
                                        alt="{{ $selectedUser->profile->name }}"
                                        class="size-12 rounded-full object-cover ring-1 ring-neutral-200 transition hover:ring-[#be1f24]">
                                </a>
                            @else
                                <img src="{{ $selectedUser->profile->avatar_url }}" alt=""
                                    class="size-12 shrink-0 rounded-full object-cover ring-1 ring-neutral-200">
                            @endif

                            <div class="min-w-0 flex-1">
                                @if (filled($selectedUser->profile->username))
                                    <a href="{{ route('employees.show', ['employee' => $selectedUser->profile->username]) }}"
                                        class="block w-fit max-w-full rounded-sm text-[clamp(1rem,1.5vw,1.2rem)] font-bold text-neutral-900 transition hover:text-[#be1f24] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                        <h2 class="truncate">{{ $selectedUser->profile->name }}</h2>
                                    </a>
                                @else
                                    <h2 class="truncate text-[clamp(1rem,1.5vw,1.2rem)] font-bold text-neutral-900">
                                        {{ $selectedUser->profile->name }}
                                    </h2>
                                @endif

                                <p class="truncate text-sm text-neutral-500">
                                    <a href="mailto:{{ $selectedUser->email }}"
                                        class="rounded-sm transition hover:text-[#be1f24] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                        {{ $selectedUser->email }}
                                    </a>
                                    @if ($selectedUser->profile->city?->name)
                                        <span aria-hidden="true">·</span> {{ $selectedUser->profile->city->name }}
                                    @endif
                                </p>
                            </div>
                            <span @class([
                                'inline-flex min-h-7 items-center rounded-md border px-2.5 text-xs font-bold',
                                'border-neutral-200 bg-neutral-50 text-neutral-700' => $selectedUser->profile->is_active,
                                'border-neutral-200 bg-neutral-50 text-neutral-600' => ! $selectedUser->profile->is_active,
                            ])>
                                {{ $selectedUser->profile->is_active ? __('access.active') : __('access.inactive') }}
                            </span>
                        </div>

                        <div class="space-y-[clamp(1.25rem,2vw,2rem)] p-[clamp(1rem,2vw,1.5rem)]">
                            <section aria-labelledby="assigned-roles-title">
                                <div class="mb-3">
                                    <h3 id="assigned-roles-title" class="text-sm font-bold text-neutral-900">
                                        {{ __('access.assigned-roles') }}
                                    </h3>
                                </div>
                                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))] gap-2">
                                    @foreach ($roles as $role)
                                        <label class="relative flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg border border-neutral-200 px-3 py-2 text-sm transition hover:border-neutral-300">
                                            <input type="checkbox" wire:model.live="employeeRoleIds" value="{{ $role->id }}"
                                                @disabled(! $canManageEmployees)
                                                class="peer rounded border-neutral-300 text-[#be1f24] focus:ring-[#be1f24]">
                                            <span class="min-w-0 truncate font-semibold text-neutral-700 peer-checked:text-[#be1f24]">
                                                {{ $role->name }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                <x-error-span>{{ $errors->first('employeeRoleIds') }}</x-error-span>
                            </section>

                            <section class="border-t border-neutral-100 pt-5" aria-labelledby="active-role-title">
                                <label id="active-role-title" for="employee-active-role" class="mb-2 block text-sm font-bold text-neutral-900">
                                    {{ __('access.active-role') }}
                                </label>
                                <select id="employee-active-role" wire:model.live="employeeActiveRoleId"
                                    @disabled(! $canManageEmployees)
                                    class="min-h-11 w-full rounded-lg border-neutral-200 bg-white text-sm text-neutral-700 focus:border-[#be1f24] focus:ring-[#be1f24] sm:max-w-sm">
                                    <option value="">—</option>
                                    @foreach ($roles->whereIn('id', array_map('intval', $employeeRoleIds)) as $role)
                                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                                    @endforeach
                                </select>
                                <x-error-span>{{ $errors->first('employeeActiveRoleId') }}</x-error-span>
                            </section>

                            <section class="border-t border-neutral-100 pt-5" aria-labelledby="direct-permissions-title">
                                <div class="mb-4">
                                    <h3 id="direct-permissions-title" class="text-sm font-bold text-neutral-900">
                                        {{ __('access.direct-permissions') }}
                                    </h3>
                                    <p class="mt-1 text-xs leading-5 text-neutral-500">{{ __('access.direct-permissions-help') }}</p>
                                </div>

                                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] gap-3">
                                    @foreach ($permissionGroups as $group)
                                        <fieldset class="min-w-0 rounded-lg border border-neutral-200 p-3.5">
                                            <legend class="px-1 text-xs font-bold uppercase tracking-wide text-neutral-700">
                                                {{ $group['label'] }}
                                            </legend>
                                            <div class="mt-1 grid grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))] gap-x-3 gap-y-2">
                                                @foreach ($group['permissions'] as $permission)
                                                    <label class="flex min-h-8 cursor-pointer items-center gap-2 text-sm text-neutral-600">
                                                        <input type="checkbox" wire:model.live="employeePermissionIds" value="{{ $permission['id'] }}"
                                                            @disabled(! $canManageEmployees)
                                                            class="rounded border-neutral-300 text-[#be1f24] focus:ring-[#be1f24]">
                                                        <span>{{ $permission['label'] }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endforeach
                                </div>
                            </section>
                        </div>

                        @if ($canManageEmployees)
                            <div class="sticky bottom-0 flex justify-end border-t border-neutral-200 bg-white/95 px-[clamp(1rem,2vw,1.5rem)] py-3 backdrop-blur-sm">
                                <button type="submit" wire:loading.attr="disabled"
                                    @disabled(! $this->employeeAccessIsDirty)
                                    data-testid="save-employee-access"
                                    data-access-dirty="{{ $this->employeeAccessIsDirty ? 'true' : 'false' }}"
                                    class="min-h-11 rounded-lg px-5 text-sm font-bold shadow-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24] enabled:bg-[#be1f24] enabled:text-white enabled:hover:opacity-90 disabled:cursor-not-allowed disabled:bg-neutral-200 disabled:text-neutral-500 disabled:shadow-none">
                                    <span wire:loading.remove wire:target="saveEmployeeAccess">{{ __('access.save-access') }}</span>
                                    <span wire:loading wire:target="saveEmployeeAccess">{{ __('general.saving') }}…</span>
                                </button>
                            </div>
                        @endif
                    </form>
                @else
                    <div class="flex min-h-[clamp(22rem,55vh,38rem)] flex-1 items-center justify-center p-6 text-center">
                        <div class="max-w-md">
                            <svg class="mx-auto size-7 text-[#be1f24]" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.1a7.5 7.5 0 0 1 15 0M18 8.25h3m-1.5-1.5v3" />
                            </svg>
                            <h2 class="mt-4 text-[clamp(1rem,1.5vw,1.15rem)] font-bold text-neutral-900">
                                {{ __('access.select-employee') }}
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-neutral-500">{{ __('access.select-employee-help') }}</p>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    @else
        <div class="grid min-w-0 gap-[clamp(1rem,1.5vw,1.5rem)] xl:grid-cols-[19rem_minmax(0,1fr)]">
            <aside class="min-w-0 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
                @if ($canManageRoles)
                    <form wire:submit="createRole" class="border-b border-neutral-200 p-3" data-testid="create-role-form">
                        <label for="new-role-name" class="mb-2 block text-sm font-bold text-neutral-900">{{ __('access.new-role') }}</label>
                        <div class="grid gap-2">
                            <input id="new-role-name" wire:model="newRoleName"
                                class="min-h-11 min-w-0 rounded-lg border-neutral-200 text-sm focus:border-[#be1f24] focus:ring-[#be1f24]"
                                placeholder="regional-auditor">
                            <button class="min-h-11 rounded-lg bg-[#be1f24] px-4 text-sm font-bold text-white transition hover:bg-[#be1f24] hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                {{ __('access.create-role') }}
                            </button>
                        </div>
                        <x-error-span>{{ $errors->first('newRoleName') }}</x-error-span>
                    </form>
                @endif

                <div data-testid="access-role-directory" class="divide-y divide-neutral-100">
                    @foreach ($roles as $role)
                        <button type="button" wire:click="selectRole({{ $role->id }})"
                            wire:key="access-role-{{ $role->id }}" @class([
                                'relative flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2.5 text-start transition focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-[#be1f24]',
                                'bg-neutral-50 text-[#be1f24]' => $selectedRoleId === $role->id,
                                'bg-white text-neutral-700 hover:bg-neutral-50' => $selectedRoleId !== $role->id,
                            ])>
                            @if ($selectedRoleId === $role->id)
                                <span class="absolute inset-y-2 start-0 w-0.5 rounded-full bg-[#be1f24]" aria-hidden="true"></span>
                            @endif
                            <span class="min-w-0 truncate text-sm font-bold">{{ $role->name }}</span>
                            <span class="inline-flex min-w-6 shrink-0 items-center justify-center rounded-md border border-neutral-200 bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold text-neutral-500">
                                {{ $role->users_count }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </aside>

            <section class="min-w-0 rounded-xl border border-neutral-200 bg-white shadow-sm">
                @if ($selectedRole)
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-neutral-200 px-[clamp(1rem,2vw,1.5rem)] py-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-[clamp(1.1rem,1.6vw,1.35rem)] font-bold text-neutral-900">{{ $selectedRole->name }}</h2>
                                @if ($selectedRoleIsAdministrator)
                                    <span class="rounded-md border border-[#be1f24] px-2 py-1 text-[0.7rem] font-bold text-[#be1f24]">
                                        {{ __('access.protected-role') }}
                                    </span>
                                @elseif ($selectedRoleIsSystem)
                                    <span class="rounded-md border border-neutral-200 bg-neutral-50 px-2 py-1 text-[0.7rem] font-bold text-neutral-600">
                                        {{ __('access.system-role') }}
                                    </span>
                                @endif
                            </div>
                            <button type="button" wire:click="showAssignedUsers"
                                class="group mt-1 inline-flex min-h-8 items-center gap-1.5 rounded-md text-sm font-semibold text-neutral-600 underline decoration-neutral-300 underline-offset-4 transition hover:text-[#be1f24] hover:decoration-[#be1f24] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]"
                                aria-haspopup="dialog">
                                <svg class="size-4 shrink-0 text-[#be1f24]" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4m4-7a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm5.5 2.5c1.5.6 2.5 2 2.5 3.5M16.5 6.2a3 3 0 0 1 0 5.6" />
                                </svg>
                                <span>{{ __('access.assigned-users', ['count' => $selectedRole->users_count]) }}</span>
                                <svg class="size-3.5 shrink-0 rtl:rotate-180" viewBox="0 0 20 20" fill="none"
                                    stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m7.5 4.5 5 5-5 5" />
                                </svg>
                            </button>
                            @if ($selectedRoleIsAdministrator)
                                <p class="mt-1.5 text-xs font-semibold text-[#be1f24]">{{ __('access.protected-role-help') }}</p>
                            @endif
                        </div>

                        @if ($canManageRoles && ! $selectedRoleIsSystem)
                            <div class="grid w-full items-end gap-2 sm:w-auto sm:grid-cols-[minmax(13rem,1fr)_auto_auto]">
                                <div class="min-w-0">
                                    <label class="mb-1.5 block text-xs font-bold text-neutral-700" for="selected-role-name">
                                        {{ __('access.role-name') }}
                                    </label>
                                    <input id="selected-role-name" wire:model="selectedRoleName"
                                        autocomplete="off" spellcheck="false"
                                        class="min-h-11 w-full min-w-0 rounded-lg border-neutral-300 bg-neutral-50 px-3 text-sm font-medium text-neutral-900 shadow-sm transition hover:border-neutral-400 focus:border-[#be1f24] focus:bg-white focus:ring-[#be1f24]">
                                </div>
                                <button type="button" wire:click="renameRole"
                                    class="min-h-11 rounded-lg border border-neutral-200 px-4 text-sm font-bold text-neutral-700 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                    {{ __('access.rename-role') }}
                                </button>
                                <button type="button" wire:click="deleteRole" wire:confirm="{{ __('access.delete-role') }}?"
                                    class="min-h-11 rounded-lg border border-[#be1f24] px-4 text-sm font-bold text-[#be1f24] transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                    {{ __('access.delete-role') }}
                                </button>
                            </div>
                        @endif
                    </div>
                    <x-error-span>{{ $errors->first('selectedRoleName') }}</x-error-span>

                    <form wire:submit="saveRolePermissions">
                        <div class="flex flex-wrap items-center justify-between gap-3 px-[clamp(1rem,2vw,1.5rem)] pt-4">
                            <h3 class="text-sm font-bold text-neutral-900">{{ __('access.permissions') }}</h3>
                            @if ($canManagePermissions)
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="selectAllRolePermissions"
                                        class="min-h-9 rounded-md border border-neutral-200 px-3 text-xs font-bold text-neutral-600 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                        {{ __('access.select-all') }}
                                    </button>
                                    <button type="button" wire:click="clearRolePermissions" @disabled($selectedRoleIsAdministrator)
                                        class="min-h-9 rounded-md border border-neutral-200 px-3 text-xs font-bold text-neutral-600 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24] disabled:cursor-not-allowed disabled:opacity-40">
                                        {{ __('access.clear-all') }}
                                    </button>
                                </div>
                            @endif
                        </div>

                        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] gap-3 p-[clamp(1rem,2vw,1.5rem)]">
                            @foreach ($rolePermissionGroups as $group)
                                <fieldset class="min-w-0 rounded-lg border border-neutral-200 p-3.5">
                                    <legend class="px-1 text-xs font-bold uppercase tracking-wide text-neutral-700">
                                        {{ $group['label'] }}
                                    </legend>
                                    <div class="mt-1 grid grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))] gap-x-3 gap-y-2">
                                        @foreach ($group['permissions'] as $permission)
                                            <label class="flex min-h-8 items-center gap-2 text-sm text-neutral-600">
                                                <input type="checkbox" wire:model="rolePermissionIds" value="{{ $permission['id'] }}"
                                                    @disabled($selectedRoleIsAdministrator || ! $canManagePermissions)
                                                    class="rounded border-neutral-300 text-[#be1f24] focus:ring-[#be1f24] disabled:opacity-70">
                                                <span>{{ $permission['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>

                        @if ($canManagePermissions)
                            <div class="sticky bottom-0 flex justify-end border-t border-neutral-200 bg-white/95 px-[clamp(1rem,2vw,1.5rem)] py-3 backdrop-blur-sm">
                                <button type="submit" data-testid="save-role-permissions"
                                    class="min-h-11 rounded-lg bg-[#be1f24] px-5 text-sm font-bold text-white shadow-sm transition hover:bg-[#be1f24] hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                    {{ __('access.save-permissions') }}
                                </button>
                            </div>
                        @endif
                    </form>
                @endif
            </section>
        </div>

        <x-modal id="assigned-role-users-modal" max-width="3xl" wire:model.live="showAssignedUsersModal">
            <section class="overflow-hidden rounded-xl bg-white" role="dialog" aria-modal="true"
                aria-labelledby="assigned-role-users-title">
                <header class="flex items-start justify-between gap-4 border-b border-neutral-200 px-[clamp(1rem,3vw,1.5rem)] py-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="grid size-9 shrink-0 place-items-center rounded-lg border border-neutral-200 bg-neutral-50 text-[#be1f24]"
                                aria-hidden="true">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4m4-7a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm5.5 2.5c1.5.6 2.5 2 2.5 3.5M16.5 6.2a3 3 0 0 1 0 5.6" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <h2 id="assigned-role-users-title" class="truncate text-lg font-bold text-neutral-900">
                                    {{ __('access.assigned-users-title', ['role' => $selectedRole?->name]) }}
                                </h2>
                                <p class="mt-0.5 text-sm text-neutral-500">
                                    {{ trans_choice('access.assigned-users-summary', $selectedRoleUsers->count(), ['count' => $selectedRoleUsers->count()]) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <button type="button" x-on:click="show = false"
                        class="grid size-10 shrink-0 place-items-center rounded-lg border border-neutral-200 text-neutral-500 transition hover:bg-neutral-50 hover:text-neutral-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]"
                        aria-label="{{ __('access.close') }}">
                        <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                            stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" d="m5 5 10 10M15 5 5 15" />
                        </svg>
                    </button>
                </header>

                <div class="max-h-[min(65vh,38rem)] overflow-y-auto overscroll-contain p-[clamp(0.75rem,2vw,1.25rem)]">
                    @forelse ($selectedRoleUsers as $roleUser)
                        @php($roleProfile = $roleUser->profile)
                        <article wire:key="assigned-role-user-{{ $roleUser->id }}"
                            class="flex min-w-0 items-center gap-3 border-b border-neutral-100 px-1 py-3 first:pt-0 last:border-0 last:pb-0">
                            @if ($roleProfile && filled($roleProfile->username))
                                <a href="{{ route('employees.show', ['employee' => $roleProfile->username]) }}"
                                    class="shrink-0 rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]"
                                    aria-label="{{ __('access.view-employee', ['name' => $roleProfile->nameForLocale(app()->getLocale())]) }}">
                                    <img src="{{ $roleProfile->avatar_url }}" alt=""
                                        class="size-11 rounded-full border border-neutral-200 bg-neutral-50 object-cover">
                                </a>
                            @else
                                <img src="{{ asset('images/default-avatar.png') }}" alt=""
                                    class="size-11 shrink-0 rounded-full border border-neutral-200 bg-neutral-50 object-cover">
                            @endif

                            <div class="min-w-0 flex-1">
                                @if ($roleProfile && filled($roleProfile->username))
                                    <a href="{{ route('employees.show', ['employee' => $roleProfile->username]) }}"
                                        class="block truncate text-sm font-bold text-neutral-900 transition hover:text-[#be1f24] hover:underline hover:underline-offset-4 focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                        {{ $roleProfile->nameForLocale(app()->getLocale()) }}
                                    </a>
                                @else
                                    <p class="truncate text-sm font-bold text-neutral-900">{{ __('access.no-employee-profile') }}</p>
                                @endif
                                <a href="mailto:{{ $roleUser->email }}"
                                    class="mt-0.5 block truncate text-sm text-neutral-500 transition hover:text-[#be1f24] hover:underline hover:underline-offset-4 focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                                    {{ $roleUser->email }}
                                </a>
                            </div>

                            @if ($roleProfile)
                                <span @class([
                                    'inline-flex shrink-0 items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-bold',
                                    'border-neutral-200 bg-neutral-50 text-neutral-700' => $roleProfile->is_active,
                                    'border-neutral-200 bg-neutral-50 text-neutral-500' => ! $roleProfile->is_active,
                                ])>
                                    <span @class([
                                        'size-1.5 rounded-full',
                                        'bg-neutral-500' => $roleProfile->is_active,
                                        'bg-neutral-400' => ! $roleProfile->is_active,
                                    ]) aria-hidden="true"></span>
                                    {{ $roleProfile->is_active ? __('access.active') : __('access.inactive') }}
                                </span>
                            @endif
                        </article>
                    @empty
                        <div class="grid min-h-52 place-items-center text-center">
                            <div>
                                <span class="mx-auto grid size-11 place-items-center rounded-lg border border-neutral-200 bg-neutral-50 text-[#be1f24]"
                                    aria-hidden="true">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4m4-7a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                                    </svg>
                                </span>
                                <h3 class="mt-3 text-sm font-bold text-neutral-900">{{ __('access.no-assigned-users') }}</h3>
                                <p class="mt-1 text-sm text-neutral-500">{{ __('access.no-assigned-users-help') }}</p>
                            </div>
                        </div>
                    @endforelse
                </div>

                <footer class="flex justify-end border-t border-neutral-200 bg-neutral-50 px-[clamp(1rem,3vw,1.5rem)] py-3">
                    <button type="button" x-on:click="show = false"
                        class="min-h-10 rounded-lg border border-neutral-300 bg-white px-4 text-sm font-bold text-neutral-700 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#be1f24]">
                        {{ __('access.close') }}
                    </button>
                </footer>
            </section>
        </x-modal>
    @endif
</div>
