<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Instagram\Models\Flow;
use App\Modules\Instagram\Models\InstagramAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Visual DM flow builder (playground) — CRUD for node/edge graphs under the
 * Instagram menu. Graph shape validation happens server-side so a malformed
 * canvas can never be saved into an executable flow.
 */
class FlowController extends Controller
{
    private const NODE_TYPES = ['trigger', 'send_message', 'wait_reply', 'condition', 'end'];

    private const CONDITION_HANDLES = ['yes', 'no', 'match', 'default'];

    public function index(Request $request): \Inertia\Response
    {
        $flows = Flow::forWorkspace($this->workspaceId($request))
            ->withCount('participants')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Flow $flow) => [
                'id' => $flow->id,
                'name' => $flow->name,
                'status' => $flow->status,
                'account' => $flow->account?->only(['id', 'username']),
                'node_count' => count($flow->nodes()),
                'participants_count' => $flow->participants_count,
                'updated_at' => $flow->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('Instagram/Flows/Index', [
            'flows' => $flows,
        ]);
    }

    public function create(Request $request): \Inertia\Response
    {
        return Inertia::render('Instagram/Flows/Builder', [
            'flow' => null,
            'templates' => $this->templates($request),
            'accounts' => $this->accounts($request),
        ]);
    }

    public function edit(Request $request, Flow $flow): \Inertia\Response
    {
        $this->authorizeFlow($request, $flow);

        return Inertia::render('Instagram/Flows/Builder', [
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
                'status' => $flow->status,
                'graph' => $flow->graph,
            ],
            'templates' => $this->templates($request),
            'accounts' => $this->accounts($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $flow = Flow::create($data + [
            'workspace_id' => $this->workspaceId($request),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['id' => $flow->id, 'status' => $flow->status], 201);
    }

    public function update(Request $request, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($request, $flow);

        $flow->update($this->validated($request));

        return response()->json(['id' => $flow->id, 'status' => $flow->status]);
    }

    public function toggle(Request $request, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($request, $flow);

        if ($flow->status !== 'active' && $flow->triggerNode() === null) {
            throw ValidationException::withMessages([
                'graph' => 'Add a Trigger node before activating this flow.',
            ]);
        }

        $flow->forceFill(['status' => $flow->status === 'active' ? 'draft' : 'active'])->save();

        return response()->json(['status' => $flow->status]);
    }

    public function destroy(Request $request, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($request, $flow);

        $flow->delete();

        return response()->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------

    private function authorizeFlow(Request $request, Flow $flow): void
    {
        abort_unless((int) $flow->workspace_id === $this->workspaceId($request), 403);
    }

    /** @return array<int, array{id: int, name: string, type: string, definition: array}> */
    private function templates(Request $request): array
    {
        return \App\Modules\Instagram\Models\InstagramTemplate::forWorkspace($this->workspaceId($request))
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'type', 'definition'])
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'type' => $t->type, 'definition' => $t->definition])
            ->all();
    }

    /** @return array<int, array{id: int, username: ?string}> */
    private function accounts(Request $request): array
    {
        return InstagramAccount::query()
            ->where('workspace_id', $this->workspaceId($request))
            ->where('status', 'active')
            ->get(['id', 'username'])
            ->map(fn ($a) => ['id' => $a->id, 'username' => $a->username])
            ->all();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['nullable', 'in:draft,active'],
            'instagram_account_id' => ['nullable', 'integer'],
            'graph' => ['required', 'array'],
            'graph.nodes' => ['required', 'array', 'max:50'],
            'graph.edges' => ['required', 'array'],
        ]);

        $this->validateGraph((array) $data['graph']);

        return [
            'name' => $data['name'],
            'status' => $data['status'] ?? 'draft',
            'instagram_account_id' => $data['instagram_account_id'] ?? null,
            'graph' => $data['graph'],
        ];
    }

    /**
     * Structural graph validation — node whitelist, exactly one trigger, edges
     * reference existing nodes, condition nodes have payload keys.
     *
     * @param  array<string, mixed>  $graph
     */
    private function validateGraph(array $graph): void
    {
        $nodes = array_values(array_filter((array) ($graph['nodes'] ?? []), fn ($n) => is_array($n) && isset($n['id'], $n['type'])));
        $ids = array_map(fn (array $n) => (string) $n['id'], $nodes);

        if (count($nodes) > 50) {
            throw ValidationException::withMessages(['graph' => 'A flow can have at most 50 nodes.']);
        }

        $triggers = array_filter($nodes, fn (array $n) => ($n['type'] ?? '') === 'trigger');

        if (count($triggers) !== 1) {
            throw ValidationException::withMessages(['graph' => 'A flow needs exactly one Trigger node.']);
        }

        foreach ($nodes as $node) {
            if (! in_array((string) $node['type'], self::NODE_TYPES, true)) {
                throw ValidationException::withMessages(['graph' => 'Unknown node type: '.(string) $node['type']]);
            }

            $data = (array) ($node['data'] ?? []);

            if ((string) $node['type'] === 'send_message') {
                $kind = (string) ($data['kind'] ?? 'text');

                if ($kind === 'template' && (int) ($data['template_id'] ?? 0) <= 0 && empty($data['definition'])) {
                    throw ValidationException::withMessages(['graph' => 'A template message node needs a saved template or an inline definition.']);
                }

                if ($kind !== 'template' && trim((string) ($data['text'] ?? '')) === '') {
                    throw ValidationException::withMessages(['graph' => 'Every message node needs text (or switch it to a template).']);
                }
            }

            if ((string) $node['type'] === 'condition') {
                $condType = (string) ($data['cond_type'] ?? 'follow_check');

                if (! in_array($condType, ['follow_check', 'keyword'], true)) {
                    throw ValidationException::withMessages(['graph' => 'Unknown condition type.']);
                }

                if ($condType === 'keyword' && array_filter(array_map('trim', (array) ($data['keywords'] ?? []))) === []) {
                    throw ValidationException::withMessages(['graph' => 'Keyword conditions need at least one keyword.']);
                }
            }
        }

        foreach ((array) ($graph['edges'] ?? []) as $edge) {
            if (! is_array($edge) || ! isset($edge['source'], $edge['target'])) {
                continue;
            }

            if (! in_array((string) $edge['source'], $ids, true) || ! in_array((string) $edge['target'], $ids, true)) {
                throw ValidationException::withMessages(['graph' => 'An edge points to a deleted node — reconnect it on the canvas.']);
            }

            $source = null;

            foreach ($nodes as $node) {
                if ((string) $node['id'] === (string) $edge['source']) {
                    $source = $node;
                    break;
                }
            }

            if ($source !== null && ($source['type'] ?? '') === 'condition') {
                $handle = (string) ($edge['sourceHandle'] ?? '');
                $normalized = str_starts_with($handle, 'cond-') ? substr($handle, 5) : $handle;

                if (! in_array($normalized, self::CONDITION_HANDLES, true)) {
                    throw ValidationException::withMessages(['graph' => 'Condition nodes connect via YES/NO (or MATCH/DEFAULT) handles only.']);
                }
            }
        }
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
