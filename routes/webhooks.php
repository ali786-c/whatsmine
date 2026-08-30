<?php

use App\Http\Controllers\Webhooks\AutomationWebhookController;
use App\Modules\Broadcasting\Http\Controllers\EmailTrackingController;
use App\Modules\Broadcasting\Http\Controllers\SmsStatusWebhookController;
use App\Modules\Ecommerce\Http\Controllers\EcommerceOAuthController;
use App\Modules\Ecommerce\Http\Controllers\EcommerceWebhookController;
use App\Modules\Inbox\Http\Controllers\MetaWebhookController;
use App\Modules\Whatsapp\Http\Controllers\WhatsappWebhookController;
use App\Modules\WhatsappQR\Http\Controllers\QrWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:webhooks')->group(function () {
    Route::prefix('webhooks/whatsapp')->name('webhooks.whatsapp.')->group(function () {
        Route::get('/global', [WhatsappWebhookController::class, 'verifyGlobal'])->name('global.verify');
        Route::post('/global', [WhatsappWebhookController::class, 'receiveGlobal'])->name('global.receive');
        Route::get('/{token}', [WhatsappWebhookController::class, 'verify'])->name('verify');
        Route::post('/{token}', [WhatsappWebhookController::class, 'receive'])->name('receive');
    });

    Route::prefix('webhooks/meta')->name('webhooks.meta.')->group(function () {
        Route::get('/{token}', [MetaWebhookController::class, 'verify'])->name('verify');
        Route::post('/{token}', [MetaWebhookController::class, 'receive'])->name('receive');
    });

    Route::post('webhooks/sms/{provider}', [SmsStatusWebhookController::class, 'handle'])
        ->name('webhooks.sms.status')
        ->where('provider', 'twilio|nexmo|messagebird|plivo|telnyx|infobip|clicksend|smsbd|reve|bulksmsbd|sms_dot_bd|mimsms|fast2sms');

    Route::post('webhooks/automation/{trigger_token}', [AutomationWebhookController::class, 'receive'])
        ->name('webhooks.automation.receive');

    Route::post('webhooks/ecommerce/shopify/{store}', [EcommerceWebhookController::class, 'shopify'])
        ->name('webhooks.ecommerce.shopify');
    Route::post('webhooks/ecommerce/woocommerce/{store}', [EcommerceWebhookController::class, 'woocommerce'])
        ->name('webhooks.ecommerce.woocommerce');
    Route::post('webhooks/ecommerce/bigcommerce/{store}', [EcommerceWebhookController::class, 'bigcommerce'])
        ->name('webhooks.ecommerce.bigcommerce');

    Route::post('webhooks/ecommerce/woo-auth', [EcommerceOAuthController::class, 'woocommerceCallback'])
        ->name('webhooks.ecommerce.woo_auth');

    Route::get('track/email/{token}/open.gif', [EmailTrackingController::class, 'open'])
        ->name('track.email.open');

    Route::get('track/email/{token}/click', [EmailTrackingController::class, 'click'])
        ->name('track.email.click');

    Route::match(['get', 'post'], 'track/email/{token}/unsubscribe', [EmailTrackingController::class, 'unsubscribe'])
        ->name('track.email.unsubscribe');

    // WhatsApp QR (Baileys) webhook from Node.js
    Route::post('webhooks/qr/{sessionId}/sync-status', [QrWebhookController::class, 'syncStatus'])
        ->name('webhooks.qr.sync-status');
    Route::post('webhooks/qr/{sessionId}', [QrWebhookController::class, 'receive'])
        ->name('webhooks.qr.receive');
});
