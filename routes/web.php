<?php

use App\Http\Controllers\ArchiveFileController;
use App\Http\Controllers\EmployeeAvatarController;
use App\Http\Controllers\EmployeeDocumentController;
use App\Http\Controllers\PublicCareerController;
use App\Http\Middleware\ActiveLivewirePermissionMiddleware;
use App\Livewire\AccessManagement\Index as AccessManagementIndex;
use App\Livewire\ActivityLog\ActivityLogModule;
use App\Livewire\Archives\ArchivedFilesModule;
use App\Livewire\Badges\BadgesModule;
use App\Livewire\Basics\CourseCategoryModule;
use App\Livewire\ChecklistItems\AllItems;
use App\Livewire\ChecklistItems\DoneRequests;
use App\Livewire\ChecklistItems\PendingItems;
use App\Livewire\ChecklistItems\PendingReturns;
use App\Livewire\Dashboard\Index as DashboardIndex;
use App\Livewire\Employee\EmployeeProfile;
use App\Livewire\Employee\EmployeeStatistics;
use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Livewire\Employee\Leave\EmpLeaveBalanceModule;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Livewire\EmployeeCards\ShowDigitalId;
use App\Livewire\News\NewsCreate;
use App\Livewire\News\NewsModule;
use App\Livewire\Notifications\Index;
use App\Livewire\Settings\Password;
use App\Livewire\Statistics\ContractStatistics;
use App\Livewire\Statistics\GeneralStatistics;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

Route::get('/careers/{token}', [PublicCareerController::class, 'show'])
    ->middleware('throttle:careers-view')
    ->name('careers.show');
Route::get('/careers/{token}/submitted', [PublicCareerController::class, 'submitted'])
    ->middleware('throttle:careers-view')
    ->name('careers.submitted');
Route::get('/careers/{token}/sign-in', [PublicCareerController::class, 'signIn'])
    ->middleware('throttle:careers-view')
    ->name('careers.sign-in');
Route::post('/careers/{token}/invitation', [PublicCareerController::class, 'checkInvitation'])
    ->middleware('throttle:careers-invitation')
    ->name('careers.invitation');
Route::post('/careers/{token}/applications', [PublicCareerController::class, 'store'])
    ->middleware('throttle:careers-submit')
    ->name('careers.submit');

// Public ID card: /ID/[city 3 digits][program 3 digits][employee id]-[index]
// (access-policy.md §9 A2D-Q6). The constraint only bounds the segment; it is
// deliberately loose so malformed and old username-style links still reach
// ShowDigitalId, which parses strictly and answers every invalid card with
// the same "card is not valid" page and the same 404.
Route::get('/ID/{card}', ShowDigitalId::class)
    ->where('card', '[^/]{1,64}')
    ->middleware('throttle:30,1')
    ->name('digital-id.show');

// Photo for the public ID card. Outside the locale group and outside 'auth'
// because the card itself is unauthenticated; the signed URL is minted by
// ShowDigitalId only after the card link resolved to an active employee's
// current, enabled card, and it expires within minutes.
Route::get('/ID/{employee}/photo', [EmployeeAvatarController::class, 'digitalId'])
    ->middleware(['signed', 'throttle:60,1'])
    ->name('digital-id.avatar');

