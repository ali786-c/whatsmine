<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappQR\Models\WhatsappQRSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security tests for the QR (Baileys) webhook HMAC authentication,
 * the inbound-media SSRF guard, upload allowlists and media path
 * randomisation covered by the production hardening pass.
 */
class QrMediaSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ─── QR webhook HMAC (Critical) ────────────────────────────────────────

    public function test_qr_webhook_rejects_missing_signature_in_production(): void
    {
        $session = $this->makeQrSession();

        $this->production();

        $this->postJson('/webhooks/qr/'.$session->session_id, [
            'messages' => [['id' => 'MSG1', 'from' => '923001234567@s.whatsapp.net', 'body' => 'hi']],
        ])->assertStatus(401);

        $this->assertEquals(0, Message::count());
    }

    public function test_qr_webhook_rejects_invalid_signature_in_production(): void
    {
        $session = $this->makeQrSession();

        $this->production();

        $this->postJson('/webhooks/qr/'.$session->session_id, [
            'messages' => [['id' => 'MSG1', 'from' => '923001234567@s.whatsapp.net', 'body' => 'hi']],
        ], $this->headers('sha256='.str_repeat('0', 64), (string) time()))
            ->assertStatus(401);

        $this->assertEquals(0, Message::count());
    }

    public function test_qr_webhook_rejects_stale_timestamp_even_with_valid_signature(): void
    {
        $session = $this->makeQrSession();

        $this->production();

        $payload = json_encode(['messages' => []]);
        $stale = (string) (time() - 3600);
        $sig = 'sha256='.hash_hmac('sha256', $payload, $session->webhook_secret);

        $this->postJson('/webhooks/qr/'.$session->session_id, json_decode($payload, true), $this->headers($sig, $stale))
            ->assertStatus(401);
    }

    public function test_qr_webhook_accepts_valid_signature(): void
    {
        [$session, $ctx] = $this->makeQrSessionWithAccount();
        $payload = [
            'messages' => [[
                'id' => 'MSG_OK_1',
                'from' => '923001234567@s.whatsapp.net',
                'body' => 'hello from whatsapp',
                'type' => 'text',
                'timestamp' => time(),
            ]],
        ];
        $raw = json_encode($payload);

        $this->production();
        $sig = 'sha256='.hash_hmac('sha256', $raw, $session->webhook_secret);

        $this->postJson('/webhooks/qr/'.$session->session_id, $payload, $this->headers($sig, (string) time()))
            ->assertStatus(200)
            ->assertJson(['status' => 'ok', 'processed' => 1]);

        $this->assertDatabaseHas('messages', ['provider_message_id' => 'MSG_OK_1', 'channel' => 'whatsapp_qr']);
    }

    public function test_qr_webhook_sync_status_requires_signature_in_production(): void
    {
        $session = $this->makeQrSession('disconnected');

        $this->production();

        $this->postJson('/webhooks/qr/'.$session->session_id.'/sync-status', [
            'status' => 'logged_out',
            'phone_number' => '+923001234567',
        ])->assertStatus(401);

        $this->assertDatabaseMissing('whatsapp_qr_sessions', [
            'id' => $session->id,
            'status' => 'logged_out',
        ]);
    }

    public function test_legacy_session_without_secret_is_rejected_in_production(): void
    {
        $session = $this->makeQrSession();
        $session->forceFill(['webhook_secret' => null])->save();

        $this->production();

        $this->postJson('/webhooks/qr/'.$session->session_id, ['messages' => []])->assertStatus(401);
    }

    public function test_legacy_session_gets_secret_provisioned_on_first_valid_request(): void
    {
        $session = $this->makeQrSession();
        $session->forceFill(['webhook_secret' => null])->save();

        // Non-production: unsigned request is allowed (warn-only) and the
        // session is upgraded with a fresh per-session secret.
        $this->postJson('/webhooks/qr/'.$session->session_id, ['messages' => []])->assertStatus(200);

        $session->refresh();
        $this->assertNotNull($session->webhook_secret);
        $this->assertSame(64, strlen($session->webhook_secret));
    }

    // ─── serveMedia SSRF + enumeration (Critical + High) ──────────────────

    public function test_serve_media_blocks_internal_metadata_url(): void
    {
        $ctx = $this->createWorkspaceContext();
        [$conversation, $message] = $this->makeQrMedia($ctx, [
            'preview_url' => 'http://169.254.169.254/latest/meta-data/',
        ]);

        $this->actingAs($ctx['user'])
            ->get("/app/inbox/conversations/{$conversation->uuid}/messages/{$message->id}/media")
            ->assertStatus(502);
    }

    public function test_serve_media_blocks_private_range_url(): void
    {
        $ctx = $this->createWorkspaceContext();
        [$conversation, $message] = $this->makeQrMedia($ctx, [
            'preview_url' => 'http://10.1.2.3/secret-file',
        ]);

        $this->actingAs($ctx['user'])
            ->get("/app/inbox/conversations/{$conversation->uuid}/messages/{$message->id}/media")
            ->assertStatus(502);
    }

    public function test_serve_media_serves_cached_file_with_safe_mime_and_random_name(): void
    {
        $ctx = $this->createWorkspaceContext();
        Storage::fake('public');

        [$conversation, $message] = $this->makeQrMedia($ctx, [
            'stored_path' => 'message-media/'.Str::random(40).'.png',
            'mime_type' => 'image/png',
        ], 'fake-png-bytes');

        $storedPath = $message->payload['stored_path'];
        // Never the legacy sequential name message-media/{id}.{ext}
        $this->assertDoesNotMatchRegularExpression(
            '/^message-media\/'.preg_quote((string) $message->id, '/').'\./',
            $storedPath
        );

        $this->actingAs($ctx['user'])
            ->get("/app/inbox/conversations/{$conversation->uuid}/messages/{$message->id}/media")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_serve_media_downloads_dangerous_extensions_instead_of_inline(): void
    {
        $ctx = $this->createWorkspaceContext();
        Storage::fake('public');

        [$conversation, $message] = $this->makeQrMedia($ctx, [
            'stored_path' => 'message-media/'.Str::random(40).'.html',
            'mime_type' => 'text/html',
        ], '<script>alert(1)</script>');

        $response = $this->actingAs($ctx['user'])
            ->get("/app/inbox/conversations/{$conversation->uuid}/messages/{$message->id}/media");

        $response->assertStatus(200);
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }

    public function test_serve_media_ignores_attacker_supplied_mime_that_disagrees_with_extension(): void
    {
        $ctx = $this->createWorkspaceContext();
        Storage::fake('public');

        // Payload claims text/html but the file is a png — the allowlist must win
        [$conversation, $message] = $this->makeQrMedia($ctx, [
            'stored_path' => 'message-media/'.Str::random(40).'.png',
            'mime_type' => 'text/html',
        ], 'fake-png-bytes');

        $this->actingAs($ctx['user'])
            ->get("/app/inbox/conversations/{$conversation->uuid}/messages/{$message->id}/media")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/png');
    }

    // ─── uploadMedia allowlist (High) ──────────────────────────────────────

    public function test_upload_media_rejects_html_file(): void
    {
        $ctx = $this->createWorkspaceContext();
        [$conversation] = $this->makeQrMedia($ctx, []);

        $this->actingAs($ctx['user'])
            ->postJson("/app/inbox/conversations/{$conversation->uuid}/upload-media", [
                'file' => UploadedFile::fake()->create('evil.html', 10, 'text/html'),
            ])
            ->assertStatus(422);
    }

    public function test_upload_media_rejects_svg_file(): void
    {
        $ctx = $this->createWorkspaceContext();
        [$conversation] = $this->makeQrMedia($ctx, []);

        $this->actingAs($ctx['user'])
            ->postJson("/app/inbox/conversations/{$conversation->uuid}/upload-media", [
                'file' => UploadedFile::fake()->createWithContent(
                    'evil.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
                ),
            ])
            ->assertStatus(422);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function production(): void
    {
        app()->detectEnvironment(fn () => 'production');
    }

    private function headers(string $signature, string $timestamp): array
    {
        return [
            'X-Qr-Signature' => $signature,
            'X-Qr-Timestamp' => $timestamp,
        ];
    }

    private function makeQrSession(string $status = 'active'): WhatsappQRSession
    {
        $ctx = $this->createWorkspaceContext();

        return WhatsappQRSession::create([
            'workspace_id' => $ctx['workspace']->id,
            'user_id' => $ctx['user']->id,
            'session_id' => 'qr_'.Str::random(32),
            'webhook_secret' => Str::random(64),
            'title' => 'Test QR',
            'status' => $status,
        ]);
    }

    /** Session + linked channel account, ready to receive inbound messages. */
    private function makeQrSessionWithAccount(): array
    {
        $ctx = $this->createWorkspaceContext();
        $session = WhatsappQRSession::create([
            'workspace_id' => $ctx['workspace']->id,
            'user_id' => $ctx['user']->id,
            'session_id' => 'qr_'.Str::random(32),
            'webhook_secret' => Str::random(64),
            'title' => 'Test QR',
            'status' => 'active',
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'whatsapp_qr',
            'provider' => 'baileys',
            'type' => 'qr',
            'display_name' => 'Test QR',
            'phone_number_id' => $session->session_id,
            'status' => 'active',
        ]);
        $session->update(['channel_account_id' => $account->id]);

        return [$session, $ctx];
    }

    /**
     * A whatsapp_qr Message whose payload points at the given media URL(s).
     *
     * @return array{0: Conversation, 1: Message}
     */
    private function makeQrMedia(array $ctx, array $payload, ?string $content = null): array
    {
        $account = ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'whatsapp_qr',
            'provider' => 'baileys',
            'type' => 'qr',
            'display_name' => 'Test QR',
            'phone_number_id' => 'qr_test_'.Str::random(8),
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $ctx['workspace']->id,
            'phone_e164' => '+92300'.random_int(1000000, 9999999),
            'source' => 'whatsapp_qr',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp_qr',
            'type' => 'image',
            'body' => '(media)',
            'payload' => $payload,
            'status' => 'delivered',
        ]);

        if ($content !== null && isset($payload['stored_path'])) {
            Storage::disk('public')->put($payload['stored_path'], $content);
        }

        return [$conversation, $message];
    }
}
