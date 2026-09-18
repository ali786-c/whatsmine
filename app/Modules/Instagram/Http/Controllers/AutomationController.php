<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Instagram\Models\CommentAutomation;
use App\Modules\Instagram\Models\CommentAutomationLog;
use App\Modules\Instagram\Models\Flow;
use App\Modules\Instagram\Models\InstagramAccount;
use App\Modules\Instagram\Services\InstagramGraphClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);

        $automations = CommentAutomation::where('workspace_id', $workspaceId)
            ->with('account:id,username,ig_user_id')
            ->withCount(['participants as triggered_count'])
            ->withCount(['participants as delivered_count' => fn ($q) => $q->where('stage', 'delivered')])
            ->orderBy('priority')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Instagram/Automations/Index', [
            'automations' => $automations,
            'accountsCount' => InstagramAccount::where('workspace_id', $workspaceId)->where('status', 'active')->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        $form = $this->formProps($request);

        // Quick-start templates: expand ?template=link|file into prefilled values.
        // Everything here is within the same validation rules store() applies,
        // so the user can save immediately or tweak freely.
        $templates = [
            'link' => [
                'name' => 'Link drop',
                'trigger_type' => 'keyword',
                'keywords' => ['link', 'info', 'price'],
                'reply_message' => 'Hey {username}! 👋 Thanks for commenting — here it is:',
                'follow_gate' => false,
                'delivery' => [
                    'type' => 'link',
                    'text' => 'Here it is as promised:',
                    'url' => '',
                    'filename' => '',
                ],
            ],
            'file' => [
                'name' => 'Follow-gated file',
                'trigger_type' => 'all_comments',
                'keywords' => [],
                'reply_message' => 'Hey {username}! 👋 Thanks for commenting!',
                'follow_gate' => true,
                'follow_prompt_message' => '',
                'reply_keyword' => 'DONE',
                'delivery' => [
                    'type' => 'file',
                    'text' => 'Here is your file — enjoy!',
                    'url' => '',
                    'filename' => '',
                ],
            ],
        ];

        $templateKey = (string) $request->query('template', '');
        if (isset($templates[$templateKey])) {
            $form['automation'] = array_merge($form['automation'] ?? [], $templates[$templateKey]);
        }

        return Inertia::render('Instagram/Automations/Edit', $form);
    }

    /**
     * Recent posts/reels of one of the workspace's connected IG accounts —
     * powers the per-post picker in the automation builder.
     *
     * Cursor-paginated: pass ?cursor= from the previous response (next_cursor)
     * to fetch the next page, so accounts with 1000+ posts can walk their feed.
     */
    public function recentPosts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $account = InstagramAccount::where('workspace_id', $this->workspaceId($request))
            ->where('id', (int) $validated['account_id'])
            ->where('status', 'active')
            ->firstOrFail();

        try {
            $result = app(InstagramGraphClient::class)->getRecentMedia(
                $account,
                (int) ($validated['limit'] ?? 12),
                isset($validated['cursor']) ? (string) $validated['cursor'] : null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not fetch posts: '.$e->getMessage()], 502);
        }

        return response()->json([
            'posts' => collect($result['media'])->map(fn ($m) => [
                'id' => (string) ($m['id'] ?? ''),
                'caption' => mb_substr((string) ($m['caption'] ?? ''), 0, 90),
                'type' => (string) ($m['media_product_type'] ?? ''),
                'thumbnail' => $m['thumbnail_url'] ?? $m['media_url'] ?? null,
                'permalink' => (string) ($m['permalink'] ?? ''),
                'timestamp' => (string) ($m['timestamp'] ?? ''),
            ])->values(),
            'next_cursor' => $result['next_cursor'],
        ]);
    }

    public function edit(Request $request, CommentAutomation $automation): Response
    {
        $this->authorizeAccess($request, $automation->workspace_id);

        return Inertia::render('Instagram/Automations/Edit', $this->formProps($request, $automation));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        CommentAutomation::create($data + ['workspace_id' => $this->workspaceId($request)]);

        return redirect()->route('client.instagram.automations.index')
            ->with('flash.success', 'Automation created.');
    }

    public function update(Request $request, CommentAutomation $automation): RedirectResponse
    {
        $this->authorizeAccess($request, $automation->workspace_id);

        $automation->update($this->validated($request));

        return redirect()->route('client.instagram.automations.index')
            ->with('flash.success', 'Automation updated.');
    }

    public function toggle(Request $request, CommentAutomation $automation): RedirectResponse
    {
        $this->authorizeAccess($request, $automation->workspace_id);

        $automation->update(['is_active' => ! $automation->is_active]);

        return redirect()->back()->with('flash.success', $automation->is_active ? 'Automation activated.' : 'Automation paused.');
    }

    public function destroy(Request $request, CommentAutomation $automation): RedirectResponse
    {
        $this->authorizeAccess($request, $automation->workspace_id);

        $automation->delete();

        return redirect()->back()->with('flash.success', 'Automation deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'instagram_account_id' => [
                'required',
                Rule::exists('instagram_accounts', 'id')->where('workspace_id', $this->workspaceId($request)),
            ],
            'name' => ['required', 'string', 'max:120'],
            'trigger_type' => ['required', Rule::in(['all_comments', 'keyword', 'mention_only'])],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:64'],
            'match_mode' => ['required', Rule::in(['contains', 'exact', 'starts_with', 'regex'])],
            'reply_message' => ['required', 'string', 'max:1000'],
            'follow_gate' => ['boolean'],
            'follow_prompt_message' => ['nullable', 'string', 'max:500'],
            'reply_keyword' => ['nullable', 'string', 'max:64'],
            'cta_message' => ['nullable', 'string', 'max:500'],
            'cta_button_label' => ['nullable', 'string', 'max:60'],
            'gate_message' => ['nullable', 'string', 'max:500'],
            'visit_profile_label' => ['nullable', 'string', 'max:60'],
            'confirm_follow_label' => ['nullable', 'string', 'max:60'],
            'delivery' => ['nullable', 'array'],
            'delivery.type' => ['nullable', Rule::in(['text', 'link', 'file', 'flow'])],
            'delivery.flow_id' => ['nullable', 'integer', Rule::exists('instagram_flows', 'id')->where('workspace_id', $this->workspaceId($request))],
            'delivery.text' => ['nullable', 'string', 'max:900'],
            'delivery.url' => ['nullable', 'url', 'max:2048'],
            'delivery.filename' => ['nullable', 'string', 'max:120'],
            'media_filter' => ['nullable', 'array'],
            'media_filter.*' => [Rule::in(['POST', 'REEL', 'STORY', 'AD'])],
            'media_ids' => ['nullable', 'array', 'max:100'],
            'media_ids.*' => ['string', 'max:64'],
            'is_active' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        // A flow delivery can only ever start AFTER the user replies (the flow
        // hands off through LeadDeliveryService during the gated loop), so a
        // flow delivery without the follow ask would silently never fire.
        if (($data['delivery']['type'] ?? null) === 'flow') {
            if (! $request->boolean('follow_gate')) {
                throw ValidationException::withMessages([
                    'delivery.type' => 'A DM flow needs the follow ask turned on — the flow starts only after they reply.',
                ]);
            }

            if (blank($data['delivery']['flow_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'delivery.flow_id' => 'Pick which DM flow should run.',
                ]);
            }
        }

        return $data;
    }

    private function formProps(Request $request, ?CommentAutomation $automation = null): array
    {
        $workspaceId = $this->workspaceId($request);

        // Saved DM flows (draft + active) power the "A DM flow" delivery option
        // in the wizard; drafts are listed too so a user can save the automation
        // first and activate the flow later.
        $flows = Flow::forWorkspace($workspaceId)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'status'])
            ->map(fn (Flow $flow) => ['id' => $flow->id, 'name' => $flow->name.($flow->status === 'draft' ? ' (draft)' : '')])
            ->all();

        return [
            'automation' => $automation?->only([
                'id', 'instagram_account_id', 'name', 'trigger_type', 'keywords', 'match_mode',
                'reply_message', 'follow_gate', 'follow_prompt_message', 'reply_keyword',
                'delivery', 'media_filter', 'media_ids', 'is_active', 'priority',
            ]),
            'flows' => $flows,
            'accounts' => InstagramAccount::where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->get(['id', 'username', 'ig_user_id', 'status']),
        ];
    }

    private function authorizeAccess(Request $request, int $workspaceId): void
    {
        abort_unless((int) $workspaceId === $this->workspaceId($request), 403);
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
