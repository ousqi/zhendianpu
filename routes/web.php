<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\MonthlyBookkeepingReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));
Route::get('/reports/{ledger:public_id}', MonthlyBookkeepingReportController::class)->name('reports.monthly.show');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('/account/password', [LoginController::class, 'password'])->middleware('throttle:login')->name('account.password');
});
