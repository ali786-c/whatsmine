<?php

namespace App\Modules\WhatsappQR\Http\Controllers;

use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappQR\Models\WhatsappQRSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives inbound WhatsApp messages from the Node.js Baileys service
 * and stores them in Laravel conversation/message tables so they
 * appear in the WhatsMine Inbox.
 */
class QrWebhookController extends Controller
{
    /**
     * POST /webhooks/qr/{sessionId}
     */
    public function receive(Request $request, string $sessionId): JsonResponse
    {
        $payload = $request->all();
        $messages = $payload["messages"] ?? [];
        if (empty($messages)) {
            return response()->json(["status" => "ok"]);
        }
        // Relaxed: accept any non-logged-out session
        $qrSession = WhatsappQRSession::where("session_id", $sessionId)
            ->where("status", "!=", "logged_out")
            ->first();
        if (! $qrSession) {
            Log::warning("QR webhook: session not found", ["session_id" => $sessionId]);
            return response()->json(["status" => "ignored", "reason" => "session_not_found"]);
        }
        $workspaceId = $qrSession->workspace_id;
        // Auto-promote to active when messages arrive
        if ($qrSession->status !== "active") {
            $updateData = ["status" => "active"];
            if (! $qrSession->connected_at) { $updateData["connected_at"] = now(); }
            $qrSession->update($updateData);
            Log::info("QR webhook: auto-promoted to active", ["session_id" => $sessionId]);
        }
        // Ensure channel account linked
        $channelAccount = $qrSession->channelAccount;
        if (! $channelAccount) { $channelAccount = $this->ensureChannelAccount($qrSession); }
        if (! $channelAccount) {
            return response()->json(["status" => "ignored", "reason" => "no_channel_account"]);
        }
        $processed = 0;
        foreach ($messages as $msg) {
            try {
                $this->processMessage($workspaceId, $channelAccount, $msg);
                $processed++;
            } catch (\Throwable $e) {
                Log::error("QR webhook: message processing failed", [
                    "session_id" => $sessionId, "error" => $e->getMessage(),
                ]);
            }
        }
        $qrSession->update(["last_active_at" => now()]);
        Log::info("QR webhook: processed messages", ["session_id" => $sessionId, "processed" => $processed]);
        return response()->json(["status" => "ok", "processed" => $processed]);
    }

    /**
     * POST /webhooks/qr/{sessionId}/sync-status
     */
    public function syncStatus(Request $request, string $sessionId): JsonResponse
    {
        $payload = $request->all();
        $status = $payload["status"] ?? null;
        $phoneNumber = $payload["phone_number"] ?? null;
        $whatsappJid = $payload["whatsapp_jid"] ?? null;
        $qrSession = WhatsappQRSession::where("session_id", $sessionId)
            ->where("status", "!=", "logged_out")->first();
        if (! $qrSession) {
            return response()->json(["status" => "ignored"]);
        }
        $updateData = [];
        if ($status) { $updateData["status"] = $status; }
        if ($phoneNumber) { $updateData["phone_number"] = $phoneNumber; }
        if ($whatsappJid) { $updateData["whatsapp_jid"] = $whatsappJid; }
        if ($status === "active" && ! $qrSession->connected_at) { $updateData["connected_at"] = now(); }
        if (in_array($status, ["disconnected", "logged_out"]) && ! $qrSession->disconnected_at) {
            $updateData["disconnected_at"] = now();
        }
        if (! empty($updateData)) { $qrSession->update($updateData); }
        if ($status === "active" && ! $qrSession->channel_account_id) { $this->ensureChannelAccount($qrSession); }
        Log::info("QR sync-status", ["session_id" => $sessionId, "status" => $status]);
        return response()->json(["status" => "ok"]);
    }

