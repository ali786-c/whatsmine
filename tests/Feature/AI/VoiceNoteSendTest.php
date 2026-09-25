<?php

namespace Tests\Feature\AI;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Voice-note upload must never 500 when exec functions are unavailable.
 *
 * transcodeAudioToOgg() previously called shell_exec() directly; on hardened
 * hosts (aaPanel default) shell_exec is in disable_functions and the call
 * throws a fatal \Error — a 500 on every Chrome voice-note send. It now uses
 * guarded process launching with a graceful "no transcode" fallback, so the
 * send path always completes (the driver surfaces a clear error if Meta
 * rejects the original mime).
 */
class VoiceNoteSendTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    #[Test]
    public function webm_voice_note_send_does_not_crash_when_transcode_is_unavailable(): void
    {
        $workspaceId = $this->ctx['workspace']->id;

        $waba = WhatsappBusinessAccount::create([
            'workspace_id' => $workspaceId,
            'waba_id' => 'WABA-TEST-1',
            'credentials' => ['system_user_token' => 'TEST-TOKEN'],
            'status' => 'active',
        ]);
        WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => 'PN123',
            'display_phone' => '+447700900000',
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'WA Test',
            'phone_number_id' => 'PN123',
            'credentials' => ['access_token' => 'TEST-TOKEN'],
        ]);
        $contact = Contact::create(['workspace_id' => $workspaceId, 'phone_e164' => '+447700900111']);
        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => '+447700900111',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['id' => 'MEDIA123'], 200) // uploadMedia
                ->push(['messages' => [['id' => 'wamid.VOICE1']]], 200), // send
        ]);

        $response = $this->actingAs($this->ctx['user'])->post(
            route('client.inbox.reply', $conversation),
            [
                'type' => 'audio',
                'attachment' => UploadedFile::fake()->createWithContent(
                    'voice-note.webm',
                    str_repeat('FAKE-WEBM', 64),
                ),
            ],
            ['Accept' => 'application/json'],
        );

        // Before the fix this was a 500 (Call to undefined function
        // shell_exec()). It must now complete the send flow regardless of
        // whether ffmpeg/transcode is available on the machine.
        $this->assertNotSame(500, $response->status());
    }
}
