<?php

use App\Livewire\Position\PositionCreate;
use App\Livewire\Position\PositionEdit;
use App\Livewire\Position\PositionsModule;
use App\Livewire\ProgramManagement\Center\CenterCreate;
use App\Livewire\ProgramManagement\Center\CenterDetails;
use App\Livewire\ProgramManagement\Center\CentersModule;
use App\Livewire\ProgramManagement\Program\Create as ProgramCreate;
use App\Livewire\ProgramManagement\Program\Detail as ProgramDetail;
use App\Livewire\ProgramManagement\Program\Index as ProgramIndex;
use App\Livewire\ProgramManagement\Program\Update as ProgramUpdate;
use Illuminate\Support\Facades\Route;

Route::prefix('programs')
    ->name('programs.')
    ->group(function () {
        Route::get('/', ProgramIndex::class)->name('index')->middleware('reference_page:programs');
        Route::get('/create', ProgramCreate::class)->name('create')->middleware('active_permission:programs.create');
        Route::get('/edit/{program:slug}', ProgramUpdate::class)->name('edit')->middleware('active_permission:programs.update');
        Route::get('/{program:slug}', ProgramDetail::class)->name('show')->middleware('reference_page:programs');
    });
Route::prefix('positions')
    ->name('positions.')
    ->group(function () {
        Route::get('/', PositionsModule::class)->middleware('reference_page:positions')->name('index');
        Route::get('/create', PositionCreate::class)->middleware('active_permission:positions.create')->name('create');
        Route::get('/{position}/edit', PositionEdit::class)->middleware('active_permission:positions.update')->name('edit');
    });

Route::prefix('centers')
    ->name('centers.')
    ->group(function () {
        Route::get('/', CentersModule::class)->middleware('reference_page:centers')->name('index');
        Route::get('/create', CenterCreate::class)->name('create')->middleware('active_permission:centers.create');
        Route::get('/{center}', CenterDetails::class)->middleware('reference_page:centers')->name('show');
    });