    private function ensureChannelAccount(WhatsappQRSession $session): ?ChannelAccount
    {
        $channelAccount = $session->channelAccount;
        if ($channelAccount) { return $channelAccount; }
        try {
            $phoneNumber = $session->phone_number;
            $whatsappJid = $session->whatsapp_jid;
            $channelAccount = ChannelAccount::create([
                "workspace_id" => $session->workspace_id,
                "channel" => "whatsapp_qr",
                "provider" => "baileys",
                "type" => "qr",
                "display_name" => $session->title . ($phoneNumber ? " (" . $phoneNumber . ")" : ""),
                "phone_number_id" => $session->session_id,
                "status" => "active",
                "meta_json" => [
                    "qr_session_id" => $session->id,
                    "phone_number" => $phoneNumber,
                    "whatsapp_jid" => $whatsappJid,
                ],
            ]);
            $session->update(["channel_account_id" => $channelAccount->id]);
            Log::info("QR webhook: auto-created channel account", [
                "session_id" => $session->session_id,
                "channel_account_id" => $channelAccount->id,
            ]);
            return $channelAccount;
        } catch (\Throwable $e) {
            Log::error("QR webhook: failed to create channel account", [
                "session_id" => $session->session_id,
                "error" => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function processMessage(int $workspaceId, ChannelAccount $channelAccount, array $msg): void
    {
        $from = $msg["from"] ?? "";
        $body = $msg["body"] ?? "";
        $msgType = $msg["type"] ?? "text";
        $providerMessageId = $msg["id"] ?? null;
        $timestamp = $msg["timestamp"] ?? time();
        $senderName = $msg["senderName"] ?? null;
        $fromMe = $msg["fromMe"] ?? false;

        if (! $from || $from === "status@broadcast") { return; }
        if ($providerMessageId) {
            $exists = Message::where("provider_message_id", $providerMessageId)->exists();
            if ($exists) { return; }
        }
        $phoneNumber = preg_replace("/@s\.whatsapp\.net$/", "", $from);
        
        // If the message is fromMe, do NOT use the senderName (which is the account owner's pushName) to resolve/overwrite the contact's name
        $contact = $this->resolveContact($workspaceId, $phoneNumber, $fromMe ? null : $senderName);
        
        $conversation = Conversation::firstOrCreate(
            ["workspace_id" => $workspaceId, "contact_id" => $contact->id, "channel_account_id" => $channelAccount->id],
            ["status" => "open", "external_thread_id" => $from, "last_message_at" => now()]
        );
        $validTypes = ["text", "image", "video", "document", "audio", "location", "reaction"];
        $normalizedType = in_array($msgType, $validTypes, true) ? $msgType : "text";
        $message = Message::create([
            "conversation_id" => $conversation->id,
            "direction" => $fromMe ? "out" : "in",
            "channel" => "whatsapp_qr",
            "type" => $normalizedType,
            "body" => $body ?: "(unsupported message)",
            "payload" => $msg,
            "status" => "delivered",
            "provider_message_id" => $providerMessageId,
            "sent_by" => "human",
            "sent_at" => \Carbon\Carbon::createFromTimestamp($timestamp),
        ]);
        $conversation->update([
            "last_message_at" => $message->sent_at,
            "status" => "open",
            "unread_count" => $fromMe ? 0 : ($conversation->unread_count + 1),
            "last_inbound_at" => $fromMe ? $conversation->last_inbound_at : $message->sent_at,
        ]);

        if ($fromMe) {
            MessageSent::dispatch($message);
        } else {
            MessageReceived::dispatch($message);
        }
    }

    private function resolveContact(int $workspaceId, string $phoneNumber, ?string $senderName = null): Contact
    {
        $phoneE164 = "+" . $phoneNumber;
        $contact = Contact::where("workspace_id", $workspaceId)
            ->where("phone_e164", $phoneE164)->first();
        if (! $contact) {
            $nameParts = $senderName ? explode(" ", $senderName, 2) : [];
            $contact = Contact::create([
                "workspace_id" => $workspaceId,
                "phone_e164" => $phoneE164,
                "first_name" => $nameParts[0] ?? null,
                "last_name" => $nameParts[1] ?? null,
                "source" => "whatsapp_qr",
                "opt_in_whatsapp" => true,
            ]);
            ContactCreated::dispatch($contact);
        } elseif ($senderName && empty($contact->first_name)) {
            $nameParts = explode(" ", $senderName, 2);
            $contact->update(["first_name" => $nameParts[0] ?? null, "last_name" => $nameParts[1] ?? null]);
        }
        return $contact;
    }
}
