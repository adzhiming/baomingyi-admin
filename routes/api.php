<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::namespace('Api')->group(function () {
    Route::middleware([])->group(function () {
        Route::get('/gettoken', 'IndexController@gettoken');
        Route::post('auth/register', 'IndexController@register');
        Route::post('auth/login', 'IndexController@login');
        Route::post('auth/send-verify-code', 'IndexController@sendVerifyCode');
        Route::post('auth/forget', 'IndexController@forgetPassword');
    });
    Route::middleware(['jwt'])->group(function () {
        Route::get('auth/logout', ['middleware' => ['jwt'], 'uses' => 'AuthController@logout']);
    });
});
