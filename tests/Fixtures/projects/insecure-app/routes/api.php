<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::post('/orders/{order}/refund', [OrderController::class, 'refund']);
Route::delete('/users/{id}', [OrderController::class, 'destroy']);
Route::post('/login', [OrderController::class, 'refund']);
