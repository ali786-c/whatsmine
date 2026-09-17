<?php

use App\Modules\Instagram\Http\Controllers\AutomationController;
use App\Modules\Instagram\Http\Controllers\ConnectController;
use App\Modules\Instagram\Http\Controllers\LogsController;
use App\Modules\Instagram\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

// Connection lives in ONE place: the Inbox Channels page (Inbox → Setup).
// The module Setup page is a read-only manage view; the IG-Login OAuth
// redirect-back target is kept here so a flow that started on THIS page
// resolves cleanly.
Route::get('/setup', [ConnectController::class, 'index'])->name('setup');
Route::get('/setup/connect-instagram-login', [ConnectController::class, 'connectInstagramLogin'])->name('connect-instagram-login');
Route::get('/setup/status', [ConnectController::class, 'status'])->name('setup.status');

Route::get('/automations', [AutomationController::class, 'index'])->name('automations.index');
Route::get('/automations/create', [AutomationController::class, 'create'])->name('automations.create');
Route::get('/automations/recent-posts', [AutomationController::class, 'recentPosts'])->name('automations.recent-posts');
Route::get('/automations/{automation}/edit', [AutomationController::class, 'edit'])->name('automations.edit');
Route::post('/automations', [AutomationController::class, 'store'])->name('automations.store');
Route::put('/automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
Route::patch('/automations/{automation}/toggle', [AutomationController::class, 'toggle'])->name('automations.toggle');
Route::delete('/automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');

Route::get('/logs', [LogsController::class, 'index'])->name('logs.index');

// Saved message templates (generic carousel / button) for the Inbox composer
// plus a full-page gallery/editor under the Instagram menu.
Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
Route::get('/templates/gallery', [TemplateController::class, 'gallery'])->name('templates.gallery');
Route::get('/templates/create', [TemplateController::class, 'create'])->name('templates.create');
Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
