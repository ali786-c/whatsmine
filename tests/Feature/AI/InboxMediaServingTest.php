<?php

namespace Tests\Feature\AI;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Inbox\Http\Controllers\InboxController;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inbound WhatsApp media serving.
 *
 * serveMedia previously REDIRECTED to storage->url(), which depends on the
 * /storage symlink (often missing on servers) — images showed as
 * "Image unavailable" in the inbox. It now streams the bytes inline, and
 * only falls back to the Graph API download when nothing is cached.
 */
class InboxMediaServingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cached_media_is_streamed_inline_without_symlink(): void
    {
        Storage::fake('public');

        [$conversation, $message] = $this->makeMessage('whatsapp');
        $disk = app(StorageManager::class)->disk();
        $path = app(StorageManager::class)->prefixedPath("message-media/{$message->id}.jpg");
        $disk->put($path, 'FAKE-JPEG-BYTES');

        // payload.preview_url set (cached marker) but the symlink URL is
        // irrelevant now — the controller must stream the stored bytes.
        $message->update(['payload' => ['preview_url' => '/storage/missing-via-symlink.jpg', 'mime_type' => 'image/jpeg']]);

        $response = $this->actingAs($this->user)->get(
            route('client.inbox.message-media', ['conversation' => $conversation->uuid, 'message' => $message->id]),
        );

        $response->assertOk();
        $this->assertSame('FAKE-JPEG-BYTES', $response->getContent());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function uncached_cloud_media_is_downloaded_via_graph_and_streamed(): void
    {
        Storage::fake('public');
        Http::fake([
            'graph.facebook.com/v20.0/MEDIAID*' => Http::response(['url' => 'https://graph.facebook.com/dl', 'mime_type' => 'image/jpeg'], 200),
            'graph.facebook.com/dl*' => Http::response('GRAPH-JPEG-BYTES', 200),
        ]);

        [$conversation, $message] = $this->makeMessage('whatsapp');
        $message->update(['payload' => ['image' => ['id' => 'MEDIAID']]]);

        $response = $this->actingAs($this->user)->get(
            route('client.inbox.message-media', ['conversation' => $conversation->uuid, 'message' => $message->id]),
        );

        $response->assertOk();
        $this->assertSame('GRAPH-JPEG-BYTES', $response->getContent());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));

        // Cached for the next request with a preview_url + mime_type.
        $this->assertNotNull($message->fresh()->payload['preview_url'] ?? null);
        $this->assertSame('image/jpeg', $message->fresh()->payload['mime_type'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeMessage(string $channel): array
    {
        // CloudApiClient::forWorkspace resolves the WABA via workspace_id.
        \App\Modules\Whatsapp\Models\WhatsappBusinessAccount::create([
            'workspace_id' => 1,
            'waba_id' => 'WABA-MEDIA-1',
            'credentials' => ['system_user_token' => 'TEST-TOKEN'],
            'status' => 'active',
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => 1,
            'channel' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'WA Media',
            'phone_number_id' => 'PN-MEDIA-1',
        ]);
        $contact = Contact::create(['workspace_id' => 1, 'phone_e164' => '+447700900222']);
        $conversation = Conversation::create([
            'workspace_id' => 1,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => '+447700900222',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => $channel,
            'type' => 'image',
            'body' => null,
            'payload' => [],
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        $this->user = \App\Models\User::factory()->create([
            'role' => 'client',
            'email_verified_at' => now(),
            'workspace_id' => 1,
        ]);

        return [$conversation, $message];
    }
}
