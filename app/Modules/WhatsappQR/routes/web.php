<?php

use App\Modules\WhatsappQR\Http\Controllers\WhatsappQRController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/whatsapp-qr')->name('client.whatsapp-qr.')->group(function () {
    Route::get('/', [WhatsappQRController::class, 'index'])->name('index');
    Route::get('/{session}', [WhatsappQRController::class, 'show'])->name('show');
    Route::post('/', [WhatsappQRController::class, 'store'])->name('store');
    Route::get('/{session}/qr', [WhatsappQRController::class, 'qr'])->name('qr');
    Route::get('/{session}/status', [WhatsappQRController::class, 'status'])->name('status');
    Route::delete('/{session}', [WhatsappQRController::class, 'destroy'])->name('destroy');
});
