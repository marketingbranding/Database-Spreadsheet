<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BastController;
use App\Http\Controllers\AkadController;
use App\Http\Controllers\BiCheckController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingChangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportConflictController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PemberkasanController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProsesBankController;
use App\Http\Controllers\PsjbController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\TtdPpjbController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/psjb/create', [PsjbController::class, 'create'])->name('psjb.create');
    Route::post('/projects/{project}/psjb', [PsjbController::class, 'store'])->name('psjb.store');
    Route::get('/projects/{project}/bi-checking', [BiCheckController::class, 'index'])->name('bi.index');
    Route::get('/projects/{project}/bi-checking/{booking}', [BiCheckController::class, 'edit'])->name('bi.edit');
    Route::post('/projects/{project}/bi-checking/{booking}', [BiCheckController::class, 'update'])->name('bi.update');
    Route::get('/projects/{project}/pemberkasan', [PemberkasanController::class, 'index'])->name('pemberkasan.index');
    Route::get('/projects/{project}/pemberkasan/{booking}', [PemberkasanController::class, 'edit'])->name('pemberkasan.edit');
    Route::post('/projects/{project}/pemberkasan/{booking}', [PemberkasanController::class, 'update'])->name('pemberkasan.update');
    Route::get('/projects/{project}/proses-bank', [ProsesBankController::class, 'index'])->name('bank.index');
    Route::get('/projects/{project}/proses-bank/{booking}/create', [ProsesBankController::class, 'create'])->name('bank.create');
    Route::post('/projects/{project}/proses-bank/{booking}', [ProsesBankController::class, 'store'])->name('bank.store');
    Route::get('/projects/{project}/proses-bank/{submission}/result', [ProsesBankController::class, 'editResult'])->name('bank.result.edit');
    Route::post('/projects/{project}/proses-bank/{submission}/result', [ProsesBankController::class, 'storeResult'])->name('bank.result.store');
    Route::get('/projects/{project}/ttd-ppjb', [TtdPpjbController::class, 'index'])->name('ppjb.index');
    Route::get('/projects/{project}/ttd-ppjb/{booking}/create', [TtdPpjbController::class, 'create'])->name('ppjb.create');
    Route::post('/projects/{project}/ttd-ppjb/{booking}', [TtdPpjbController::class, 'store'])->name('ppjb.store');
    Route::get('/projects/{project}/akad', [AkadController::class, 'index'])->name('akad.index');
    Route::get('/projects/{project}/akad/{booking}/create', [AkadController::class, 'create'])->name('akad.create');
    Route::post('/projects/{project}/akad/{booking}', [AkadController::class, 'store'])->name('akad.store');
    Route::get('/projects/{project}/bast', [BastController::class, 'index'])->name('bast.index');
    Route::get('/projects/{project}/bast/{booking}/create', [BastController::class, 'create'])->name('bast.create');
    Route::post('/projects/{project}/bast/{booking}', [BastController::class, 'store'])->name('bast.store');

    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::get('/projects/{project}/bookings/{booking}/change-name', [BookingChangeController::class, 'nameCreate'])->name('bookings.change-name.create');
    Route::post('/projects/{project}/bookings/{booking}/change-name', [BookingChangeController::class, 'nameStore'])->name('bookings.change-name.store');
    Route::get('/projects/{project}/bookings/{booking}/move-lot', [BookingChangeController::class, 'lotCreate'])->name('bookings.move-lot.create');
    Route::post('/projects/{project}/bookings/{booking}/move-lot', [BookingChangeController::class, 'lotStore'])->name('bookings.move-lot.store');
    Route::post('/bookings/{booking}/withdraw', [PsjbController::class, 'withdraw'])->name('bookings.withdraw');
    Route::post('/bookings/{booking}/cancel', [PsjbController::class, 'cancel'])->name('bookings.cancel');

    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::get('/reconciliation/{project}', [ReconciliationController::class, 'show'])->name('reconciliation.show');

    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::get('/imports/{batch}', [ImportController::class, 'show'])->name('imports.show');

    Route::get('/projects/{project}/import-conflicts', [ImportConflictController::class, 'index'])->name('import-conflicts.index');
    Route::get('/projects/{project}/import-conflicts/{conflict}', [ImportConflictController::class, 'show'])->name('import-conflicts.show');
    Route::post('/projects/{project}/import-conflicts/{conflict}/proposals', [ImportConflictController::class, 'storeProposal'])->name('import-conflicts.proposal.store');
    Route::post('/projects/{project}/import-conflicts/{conflict}/proposals/{proposal}/approve', [ImportConflictController::class, 'approve'])->name('import-conflicts.proposal.approve');
    Route::post('/projects/{project}/import-conflicts/{conflict}/proposals/{proposal}/reject', [ImportConflictController::class, 'reject'])->name('import-conflicts.proposal.reject');
});
