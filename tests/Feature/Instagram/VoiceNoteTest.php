<?php

namespace Tests\Feature\Instagram;

use App\Modules\Inbox\Services\InstagramDriver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Instagram voice notes: the composer records Chrome webm, but Instagram
 * Messaging accepts only aac/m4a/wav/mp4 audio attachments referenced by
 * public URL (no WhatsApp-style media-id upload). These tests pin the chain:
 * transcode+storage in the controller, URL-based send in the driver, inbound
 * attachment mapping, and StorageManager::publicUrl().
 */
class VoiceNoteTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    /**
     * Build an Instagram channel account + conversation for the workspace.
     */
    private function makeConversation(): Conversation
    {
        $account = ChannelAccount::create([
            'workspace_id'  => $this->ctx['workspace']->id,
            'channel'       => 'instagram',
            'provider'      => 'meta',
            'status'        => 'active',
            'display_name'  => 'IG Test',
            'credentials'   => ['access_token' => 'IG-TOKEN', 'instagram_account_id' => 'IG-ACC-1'],
            'meta_json'     => [
                'instagram_page_id'    => 'IG-ACC-1',
                'instagram_account_id' => 'IG-ACC-1',
                'auth_type'            => 'instagram_login',
            ],
        ]);

        $contact = Contact::create([
            'workspace_id'  => $this->ctx['workspace']->id,
            'source'        => 'instagram',
            'first_name'    => 'Zainab',
            'custom_fields' => ['instagram_psid' => 'PSID-1'],
        ]);

        return Conversation::create([
            'workspace_id'       => $this->ctx['workspace']->id,
            'channel_account_id' => $account->id,
            'contact_id'         => $contact->id,
            'status'             => 'open',
            'external_thread_id' => 'PSID-1',
        ]);
    }

    #[Test]
    public function driver_sends_audio_as_url_attachment(): void
    {
        $conversation = $this->makeConversation();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'out',
            'channel'         => 'instagram',
            'type'            => 'audio',
            'body'            => 'voice-note-1.m4a',
            'payload'         => [
                'preview_url' => 'https://cdn.example.com/ig-voice-1.m4a',
                'mime_type'   => 'audio/mp4',
            ],
            'status'          => 'queued',
            'sent_by'         => 'human',
            'sent_at'         => now(),
        ]);

        // Instagram-Login connections talk to graph.instagram.com. Only the
        // primary attempt is asserted; an audio message must NOT fall through
        // to the plain-text branch.
        Http::fake([
            'graph.instagram.com/*' => Http::response(['message_id' => 'IGMID-1'], 200),
        ]);

        $providerId = app(InstagramDriver::class)->send($message);

        $this->assertSame('IGMID-1', $providerId);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://graph.instagram.com/v20.0/IG-ACC-1/messages'
                && ($body['message']['attachment']['type'] ?? null) === 'audio'
                && ($body['message']['attachment']['payload']['url'] ?? null) === 'https://cdn.example.com/ig-voice-1.m4a';
        });
    }

    #[Test]
    public function driver_throws_clear_error_when_audio_has_no_url(): void
    {
        $conversation = $this->makeConversation();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'out',
            'channel'         => 'instagram',
            'type'            => 'audio',
            'body'            => 'voice-note.m4a',
            'payload'         => [],
            'status'          => 'queued',
            'sent_by'         => 'human',
            'sent_at'         => now(),
        ]);

        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no public audio URL');

        app(InstagramDriver::class)->send($message);
    }

    #[Test]
    public function composer_reply_fails_fast_when_transcode_unavailable(): void
    {
        $conversation = $this->makeConversation();

        Http::fake(); // no external calls expected on the Instagram path

        $response = $this->actingAs($this->ctx['user'])->post(
            route('client.inbox.reply', $conversation),
            [
                'type'       => 'audio',
                'attachment' => UploadedFile::fake()->createWithContent(
                    'voice-note.webm',
                    str_repeat('FAKE-WEBM', 64),
                ),
            ],
            ['Accept' => 'application/json'],
        );

        // ffmpeg is not installed on dev machines: the controller must fail
        // fast with a precise 422 (not 500, not a silent webm upload).
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Instagram needs m4a/aac', (string) $response->json('error'));

        $this->assertDatabaseCount('messages', 0);
    }

    #[Test]
    public function composer_reply_accepts_m4a_and_builds_public_url_payload(): void
    {
        $conversation = $this->makeConversation();

        Http::fake();

        $response = $this->actingAs($this->ctx['user'])->post(
            route('client.inbox.reply', $conversation),
            [
                'type'       => 'audio',
                'attachment' => UploadedFile::fake()->createWithContent('voice-note.m4a', str_repeat('M4A', 64)),
            ],
            ['Accept' => 'application/json'],
        );

        $this->assertSame(200, $response->status());

        $message = Message::query()->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame('audio', $message->type);

        $payload = $message->payload;
        $this->assertNotEmpty($payload['preview_url'] ?? null);
        $this->assertStringStartsWith('http', $payload['preview_url']);
        $this->assertSame('audio/mp4', $payload['mime_type']);
        $this->assertArrayNotHasKey('media_id', $payload);
    }

    #[Test]
    public function inbound_audio_attachment_is_recorded_as_audio_message(): void
    {
        $this->makeConversation();

        // Sender profile fetch fails silently in the driver — no fake needed,
        // the contact is still created from the PSID.
        Http::fake(['graph.facebook.com/*' => Http::response([], 403)]);

        app(InstagramDriver::class)->processWebhookPayload([
            'object' => 'instagram',
            'entry'  => [[
                'id'        => 'IG-ACC-1',
                'time'      => now()->timestamp * 1000,
                'messaging' => [[
                    'sender'    => ['id' => 'PSID-1'],
                    'recipient' => ['id' => 'IG-ACC-1'],
                    'timestamp' => now()->timestamp * 1000,
                    'message'   => [
                        'mid'         => 'MID-AUDIO-'.uniqid(),
                        'attachments' => [[
                            'type'    => 'audio',
                            'payload' => ['url' => 'https://cdn.fakeig.net/audio.m4a'],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $message = Message::query()->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame('in', $message->direction);
        $this->assertSame('audio', $message->type);
        $this->assertSame('https://cdn.fakeig.net/audio.m4a', $message->payload['audio']['preview_url'] ?? null);
    }

    #[Test]
    public function storage_manager_builds_absolute_public_url_for_local_disk(): void
    {
        config(['app.url' => 'https://wa.example.com']);
        config(['filesystems.disks.testing_public' => [
            'driver' => 'local',
            'root'   => storage_path('framework/testing/disks'),
            'url'    => env('APP_URL').'/storage',
        ]]);

        $sm = app(\App\Services\StorageManager::class);

        // Force-resolve onto the plain local public disk: its url() yields a
        // relative /storage/... path, which publicUrl() must make absolute
        // using the configured APP URL.
        $sm->clearCache();
        $url = $sm->publicUrl('message-media/ig-voice-1.m4a');

        $this->assertStringStartsWith('http', $url);
        $this->assertStringContainsString('ig-voice-1.m4a', $url);
    }
}
