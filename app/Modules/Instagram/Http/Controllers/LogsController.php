<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Instagram\Models\CommentAutomationLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogsController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        $logs = CommentAutomationLog::query()
            ->where('workspace_id', $workspaceId)
            ->when($request->string('action'), fn ($q, $v) => $q->where('action', (string) $v))
            ->when($request->string('comment_id'), fn ($q, $v) => $q->where('comment_id', (string) $v))
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            ->map(fn (CommentAutomationLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'stage' => $log->stage,
                'comment_id' => $log->comment_id,
                'automation_id' => $log->automation_id,
                'automation_name' => $log->automation?->name,
                'error' => $log->error,
                'request_json' => $log->request_json,
                'response_json' => $log->response_json,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Instagram/Logs/Index', [
            'logs' => $logs,
            'filters' => ['action' => (string) $request->string('action'), 'comment_id' => (string) $request->string('comment_id')],
        ]);
    }
}
