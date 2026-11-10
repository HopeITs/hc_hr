<?php

use App\Http\Controllers\ContractExportController;
use App\Livewire\Contract\ContractCreate;
use App\Livewire\Contract\ContractDetails;
use App\Livewire\Contract\ContractEdit;
use App\Livewire\Contract\ContractsModule;
use App\Livewire\Employee\EmployeeCreate;
use App\Livewire\Employee\EmployeeEdit;
use App\Livewire\Employee\EmployeeModule;
use App\Livewire\Employee\EmployeeProfile;
use App\Livewire\ProgramManagement\DefaultDeliverable\DefaultDeliverablesModule;

Route::prefix('employees')
    ->name('employees.')
    ->group(function () {
        Route::get('/', EmployeeModule::class)->middleware('active_permission:employees.view')->name('index');
        Route::get('/create', EmployeeCreate::class)->middleware('active_permission:employees.create')->name('create');
        Route::get('/{employee}/edit', EmployeeEdit::class)->middleware('active_permission:employees.update')->name('edit');

    });
Route::get('employees/{employee:username}/show', EmployeeProfile::class)->name('employees.show');
Route::get('/default-deliverables', DefaultDeliverablesModule::class)->middleware('active_permission:checklists.manage')->name('default-deliverables.index');

Route::prefix('contracts')
    ->name('contracts.')
    ->group(function () {
        Route::get('/', ContractsModule::class)->middleware('active_permission:contracts.view')->name('index');
        Route::get('/create', ContractCreate::class)->middleware('active_permission:contracts.create')->name('create');
        Route::get('/{contract}/edit', ContractEdit::class)->middleware('active_permission:contracts.update')->name('edit');
        // The creation wizard again, prefilled from the contract being renewed.
        Route::get('/{contract}/renew', ContractCreate::class)->middleware('active_permission:contracts.create')->name('renew');
        Route::get('/{contract}/export', [ContractExportController::class, 'pdf'])->middleware('active_permission:contracts.export')->name('export');
        Route::get('/{contract}', ContractDetails::class)->middleware('active_permission:contracts.view')->name('show');
    });

// The print layout as plain HTML, for iterating on it in devtools. It answers
// 404 outside the local environment (the controller checks, so the gate is
// testable) and otherwise holds the same export/salary/city gates as the PDF:
// the page carries the pay clause.
Route::get('contracts/{contract}/export-preview', [ContractExportController::class, 'preview'])
    ->middleware('active_permission:contracts.export')
    ->name('contracts.export-preview');
