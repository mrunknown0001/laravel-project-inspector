<?php

use Illuminate\Support\Facades\Route;

Route::get('/login', 'Auth\\LoginController@create')->middleware('guest')->name('login');
Route::post('/login', 'Auth\\LoginController@store')->middleware('guest');
Route::post('/logout', 'Auth\\LoginController@destroy')->middleware('auth')->name('logout');
