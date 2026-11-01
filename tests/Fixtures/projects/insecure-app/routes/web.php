<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/phpinfo', fn () => phpinfo());
Route::get('/admin/users', [AdminController::class, 'index']);
Route::get('/admin/users', [AdminController::class, 'index'])->name('admin.users');
Route::get('/debug/run', function () {
    Artisan::call('migrate', ['--force' => true]);
});
Route::post('/reports', [ReportController::class, 'store'])->middleware('auth');
Route::get('/settings', 'App\\Http\\Controllers\\SettingsController@index');
