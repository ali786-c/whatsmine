<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WorkspaceSetting;
use App\Modules\AI\Models\AiMemory;
use App\Modules\AI\Services\AiMemoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-level "AI Memory" management: view, add and remove the learned
 * facts the bot injects into every reply, and toggle background learning.
 */
class AiMemoryController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $service = app(AiMemoryService::class);

        $memories = AiMemory::query()
            ->where('workspace_id', $wid)
            ->orderByDesc('usefulness')
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('AI/Memories/Index', [
            'memories' => $memories->map(fn (AiMemory $m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'content' => $m->content,
                'usefulness' => $m->usefulness,
                'use_count' => $m->use_count,
                'source' => $m->source,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
            'learningEnabled' => $service->learningEnabled($wid),
            'maxMemories' => AiMemory::MAX_MEMORIES,
            'extractEvery' => AiMemoryService::EXTRACTION_EVERY,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'kind' => ['required', 'in:fact,preference,policy,order_info,business'],
            'content' => ['required', 'string', 'max:'.AiMemory::MAX_CONTENT_CHARS],
        ]);

        AiMemory::create([
            'workspace_id' => $wid,
            'kind' => $validated['kind'],
            'content' => trim($validated['content']),
            'usefulness' => 3, // manually-taught facts start trusted
            'source' => 'manual',
        ]);

        return back()->with('success', 'Memory added.');
    }

    public function destroy(Request $request, AiMemory $memory): RedirectResponse
    {
        abort_unless($memory->workspace_id === $this->workspaceId($request), 403);

        $memory->delete();

        return back()->with('success', 'Memory removed.');
    }

    public function toggle(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $service = app(AiMemoryService::class);

        $next = ! $service->learningEnabled($wid);
        WorkspaceSetting::set($wid, 'ai_learning_enabled', $next ? '1' : '0');

        return back()->with('success', $next ? 'AI learning enabled.' : 'AI learning disabled.');
    }
}
