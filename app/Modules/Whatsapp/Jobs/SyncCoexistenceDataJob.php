<?php

namespace App\Modules\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncCoexistenceDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $phoneNumberId,
        private readonly string $accessToken
    ) {}

    public function handle(): void
    {
        Log::info('Initiating coexistence data sync', [
            'phone_number_id' => $this->phoneNumberId,
            'workspace_id' => $this->workspaceId
        ]);

        $baseUrl = 'https://graph.facebook.com/v20.0/' . $this->phoneNumberId . '/smb_app_data';

        // 1. Sync Contacts
        $contactResp = Http::withToken($this->accessToken)->post($baseUrl, [
            'messaging_product' => 'whatsapp',
            'sync_type' => 'smb_app_state_sync'
        ]);

        if (!$contactResp->successful()) {
            Log::error('Failed to init contact sync', ['response' => $contactResp->json()]);
        }

        // 2. Sync History
        $historyResp = Http::withToken($this->accessToken)->post($baseUrl, [
            'messaging_product' => 'whatsapp',
            'sync_type' => 'history'
        ]);

        if (!$historyResp->successful()) {
            Log::error('Failed to init history sync', ['response' => $historyResp->json()]);
        }
    }
}
