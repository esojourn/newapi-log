<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
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
    if (session('user_token_name')) {
        return redirect('/usage');
    }
    return view('welcome');
});
Route::post('/', [StatsController::class, 'authenticate'])->middleware('throttle:10,1');
Route::get('/usage', [StatsController::class, 'usage'])->name('user.usage');
Route::get('/usage/logs', [StatsController::class, 'usageLogs'])->name('user.usage.logs');
Route::get('/usage/hourly', [StatsController::class, 'usageHourly'])->name('user.usage.hourly');
Route::post('/signout', function (Request $request) {
    $request->session()->forget(['user_api_key', 'user_token_name']);
    return redirect('/');
})->name('user.signout');

Route::get('/admin/login', [AdminController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login'])->middleware('throttle:5,1');
Route::post('/admin/logout', [AdminController::class, 'logout'])->name('admin.logout');

Route::middleware('admin')->group(function () {
    Route::get('/admin', [StatsController::class, 'dashboard'])->name('admin.dashboard');
    Route::get('/admin/user/{tokenName}', [StatsController::class, 'userDetail'])->name('admin.user.detail');
    Route::get('/admin/user/{tokenName}/logs', [StatsController::class, 'userLogs'])->name('admin.user.logs');
    Route::get('/admin/user/{tokenName}/logs/export', [StatsController::class, 'userLogsExport'])->name('admin.user.logs.export');
    Route::get('/admin/user/{tokenName}/hourly', [StatsController::class, 'userHourly'])->name('admin.user.hourly');
});

Route::get('/user/{apikey}', [StatsController::class, 'publicUserDetail'])->name('user.detail');
Route::get('/user/{apikey}/logs', [StatsController::class, 'publicUserLogs'])->name('user.logs');
Route::get('/user/{apikey}/hourly', [StatsController::class, 'publicUserHourly'])->name('user.hourly');
