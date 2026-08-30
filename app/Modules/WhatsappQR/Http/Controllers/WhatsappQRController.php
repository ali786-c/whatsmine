<?php

namespace App\Modules\WhatsappQR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\WhatsappQR\Models\WhatsappQRSession;
use App\Modules\WhatsappQR\Services\QrSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class WhatsappQRController extends Controller
{
    public function __construct(
        private QrSessionManager $qrManager,
    ) {}

    /**
     * List all QR sessions for the workspace.
     */
    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $sessions = WhatsappQRSession::where('workspace_id', $workspaceId)
            ->with('channelAccount:id,display_name,status')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('WhatsappQR/Index', [
            'sessions' => $sessions,
        ]);
    }

    /**
     * Show a single QR session with QR code.
     */
    public function show(Request $request, WhatsappQRSession $session): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $session->workspace_id === (int) $workspaceId, 403);

        $session->load('channelAccount');

        return Inertia::render('WhatsappQR/Show', [
            'session' => $session,
        ]);
    }

    /**
     * Create a new QR session and generate the QR code.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:128'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        try {
            $userId = $request->user()->id;
            $session = $this->qrManager->createSession(
                $workspaceId,
                $userId,
                $validated['label'] ?? 'WhatsApp',
            );

            // Poll the QR code from Node.js
            $qrCode = $this->qrManager->pollQrCode($session);

            return response()->json([
                'success' => true,
                'session' => $session->fresh(),
                'qr_code' => $qrCode,
            ]);
        } catch (\Throwable $e) {
            Log::error('QR session creation failed', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create QR session: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the current QR code for a session (for polling).
     */
    public function qr(Request $request, WhatsappQRSession $session): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $session->workspace_id === (int) $workspaceId, 403);

        try {
            $qrCode = $this->qrManager->pollQrCode($session);

            return response()->json([
                'success' => true,
                'qr_code' => $qrCode,
                'status' => $session->fresh()->status,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to generate QR: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the status of a QR session (for polling).
     */
    public function status(Request $request, WhatsappQRSession $session): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $session->workspace_id === (int) $workspaceId, 403);

        // Check real status from Node.js service (updates DB if status changed)
        $this->qrManager->checkStatus($session);
        $session = $session->fresh();

        return response()->json([
            'success' => true,
            'status' => $session->status,
            'phone_number' => $session->phone_number,
            'connected_at' => $session->connected_at?->toIso8601String(),
            'last_active_at' => $session->last_active_at?->toIso8601String(),
        ]);
    }

    /**
     * Disconnect and delete a QR session.
     */
    public function destroy(Request $request, WhatsappQRSession $session): JsonResponse|RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $session->workspace_id === (int) $workspaceId, 403);

        try {
            // Disconnect from Node.js
            $this->qrManager->logout($session);

            // Delete the channel account if linked
            if ($session->channel_account_id) {
                ChannelAccount::where('id', $session->channel_account_id)->delete();
            }

            // Delete the session
            $session->delete();

            return redirect()->route('client.whatsapp-qr.index')
                ->with('success', 'QR session disconnected and deleted.');
        } catch (\Throwable $e) {
            Log::error('QR session deletion failed', [
                'session_id' => $session->session_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to delete session: ' . $e->getMessage(),
            ], 500);
        }
    }
}
