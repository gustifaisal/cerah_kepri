<?php

use App\Http\Controllers\AdminController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    Route::get('/login', [AdminController::class, 'loginForm'])->name('admin.login');
    Route::post('/login', [AdminController::class, 'login'])->name('admin.login.submit');

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('admin.index');
        Route::get('/data', [AdminController::class, 'data'])->name('admin.data');
        Route::post('/run', [AdminController::class, 'run'])->name('admin.run');
        Route::post('/news/{id}/toggle', [AdminController::class, 'toggleNews'])->whereNumber('id')->name('admin.news.toggle');
        Route::post('/logout', [AdminController::class, 'logout'])->name('admin.logout');
    });
});
