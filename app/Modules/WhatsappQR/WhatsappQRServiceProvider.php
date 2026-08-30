<?php

namespace App\Modules\WhatsappQR;

use App\Modules\Shared\Services\ChannelManager;
use App\Modules\WhatsappQR\Services\QrDriver;
use Illuminate\Support\ServiceProvider;

class WhatsappQRServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Register the QR driver with ChannelManager
        $manager = $this->app->make(ChannelManager::class);
        $manager->register('whatsapp_qr', QrDriver::class);
    }
}