Route::group(
    [
        'prefix' => LaravelLocalization::setLocale(),
        'middleware' => ['localeSessionRedirect', 'localizationRedirect', 'localeViewPath'],
    ],
    function () {
        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', ActiveLivewirePermissionMiddleware::class]));

        Route::middleware(['auth'])->group(function () {
            Route::get('/', DashboardIndex::class)
                ->name('dashboard');
            Route::prefix('statistics')
                ->name('statistics.')
                ->middleware('active_permission:statistics.view')
                ->group(function () {
                    Route::get('/general', GeneralStatistics::class)->name('general');
                    Route::get('/contract', ContractStatistics::class)->name('contract');
                });

            Route::redirect('settings', 'settings/password');
            Route::get('settings/password', Password::class)->name('settings.password');
            Route::get('my-profile', EmployeeProfile::class)->name('my-profile');
            // C3: the work inbox. No permission of its own: every row comes from a
            // source's scoped query and every action re-checks its domain policy.
            Route::get('inbox', App\Livewire\Inbox\Index::class)->name('inbox');

            // Basic project routes
            require __DIR__.'/basic-project.php';

            require __DIR__.'/projects-management.php';

            require __DIR__.'/employees-management.php';

            require __DIR__.'/recruitment-publishing.php';
            require __DIR__.'/recruitment.php';

            Route::prefix('leaves')
                ->name('leaves.')
                ->group(function () {
                    Route::get('/', MyLeaveRequestModule::class)->middleware('active_permission:leaves.view-own')->name('index');
                    Route::get('/all', AllLeaveRequestModule::class)->name('all')->middleware('active_permission:leaves.view-all');
                    Route::get('/balance', EmpLeaveBalanceModule::class)->name('balance')->middleware('active_permission:leaves.manage-balances');
                });

            Route::view('archived-files/{folder?}', 'archives.index')->middleware('active_permission:archives.view')->name('archived-files.index');
            Route::get('my-folders/{folderId?}', ArchivedFilesModule::class)->defaults('isShared', false)->middleware('active_permission:archives.view')->name('my-folders.index');
            Route::get('shared-folders/{folderId?}', ArchivedFilesModule::class)->defaults('isShared', true)->middleware('active_permission:archives.view')->name('shared-folders.index');
            Route::get('folder-files/{media}/preview', [ArchiveFileController::class, 'preview'])->middleware('active_permission:archives.view')->name('folder-files.preview');
            Route::get('folder-files/{media}/download', [ArchiveFileController::class, 'download'])->middleware('active_permission:archives.download')->name('folder-files.download');
            Route::get('folder-files/{media}/office-preview', [ArchiveFileController::class, 'officePreview'])->middleware('active_permission:archives.view')->name('folder-files.office-preview');
            Route::get('employee-files/{media}/download', [EmployeeDocumentController::class, 'download'])
                ->middleware('throttle:60,1')
                ->name('employee-files.download');

            // Employee photographs live on the private disk; every in-app
            // <img> resolves through here so EmployeePolicy (and with it the
            // city scope) applies to the image itself, not just to the page
            // that embeds it.
            Route::get('employee-photo/{employee}', [EmployeeAvatarController::class, 'internal'])
                ->name('employees.avatar');

            Route::prefix('checklist-items')
                ->name('checklist-items.')
                ->group(function () {
                    Route::get('/pending-delivers', PendingItems::class)->middleware('active_permission:checklists.deliver')->name('pending-delivers');
                    Route::get('/pending-returns', PendingReturns::class)->middleware('active_permission:checklists.receive')->name('pending-returns');
                    Route::get('/done-requests', DoneRequests::class)->middleware('active_permission:checklists.manage')->name('done-requests');
                    Route::get('/all', AllItems::class)->middleware('active_permission:checklists.view-own')->name('index');
                });

            Route::get('/notifications', Index::class)->name('notifications.index');

            Route::get('/news', NewsModule::class)->name('news.index')->middleware('active_permission:news.view');

            Route::get('/employee-statistics/{mode}', [EmployeeStatistics::class, 'getStatistics'])
                ->middleware('active_permission:statistics.view');

            Route::get('/badges', BadgesModule::class)
                ->middleware('active_permission:badges.view')
                ->name('badges.index');

            Route::get('/news/create', NewsCreate::class)->name('news.create')->middleware('active_permission:news.create');
            Route::get('/news/{news}/edit', NewsCreate::class)->name('news.edit')->middleware('active_permission:news.update');

            Route::get('/course-categories', CourseCategoryModule::class)->middleware('reference_page:project-basics')->name('course-categories.index');
            Route::get('/activity-log', ActivityLogModule::class)
                ->name('activity-log.index')
                ->middleware('active_permission:activity-log.view');

            Route::get('/access-management', AccessManagementIndex::class)
                ->middleware('active_permission:access-management.view')
                ->name('access-management.index');

        });

        require __DIR__.'/auth.php';
    },
);
