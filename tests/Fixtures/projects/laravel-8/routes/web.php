<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

Route::resource('photos', PhotoController::class)->only(['index', 'show', 'store']);

Route::get('/legacy', 'LegacyController@show')->name('legacy');

Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'can:admin'], 'as' => 'admin.'], function () {
    Route::get('/reports', [App\Http\Controllers\Admin\ReportController::class, 'index'])->name('reports');
});

require __DIR__.'/auth.php';
