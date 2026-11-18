<?php

use App\Http\Controllers\Admin\SpendReportController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// No marketing page: the root is the login screen. Signed-in users are forwarded on to the app by
// the guest middleware on /login.
Route::redirect('/', '/login');

// Breeze sends users here after login; the app's home is the generate page.
Route::get('/dashboard', fn () => to_route('generations.create'))
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/admin/report', [SpendReportController::class, 'index'])
        ->middleware('can:view-reports')->name('admin.report');

    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('/generations/create', [GenerationController::class, 'create'])->name('generations.create');
    Route::get('/generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('/generations',[GenerationController::class, 'store'])->name('generations.store');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
