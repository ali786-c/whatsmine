<?php

namespace App\Modules\AI\Services;

use App\Models\SystemSetting;
use App\Modules\AI\Services\Llm\DeadUpstreamGuard;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\OmniRouteProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * One-shot AI pipeline diagnostic.
 *
 * Answers, with evidence: "Why is the bot replying with garbage?"
 *
 * Checks, in order:
 *  1. Which OmniRoute settings are actually stored in the DB (and is it enabled).
 *  2. Whether the deployed code contains the dead-upstream guards (older code
 *     on the VPS happily forwards canned greetings — the #1 hidden cause).
 *  3. Raw gateway chat call for the configured default model — the exact JSON
 *     body the bot would receive, printed verbatim.
 *  4. The same call with auto/chat for comparison.
 *  5. Guard verdict on each raw reply.
 *  6. A final diagnosis string naming the root cause.
 */
class AiDiagnostic
{
    /** Strings that must exist in this build for the dead-upstream defense to be active. */
    private const CODE_MARKERS = [
        'DeadUpstreamGuard::inspect' => 'dead-upstream guard wired in OmniRouteProvider',
        'canned_greeting_loop' => 'greeting-loop detection reason string',
        'llm.dead_upstream_retry' => 'gateway auto-retry log marker',
    ];

    private const CANARY = 'What products do you offer? Answer in one short sentence.';

    public function run(): array
    {
        $settings = $this->settings();
        $code = $this->codeMarkers();
        $probes = $this->probes($settings);
        $workspaceLayer = $this->workspaceLayer();
        $kbLayer = $this->kbLayer();

        if ($this->diagnosis === null) {
            $this->diagnosis = $this->defaultDiagnosis($code, $probes, $workspaceLayer, $kbLayer);
        }

        return [
            'settings' => $settings,
            'code' => $code,
            'probes' => $probes,
            'workspace' => $workspaceLayer,
            'kb' => $kbLayer,
            'diagnosis' => $this->diagnosis,
        ];
    }

    private ?string $diagnosis = null;

    // ── 1. Stored settings ────────────────────────────────────────────────

    private function settings(): array
    {
        $key = trim((string) SystemSetting::get(LlmManager::OMNIROUTE_KEYS['api_key'], ''));
        $baseUrl = rtrim(trim((string) SystemSetting::get(LlmManager::OMNIROUTE_KEYS['base_url'], '')), '/');
        $model = trim((string) SystemSetting::get(LlmManager::OMNIROUTE_KEYS['model'], '')) ?: 'auto/chat';

        return [
            'enabled' => SystemSetting::get(LlmManager::OMNIROUTE_KEYS['enabled'], 'false') === 'true',
            'base_url' => $baseUrl,
            'api_key_set' => $key !== '',
            'api_key_preview' => $key !== '' ? Str::substr($key, 0, 8).'…'.Str::substr($key, -4) : null,
            'default_model' => $model,
            'model_is_recommended_combo' => str_starts_with($model, 'auto/'),
        ];
    }

    // ── 2. Deployed-code markers ──────────────────────────────────────────

    private function codeMarkers(): array
    {
        $omniProviderFile = (string) @file_get_contents(__DIR__.'/Llm/OmniRouteProvider.php');
        $gatewayFile = (string) @file_get_contents(__DIR__.'/LlmGateway.php');
        $guardFile = (string) @file_get_contents(__DIR__.'/Llm/DeadUpstreamGuard.php');

        $markers = [];
        foreach (self::CODE_MARKERS as $needle => $label) {
            $markers[$needle] = [
                'label' => $label,
                'present' => str_contains($omniProviderFile.$gatewayFile.$guardFile, $needle),
            ];
        }

        $allPresent = collect($markers)->every(fn ($m) => $m['present']);

        return [
            'defense_active' => $allPresent,
            'note' => $allPresent
                ? 'Deployed code contains the dead-upstream guards.'
                : 'DEPLOYED CODE IS OLD — dead-upstream guards are missing. Run: git pull && php artisan queue:restart',
            'markers' => $markers,
        ];
    }

    // ── 3. Live gateway probes ────────────────────────────────────────────

    private function probes(array $settings): array
    {
        if (! $settings['enabled']) {
            $this->diagnose('OmniRoute is DISABLED in settings. The bot is falling back to another provider or failing.');

            return [];
        }

        if ($settings['base_url'] === '' || ! $settings['api_key_set']) {
            $this->diagnose('OmniRoute base URL or API key is missing in settings.');

            return [];
        }

        $key = trim((string) SystemSetting::get(LlmManager::OMNIROUTE_KEYS['api_key'], ''));
        $probes = [];

        // Probe the configured default model first.
        $probes[] = $this->probe($key, $settings['base_url'], $settings['default_model'], 'configured default model');

        // Always probe auto/chat as a healthy baseline comparison.
        if ($settings['default_model'] !== 'auto/chat') {
            $probes[] = $this->probe($key, $settings['base_url'], 'auto/chat', 'auto/chat baseline');
        }

        return $probes;
    }

    /**
     * One raw chat call — stores the exact upstream JSON so nothing is hidden.
     */
    private function probe(string $key, string $baseUrl, string $model, string $label): array
    {
        $started = microtime(true);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are HostingGram support. Answer from the provided context only.'],
                ['role' => 'user', 'content' => self::CANARY.' Context: We offer web hosting, domains and business email.'],
            ],
            'max_tokens' => 200,
            'temperature' => 0.3,
            'stream' => false,
        ];

        try {
            $resp = Http::withToken($key)->timeout(45)->post($baseUrl.'/chat/completions', $payload);
        } catch (\Throwable $e) {
            $this->diagnose("Gateway unreachable for [{$model}]: ".$e->getMessage());

            return ['label' => $label, 'model' => $model, 'ok' => false, 'error' => $e->getMessage()];
        }

        $latency = (int) ((microtime(true) - $started) * 1000);
        $status = $resp->status();
        $body = $resp->body();
        $json = json_decode($body, true);

        $content = $json['choices'][0]['message']['content'] ?? null;
        $upstreamModel = $json['model'] ?? null;
        $guardReason = is_array($json)
            ? DeadUpstreamGuard::inspect($json, $content, self::CANARY)
            : 'not_json_body';

        $ok = $status === 200 && is_string($content) && $guardReason === null;

        if (! $ok && $this->diagnosis === null) {
            if ($guardReason !== null) {
                $this->diagnose(
                    "[{$model}] is a DEAD UPSTREAM (guard: {$guardReason}). The bot sends its canned replies to customers. ".
                    'Fix: Admin → Settings → OmniRoute → Default Model = auto/chat (or another verified live model), then Save and php artisan queue:restart.'
                );
            } elseif ($status !== 200) {
                $this->diagnose(
                    "[{$model}] returned HTTP {$status}. Body preview in probe details. ".
                    'Fix: pick a model that passes the Test Model probe in Admin → Settings → OmniRoute.'
                );
            }
        }

        return [
            'label' => $label,
            'model' => $model,
            'http_status' => $status,
            'latency_ms' => $latency,
            'upstream_model' => $upstreamModel,
            'ok' => $ok,
            'guard_reason' => $guardReason,
            'reply_preview' => $content !== null ? Str::limit(trim((string) $content), 220) : null,
            'raw_body' => Str::limit($body, 900),
        ];
    }

    // ── 3b. Workspace-level provider resolution (what bots ACTUALLY use) ─

    /**
     * Bots never call the gateway directly — they resolve a provider via
     * LlmManager::forWorkspace(), which prefers the workspace's own provider
     * config over the system gateway. A stale workspace config pointing at a
     * dead model would produce garbage while all system probes stay green.
     */
    private function workspaceLayer(): array
    {
        $firstWorkspaceId = \App\Models\Workspace::query()->orderBy('id')->value('id');

        if ($firstWorkspaceId === null) {
            return ['note' => 'No workspaces exist.'];
        }

        $configs = \App\Modules\AI\Models\AiProviderConfig::query()
            ->where('workspace_id', $firstWorkspaceId)
            ->where('enabled', true)
            ->orderBy('id')
            ->get(['id', 'provider', 'default_model_chat', 'enabled']);

        $resolved = null;
        try {
            $provider = LlmManager::forWorkspace($firstWorkspaceId);
            $resolved = [
                'class' => $provider::class,
                'chat_model' => method_exists($provider, 'chat')
                    ? ((new \ReflectionClass($provider))->getProperty('chatModel')->getValue($provider))
                    : null,
            ];
        } catch (\Throwable $e) {
            $resolved = ['error' => $e->getMessage()];
        }

        return [
            'workspace_id_checked' => $firstWorkspaceId,
            'workspace_provider_configs' => $configs->toArray(),
            'resolved_provider' => $resolved,
            'resolved_via_workspace_config' => $configs->isNotEmpty(),
        ];
    }

    // ── 3c. Knowledge-base / RAG layer (why the bot ignores the KB) ──────

    /**
     * "Bot replies generic / ignores the KB" has exactly four causes, checked here:
     *  1. No KB attached to the bot (ai_kb_id null).
     *  2. KB attached but has zero chunks (document never indexed — usually the
     *     'ai' queue worker is not running on the VPS).
     *  3. Chunks exist but zero embeddings (no embed-capable provider when the
     *     document was indexed) AND the keyword fallback misses the query.
     *  4. Retrieval works but the model drifts anyway (guardrails/model issue).
     *
     * A live retrieval probe replays the exact ChatbotRunner logic so whatever
     * the bot would fetch for the canary question is printed verbatim.
     */
    private function kbLayer(): array
    {
        $bot = \App\Modules\AI\Models\AiChatbot::query()->orderBy('id')->first();

        if ($bot === null) {
            return ['note' => 'No chatbots exist.'];
        }

        $layer = [
            'bot_checked' => ['id' => $bot->id, 'name' => $bot->name],
            'kb_attached' => $bot->ai_kb_id !== null,
        ];

        if ($bot->ai_kb_id === null) {
            $this->diagnose(
                "Bot [{$bot->name}] has NO knowledge base attached. It answers from general knowledge, so it cannot know your company details. ".
                'Fix: Client → AI Chatbots → edit the bot → attach the Knowledge Base, save, then test again.'
            );

            return $layer;
        }

        $kb = \App\Modules\AI\Models\AiKnowledgeBase::find($bot->ai_kb_id);
        $layer['kb_id'] = $bot->ai_kb_id;
        $layer['kb_name'] = $kb?->name;
        $layer['kb_status'] = $kb?->status;

        if ($kb === null) {
            $this->diagnose("Bot [{$bot->name}] points at KB {$bot->ai_kb_id} which no longer exists (deleted KB). Re-attach or recreate the KB.");

            return $layer;
        }

        $totalChunks = \App\Modules\AI\Models\AiKbChunk::where('kb_id', $kb->id)->count();
        $embeddedChunks = \App\Modules\AI\Models\AiKbChunk::where('kb_id', $kb->id)->whereNotNull('embedding')->count();
        $layer['chunks_total'] = $totalChunks;
        $layer['chunks_with_embedding'] = $embeddedChunks;
        $layer['docs'] = \App\Modules\AI\Models\AiKbDocument::where('kb_id', $kb->id)
            ->get(['id', 'title', 'source_type', 'status'])
            ->toArray();

        if ($totalChunks === 0) {
            $this->diagnose(
                "KB [{$kb->name}] has ZERO chunks — the document was never indexed. On the VPS this is almost always the 'ai' queue worker not running. ".
                'Fix: php artisan queue:restart && verify a worker is consuming the ai queue (php artisan queue:failed / supervisor config), then re-save the document in the KB UI to re-dispatch IndexDocumentJob.'
            );

            return $layer;
        }

        // Live retrieval probe — replay ChatbotRunner's exact pipeline.
        $retrieval = $this->kbRetrievalProbe($bot, $kb->id, $embeddedChunks);
        $layer['retrieval_probe'] = $retrieval;

        if (($retrieval['chunks_found'] ?? 0) === 0) {
            if ($embeddedChunks === 0) {
                $this->diagnose(
                    "KB [{$kb->name}] has {$totalChunks} chunks but ZERO embeddings, and the keyword fallback found nothing relevant for the probe query — so the bot's prompt contains no KB context and it answers generically. ".
                    'Fix: configure an embedding-capable provider (OpenAI or Gemini) for the workspace, then re-index the KB document (re-save it in the KB UI).'
                );
            } else {
                $this->diagnose(
                    "KB [{$kb->name}] is attached and embedded, but live retrieval returned NOTHING for the probe query — embeddings exist yet do not match. ".
                    'Fix: check that the KB content actually answers the probe question below, or lower the relevance threshold.'
                );
            }

            return $layer;
        }

        if (! ($retrieval['injected'] ?? false)) {
            $this->diagnose(
                "Retrieval found chunks but they were dropped by the context budget (KbContextTrimmer) — prompt received no KB context. ".
                'Fix: this is a bug/edge case; check chunk sizes in the KB.'
            );
        } elseif ($this->diagnosis === null) {
            $this->diagnose(
                "KB pipeline is healthy for bot [{$bot->name}]: retrieval returned {$retrieval['chunks_found']} chunk(s) and context was injected. ".
                'If the bot STILL ignores the KB in the playground, the cause is model drift — see the probes/workspace layers above (dead upstream or a weak model).'
            );
        }

        return $layer;
    }

    /**
     * Replay the ChatbotRunner retrieval path (embed query → vector search →
     * keyword fallback → context trimmer) for the canary question.
     */
    private function kbRetrievalProbe(\App\Modules\AI\Models\AiChatbot $bot, int $kbId, int $embeddedChunks): array
    {
        $query = self::CANARY;
        $topK = $bot->max_context_chunks ?? 5;

        try {
            $queryEmbedding = [];
            try {
                $embeddings = app(LlmGateway::class)->embed($bot->workspace_id, [$query]);
                $queryEmbedding = $embeddings[0] ?? [];
            } catch (\Throwable) {
                $queryEmbedding = []; // same swallow as ChatbotRunner
            }

            $results = [];
            $via = null;
            if (! empty($queryEmbedding)) {
                $results = app(EmbeddingStore::class)->search($kbId, $queryEmbedding, $topK);
                $via = 'embedding';
            }
            if (empty($results)) {
                $results = app(ChatbotRunner::class)->keywordChunksForDiagnostic($kbId, $query, $topK);
                $via = $via === null ? 'keyword (no query embedding)' : 'keyword (embedding search empty)';
            }

            $kept = app(KbContextTrimmer::class)->fit($results);

            return [
                'query' => $query,
                'query_embedded' => $queryEmbedding !== [],
                'via' => $via,
                'chunks_found' => count($results),
                'chunks_injected' => count($kept),
                'injected' => count($kept) > 0,
                'top_score' => isset($results[0]['score']) ? round((float) $results[0]['score'], 3) : null,
                'preview' => isset($kept[0]['chunk']) ? Str::limit(trim($kept[0]['chunk']->content ?? ''), 200) : null,
            ];
        } catch (\Throwable $e) {
            return ['query' => $query, 'error' => $e->getMessage()];
        }
    }

    // ── 4. Diagnosis ──────────────────────────────────────────────────────

    private function diagnose(string $text): void
    {
        $this->diagnosis = $this->diagnosis ?? $text;
    }

    /** Verdict when nothing else fired: everything healthy, or old code on a healthy gateway. */
    private function defaultDiagnosis(array $code, array $probes, array $workspaceLayer = [], array $kbLayer = []): string
    {
        if ($probes !== [] && collect($probes)->contains(fn ($p) => ($p['ok'] ?? false) === true) && ! $code['defense_active']) {
            return 'Gateway and model are healthy, but the DEPLOYED CODE IS OLD (guards missing). '.
                'Old code forwards canned greetings to customers. Run: git pull && php artisan migrate && php artisan queue:restart';
        }

        if ($probes !== [] && collect($probes)->every(fn ($p) => ($p['ok'] ?? false) === true)) {
            // System layer is green — point at the next layer with specifics.
            $resolved = $workspaceLayer['resolved_provider'] ?? [];
            $chatModel = $resolved['chat_model'] ?? null;
            $viaWorkspace = $workspaceLayer['resolved_via_workspace_config'] ?? false;

            if ($viaWorkspace && $chatModel !== null && ! str_starts_with((string) $chatModel, 'auto/')) {
                return "System gateway is healthy, but workspaces resolve their OWN provider first — currently model [{$chatModel}] from a workspace-level config, which is NOT an auto/* combo and may route to a dead upstream. ".
                    'Fix: Admin/Client AI Providers — either disable the workspace-level provider (so the system gateway serves it) or set its chat model to auto/chat.';
            }

            // System layer green — the KB layer is the next most common culprit
            // for "bot ignores our KB" complaints.
            $kbAttached = $kbLayer['kb_attached'] ?? null;
            $kbChunks = $kbLayer['chunks_total'] ?? null;
            if ($kbAttached === false) {
                return 'Gateway and model are healthy, but the chatbot has NO knowledge base attached — it can only answer from general knowledge. '.
                    'Fix: attach the KB to the bot (AI Chatbots → edit → Knowledge Base), save, then re-run this diagnostic.';
            }
            if (is_int($kbChunks) && $kbChunks === 0) {
                return 'Gateway and model are healthy, but the KB has zero indexed chunks — the indexing job never ran. '.
                    'Fix: ensure the queue worker is running (it processes the ai queue), then re-save the KB document to re-index.';
            }

            return 'All checks passed — gateway reachable, model healthy, guards active, workspace resolution clean, KB retrieval healthy. '.
                'If the playground still misbehaves, send the via-model tag from a bad reply and re-run this diagnostic right after.';
        }

        return 'No probe ran (gateway disabled or credentials missing) — see settings section above.';
    }
}
