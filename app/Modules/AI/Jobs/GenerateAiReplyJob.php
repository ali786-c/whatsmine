<?php

namespace App\Modules\AI\Jobs;

use App\Events\MessageSent;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generates and sends the AI chatbot reply for one inbound message.
 *
 * Runs async so the inbound webhook (and its MessageReceived broadcast)
 * is never blocked by LLM latency: the customer's message shows in the
 * inbox immediately, the bot reply follows as soon as it is ready.
 */
class GenerateAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** No retry — a retried run risks sending the reply twice. */
    public int $tries = 1;

    /** AI calls can be slow; give the LLM room but never hold a worker forever. */
    public int $timeout = 120;

    public function __construct(
        private readonly int $chatbotId,
        private readonly int $messageId,
    ) {}

    public function handle(ChatbotRunner $runner, ChannelManager $channelManager): void
    {
        $message = Message::find($this->messageId);
        $chatbot = AiChatbot::find($this->chatbotId);

        if (! $message || ! $chatbot || ! $chatbot->enabled) {
            return;
        }

        $conversation = $message->conversation;

        // Agent took over while this job sat in the queue — stay silent.
        if (! $conversation || ($conversation->assigned_to ?? 'bot') === 'human') {
            return;
        }

        try {
            $reply = $runner->run($chatbot, $message);
            if ($reply === null || $reply === '') {
                return;
            }

            $botMessage = Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'out',
                'channel'         => $message->channel,
                'type'            => 'text',
                'body'            => $reply,
                'payload'         => [],
                'status'          => 'queued',
                'sent_by'         => 'bot',
                'sent_at'         => now(),
            ]);

            try {
                $driver = $channelManager->driver($message->channel);
                $providerId = $driver->send($botMessage);
                $botMessage->update(['status' => 'sent', 'provider_message_id' => $providerId]);
            } catch (\Throwable $sendErr) {
                $botMessage->update(['status' => 'failed', 'error_json' => ['message' => $sendErr->getMessage()]]);
                Log::warning('GenerateAiReplyJob send failed', [
                    'message_id' => $botMessage->id,
                    'channel'    => $message->channel,
                    'error'      => $sendErr->getMessage(),
                ]);
            }

            $conversation->update(['last_message_at' => now()]);
            $botMessage->load('conversation');
            MessageSent::dispatch($botMessage);
        } catch (\Throwable $e) {
            Log::error('GenerateAiReplyJob AI run failed', [
                'message_id' => $this->messageId,
                'chatbot_id' => $this->chatbotId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
