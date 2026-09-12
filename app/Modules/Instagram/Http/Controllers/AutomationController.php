<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Instagram\Models\CommentAutomation;
use App\Modules\Instagram\Models\CommentAutomationLog;
use App\Modules\Instagram\Models\InstagramAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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
        return Inertia::render('Instagram/Automations/Edit', $this->formProps($request));
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
            'delivery' => ['nullable', 'array'],
            'delivery.type' => ['nullable', Rule::in(['text', 'link', 'file'])],
            'delivery.text' => ['nullable', 'string', 'max:900'],
            'delivery.url' => ['nullable', 'url', 'max:2048'],
            'delivery.filename' => ['nullable', 'string', 'max:120'],
            'media_filter' => ['nullable', 'array'],
            'media_filter.*' => [Rule::in(['POST', 'REEL', 'STORY', 'AD'])],
            'is_active' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
    }

    private function formProps(Request $request, ?CommentAutomation $automation = null): array
    {
        $workspaceId = $this->workspaceId($request);

        return [
            'automation' => $automation?->only([
                'id', 'instagram_account_id', 'name', 'trigger_type', 'keywords', 'match_mode',
                'reply_message', 'follow_gate', 'follow_prompt_message', 'reply_keyword',
                'delivery', 'media_filter', 'is_active', 'priority',
            ]),
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
