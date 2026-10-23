<nav class="fixed top-0 z-50 w-full bg-white border-b border-neutral-200 dark:bg-neutral-800 dark:border-neutral-700">
    <div class="px-3 py-3 lg:px-4 lg:ps-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center justify-start rtl:justify-end">
                <button data-drawer-target="logo-sidebar" data-drawer-toggle="logo-sidebar" aria-controls="logo-sidebar" type="button" class="inline-flex items-center p-2 text-body text-secondary-medium rounded-control sm:hidden hover:bg-neutral-100 focus:outline-none focus:ring-2 focus:ring-neutral-200 dark:text-secondary-light dark:hover:bg-neutral-700 dark:focus:ring-neutral-600">
                    <span class="sr-only">{{ __('general.a11y.open-sidebar') }}</span>
                    <svg class="w-6 h-6" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path clip-rule="evenodd" fill-rule="evenodd" d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 10.5a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5a.75.75 0 01-.75-.75zM2 10a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 10z"></path>
                    </svg>
                </button>
                <a href="https://flowbite.com" class="flex ms-2 md:me-24">
                    <x-app-logo class="w-36"/>
{{--                    <span class="self-center text-h4 font-semibold sm:text-h3 whitespace-nowrap dark:text-white">test</span>--}}
                </a>
            </div>
            <div class="flex items-center">
                <div class="flex items-center ms-3">
                    <div>
                        <button type="button" class="flex text-body bg-neutral-800 rounded-full focus:ring-4 focus:ring-neutral-300 dark:focus:ring-neutral-600" aria-expanded="false" data-dropdown-toggle="dropdown-user">
                            <span class="sr-only">Open user menu</span>
                            <img class="size-12 rounded-full" src="https://flowbite.com/docs/images/people/profile-picture-5.jpg" alt="user photo">
                        </button>
                    </div>
                    <div class="z-50 hidden my-4 text-body list-none bg-white divide-y divide-neutral-100 rounded-surface shadow-e1 dark:bg-neutral-700 dark:divide-neutral-600" id="dropdown-user">
                        <div class="px-4 py-3" role="none">
                            <p class="text-body text-neutral-900 dark:text-white" role="none">
                                Neil Sims
                            </p>
                            <p class="text-body font-medium text-neutral-900 truncate dark:text-secondary-light" role="none">
                                neil.sims@flowbite.com
                            </p>
                        </div>
                        <ul class="py-1" role="none">
                            <li>
                                <a href="#" class="block px-4 py-2 text-body text-neutral-800 hover:bg-neutral-100 dark:text-secondary-light dark:hover:bg-neutral-600 dark:hover:text-white" role="menuitem">Dashboard</a>
                            </li>
                            <li>
                                <a href="#" class="block px-4 py-2 text-body text-neutral-800 hover:bg-neutral-100 dark:text-secondary-light dark:hover:bg-neutral-600 dark:hover:text-white" role="menuitem">Settings</a>
                            </li>
                            <li>
                                <a href="#" class="block px-4 py-2 text-body text-neutral-800 hover:bg-neutral-100 dark:text-secondary-light dark:hover:bg-neutral-600 dark:hover:text-white" role="menuitem">Earnings</a>
                            </li>
                            <li>
                                <a href="#" class="block px-4 py-2 text-body text-neutral-800 hover:bg-neutral-100 dark:text-secondary-light dark:hover:bg-neutral-600 dark:hover:text-white" role="menuitem">Sign out</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>
