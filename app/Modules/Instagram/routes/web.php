<?php

use App\Modules\Instagram\Http\Controllers\AutomationController;
use App\Modules\Instagram\Http\Controllers\ConnectController;
use App\Modules\Instagram\Http\Controllers\LogsController;
use Illuminate\Support\Facades\Route;

Route::get('/setup', [ConnectController::class, 'index'])->name('setup');
Route::get('/setup/status', [ConnectController::class, 'status'])->name('setup.status');
Route::post('/setup/connect', [ConnectController::class, 'connect'])->name('connect');
Route::delete('/setup/{instagramAccount}', [ConnectController::class, 'disconnect'])->name('disconnect');

Route::get('/automations', [AutomationController::class, 'index'])->name('automations.index');
Route::get('/automations/create', [AutomationController::class, 'create'])->name('automations.create');
Route::get('/automations/recent-posts', [AutomationController::class, 'recentPosts'])->name('automations.recent-posts');
Route::get('/automations/{automation}/edit', [AutomationController::class, 'edit'])->name('automations.edit');
Route::post('/automations', [AutomationController::class, 'store'])->name('automations.store');
Route::put('/automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
Route::patch('/automations/{automation}/toggle', [AutomationController::class, 'toggle'])->name('automations.toggle');
Route::delete('/automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');

Route::get('/logs', [LogsController::class, 'index'])->name('logs.index');
