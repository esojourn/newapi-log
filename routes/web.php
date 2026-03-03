<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\StatsController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/login', [AdminController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login']);
Route::post('/admin/logout', [AdminController::class, 'logout'])->name('admin.logout');

Route::middleware('admin')->group(function () {
    Route::get('/admin', [StatsController::class, 'dashboard'])->name('admin.dashboard');
    Route::get('/admin/user/{tokenName}', [StatsController::class, 'userDetail'])->name('admin.user.detail');
    Route::get('/admin/user/{tokenName}/logs', [StatsController::class, 'userLogs'])->name('admin.user.logs');
});
