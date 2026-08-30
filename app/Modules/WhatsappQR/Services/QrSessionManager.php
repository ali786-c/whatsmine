<?php

namespace App\Modules\WhatsappQR\Services;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\WhatsappQR\Models\WhatsappQRSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QrSessionManager
{
    private string $nodeBaseUrl;

    public function __construct()
    {
        $this->nodeBaseUrl = rtrim(config('services.whatscrm.url', 'http://localhost:3010'), '/');
    }

    /**
     * Create a new QR session via the Node.js service.
     */
    public function createSession(int $workspaceId, ?int $userId = null, string $title = 'WhatsApp'): WhatsappQRSession
    {
        $sessionId = 'qr_' . Str::random(32);

        // Create the local record first
        $session = WhatsappQRSession::create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'title' => $title,
            'status' => 'generating',
        ]);

        // Call Node.js to create the Baileys session
        try {
            $response = Http::timeout(15)
                ->post("{$this->nodeBaseUrl}/api/qr/laravel/create", [
                    'sessionId' => $sessionId,
                    'title' => $title,
                ]);

            if (! $response->successful()) {
                Log::warning('QR session creation failed on Node.js', [
                    'session_id' => $sessionId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('QR session creation request failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        return $session->fresh();
    }

    /**
     * Poll the QR code for a session. Returns base64 QR image or null.
     */
    public function pollQrCode(WhatsappQRSession $session): ?string
    {
        // First check the local database
        if ($session->qr_code) {
            return $session->qr_code;
        }

        // If not in DB, try to fetch from Node.js
        try {
            $response = Http::timeout(10)
                ->get("{$this->nodeBaseUrl}/api/qr/laravel/qr/{$session->session_id}");

            if ($response->successful()) {
                $qr = $response->json('qr');
                if ($qr) {
                    // Update local record
                    $session->update(['qr_code' => $qr]);

                    return $qr;
                }
            }
        } catch (\Throwable $e) {
            Log::error('QR poll request failed', [
                'session_id' => $session->session_id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Check the status of a QR session from the Node.js service.
     */
    public function checkStatus(WhatsappQRSession $session): string
    {
        try {
            $response = Http::timeout(10)
                ->get("{$this->nodeBaseUrl}/api/qr/laravel/status/{$session->session_id}");

            if ($response->successful()) {
                $status = $response->json('status');
                $phoneNumber = $response->json('phone_number');
                $whatsappJid = $response->json('whatsapp_jid');

                // Update local record
                $updateData = ['status' => $status];

                if ($phoneNumber) {
                    $updateData['phone_number'] = $phoneNumber;
                }
                if ($whatsappJid) {
                    $updateData['whatsapp_jid'] = $whatsappJid;
                }
                if ($status === 'active' && ! $session->connected_at) {
                    $updateData['connected_at'] = now();
                }
                if (in_array($status, ['disconnected', 'logged_out']) && ! $session->disconnected_at) {
                    $updateData['disconnected_at'] = now();
                }

                $session->update($updateData);

                // If active and no channel account exists, create one
                if ($status === 'active' && ! $session->channel_account_id) {
                    $this->createChannelAccount($session, $phoneNumber, $whatsappJid);
                }

                return $status;
            }
        } catch (\Throwable $e) {
            Log::error('QR status check failed', [
                'session_id' => $session->session_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $session->status;
    }

    /**
     * Logout and delete a QR session.
     */
    public function logout(WhatsappQRSession $session): void
    {
        // Call Node.js to logout
        try {
            Http::timeout(10)
                ->post("{$this->nodeBaseUrl}/api/qr/laravel/logout/{$session->session_id}");
        } catch (\Throwable $e) {
            Log::error('QR logout request failed', [
                'session_id' => $session->session_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Update local record
        $session->update([
            'status' => 'logged_out',
            'disconnected_at' => now(),
            'qr_code' => null,
        ]);

        // Deactivate the channel account if linked
        if ($session->channel_account) {
            $session->channel_account->update(['status' => 'inactive']);
        }
    }

    /**
     * Get all active QR sessions for a workspace.
     */
    public function getActiveSessions(int $workspaceId): \Illuminate\Database\Eloquent\Collection
    {
        return WhatsappQRSession::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->with('channelAccount')
            ->get();
    }

    /**
     * Get all QR sessions for a workspace (not logged out).
     */
    public function getSessions(int $workspaceId): \Illuminate\Database\Eloquent\Collection
    {
        return WhatsappQRSession::where('workspace_id', $workspaceId)
            ->where('status', '!=', 'logged_out')
            ->with('channelAccount')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Create a ChannelAccount linked to this QR session.
     */
    private function createChannelAccount(WhatsappQRSession $session, ?string $phoneNumber, ?string $whatsappJid): void
    {
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $session->workspace_id,
            'channel' => 'whatsapp_qr',
            'provider' => 'baileys',
            'type' => 'qr',
            'display_name' => $session->title . ($phoneNumber ? " ({$phoneNumber})" : ''),
            'phone_number_id' => $session->session_id,
            'status' => 'active',
            'meta_json' => [
                'qr_session_id' => $session->id,
                'phone_number' => $phoneNumber,
                'whatsapp_jid' => $whatsappJid,
            ],
        ]);

        $session->update(['channel_account_id' => $channelAccount->id]);
    }
}
