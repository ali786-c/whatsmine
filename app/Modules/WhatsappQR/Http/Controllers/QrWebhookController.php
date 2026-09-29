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
use Illuminate\Support\Str;

/**
 * Receives inbound WhatsApp messages from the Node.js Baileys service
 * and stores them in Laravel conversation/message tables so they
 * appear in the WhatsMine Inbox.
 *
 * Every POST from the Node service must be HMAC-signed:
 *   X-Qr-Signature: sha256=<hex of HMAC_SHA256(rawBody, secret)>
 *   X-Qr-Timestamp: <unix seconds, ±5 min window>
 *
 * The secret is per-session (webhook_secret, generated at session creation
 * and handed to the Node service) or the shared WHATSCRM_WEBHOOK_SECRET.
 * Production fails closed on missing/invalid signatures; other environments
 * log a warning and continue so old Node builds keep working in dev.
 */
class QrWebhookController extends Controller
{
    /** Replay window for X-Qr-Timestamp (seconds). */
    private const TIMESTAMP_TOLERANCE = 300;

    /**
     * POST /webhooks/qr/{sessionId}
     */
    public function receive(Request $request, string $sessionId): JsonResponse
    {
        $qrSession = WhatsappQRSession::where("session_id", $sessionId)
            ->where("status", "!=", "logged_out")
            ->first();
        if (! $qrSession) {
            Log::warning("QR webhook: session not found", ["session_id" => $sessionId]);
            return response()->json(["status" => "ignored", "reason" => "session_not_found"]);
        }

        // Reject unsigned/invalid callers before touching any state
        $authFailure = $this->verifyQrSignature($request, $qrSession);
        if ($authFailure !== null) {
            return $authFailure;
        }

        $payload = $request->all();
        $messages = $payload["messages"] ?? [];
        if (empty($messages)) {
            return response()->json(["status" => "ok"]);
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
        $qrSession = WhatsappQRSession::where("session_id", $sessionId)
            ->where("status", "!=", "logged_out")->first();
        if (! $qrSession) {
            return response()->json(["status" => "ignored"]);
        }

        $authFailure = $this->verifyQrSignature($request, $qrSession);
        if ($authFailure !== null) {
            return $authFailure;
        }

        $payload = $request->all();
        $status = $payload["status"] ?? null;
        $phoneNumber = $payload["phone_number"] ?? null;
        $whatsappJid = $payload["whatsapp_jid"] ?? null;
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

    /**
     * Verify the HMAC signature + timestamp of an inbound QR webhook call.
     *
     * Returns null when the request is allowed, or a 401 JsonResponse when
     * it must be rejected (production only — non-production environments are
     * logged and allowed through so legacy Node builds keep working in dev).
     */
    private function verifyQrSignature(Request $request, WhatsappQRSession $qrSession): ?JsonResponse
    {
        $signature = (string) $request->header("X-Qr-Signature", "");
        $timestamp = (string) $request->header("X-Qr-Timestamp", "");
        $secret = $qrSession->webhook_secret ?: (string) config("services.whatscrm.webhook_secret");
        $isProduction = app()->environment("production");

        // Legacy session with no secret at all
        if ($secret === "") {
            if ($isProduction) {
                Log::warning("QR webhook rejected: no webhook secret (legacy session)", [
                    "session_id" => $qrSession->session_id,
                ]);
                return response()->json(["status" => "error", "reason" => "missing_webhook_secret"], 401);
            }

            // One-time upgrade: provision a per-session secret on first contact
            $qrSession->ensureWebhookSecret();
            Log::warning("QR webhook accepted without signature in non-production (legacy session upgraded)", [
                "session_id" => $qrSession->session_id,
            ]);

            return null;
        }

        // No signature header
        if ($signature === "") {
            if ($isProduction) {
                Log::warning("QR webhook rejected: missing X-Qr-Signature", [
                    "session_id" => $qrSession->session_id,
                ]);
                return response()->json(["status" => "error", "reason" => "missing_signature"], 401);
            }
            Log::warning("QR webhook accepted WITHOUT signature in non-production — update the Node.js service", [
                "session_id" => $qrSession->session_id,
            ]);

            return null;
        }

        // Signature present — verify timestamp freshness first
        if ($timestamp === "" || abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            Log::warning("QR webhook: stale or missing X-Qr-Timestamp", [
                "session_id" => $qrSession->session_id, "timestamp" => $timestamp,
            ]);
            if ($isProduction) {
                return response()->json(["status" => "error", "reason" => "stale_timestamp"], 401);
            }

            return null;
        }

        $expected = "sha256=" . hash_hmac("sha256", $request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            Log::warning("QR webhook: invalid signature", ["session_id" => $qrSession->session_id]);
            if ($isProduction) {
                return response()->json(["status" => "error", "reason" => "invalid_signature"], 401);
            }

            return null;
        }

        // Signature is valid — provision a per-session secret for legacy rows
        // that were only covered by the shared secret (one-time upgrade).
        if (empty($qrSession->webhook_secret)) {
            $qrSession->ensureWebhookSecret();
        }

        return null;
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
