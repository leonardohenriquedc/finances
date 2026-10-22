<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialPlanController;
use App\Http\Controllers\OccurrenceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Same-origin JSON API: keep session cookies and CSRF protection for the Vue application.
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::get('csrf', [AuthController::class, 'csrf'])->name('csrf');
    Route::middleware('throttle:authentication')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('guest')->name('register');
        Route::post('login', [AuthController::class, 'login'])->middleware('guest')->name('login');
    });
    Route::middleware('auth')->group(function (): void {
        Route::get('user', [AuthController::class, 'user'])->name('user');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('categories', [OccurrenceController::class, 'categories'])->name('categories');
        Route::get('occurrences/export', [OccurrenceController::class, 'export'])->name('occurrences.export');
        Route::get('occurrences', [OccurrenceController::class, 'index'])->name('occurrences.index');
        Route::patch('occurrences/{occurrence}', [OccurrenceController::class, 'update'])->whereNumber('occurrence')->name('occurrences.update');
        Route::delete('occurrences/{occurrence}', [OccurrenceController::class, 'destroy'])->whereNumber('occurrence')->name('occurrences.destroy');
        Route::put('occurrences/{occurrence}/payment', [OccurrenceController::class, 'payment'])->whereNumber('occurrence')->name('occurrences.payment');
        Route::delete('occurrences/{occurrence}/payment', [OccurrenceController::class, 'reopen'])->whereNumber('occurrence')->name('occurrences.reopen');
        Route::post('plans/{plan}/lifecycle', [FinancialPlanController::class, 'lifecycle'])->whereNumber('plan')->name('plans.lifecycle');
        Route::apiResource('plans', FinancialPlanController::class)->parameters(['plans' => 'plan'])->whereNumber('plan');
    });
});
