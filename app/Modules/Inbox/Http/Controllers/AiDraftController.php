<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI draft suggestion for the inbox composer.
 *
 * The agent clicks "AI draft" → the conversation's last turns are replayed to
 * the workspace chatbot (ChatbotRunner::runForApi, the same pipeline the
 * playground uses: KB grounding, product context, emoji intelligence) and the
 * suggested reply is returned as plain text. The agent edits it and sends it
 * through the normal reply flow — the draft is NEVER auto-sent.
 */
class AiDraftController extends Controller
{
    /** How many recent messages form the draft's context window. */
    private const HISTORY_MESSAGES = 10;

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);

        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        $bot = $this->resolveBot($conversation, $workspaceId);
        if (! $bot) {
            return response()->json([
                'error' => 'No AI chatbot configured — assign one in Inbox → Setup.',
            ], 422);
        }

        // Latest customer message is what the draft should answer.
        $lastInbound = $conversation->messages()
            ->where('direction', 'in')
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderByDesc('sent_at')
            ->first();

        if (! $lastInbound) {
            return response()->json([
                'error' => 'No customer message to draft a reply for yet.',
            ], 422);
        }

        // Build sanitized history the runner understands (same shape the
        // playground sends): user = customer turns, assistant = our replies.
        $history = $conversation->messages()
            ->whereIn('type', ['text', 'template'])
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->where('id', '!=', $lastInbound->id)
            ->orderByDesc('sent_at')
            ->take(self::HISTORY_MESSAGES)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $m) => [
                'role' => $m->direction === 'in' ? 'user' : 'assistant',
                'content' => mb_substr((string) $m->body, 0, ChatbotRunner::HISTORY_MAX_CHARS),
            ])
            ->all();

        try {
            $result = app(ChatbotRunner::class)->runForApi(
                $bot,
                mb_substr((string) $lastInbound->body, 0, 1000),
                $workspaceId,
                $history,
            );
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'AI draft failed: '.mb_substr($e->getMessage(), 0, 160),
            ], 502);
        }

        $draft = trim((string) ($result['reply'] ?? ''));
        if ($draft === '') {
            return response()->json(['error' => 'AI returned an empty draft.'], 502);
        }

        return response()->json([
            'draft' => $draft,
            'meta' => [
                'bot' => $bot->name,
                'tokens_used' => (int) ($result['tokens_used'] ?? 0),
            ],
        ]);
    }

    /**
     * Bot resolution — the same precedence the auto-reply path uses:
     * the bot linked to the conversation's channel account wins, otherwise
     * the workspace's first enabled bot is used.
     */
    private function resolveBot(Conversation $conversation, int $workspaceId): ?AiChatbot
    {
        $linkedId = $conversation->channelAccount?->meta_json['ai_chatbot_id'] ?? null;

        if ($linkedId) {
            $linked = AiChatbot::find($linkedId);
            if ($linked && $linked->enabled && (int) $linked->workspace_id === $workspaceId) {
                return $linked;
            }
        }

        return AiChatbot::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->orderBy('id')
            ->first();
    }

    private function authorise(Request $request, Conversation $conversation): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $conversation->workspace_id === (int) $workspaceId, 403);
    }
}
