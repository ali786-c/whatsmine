<?php

namespace App\Console\Commands;

use App\Modules\Instagram\Models\CommentAutomationLog;
use App\Modules\Instagram\Models\FunnelParticipant;
use Illuminate\Console\Command;

/**
 * Live funnel diagnosis: `php artisan instagram:funnel`
 *
 * Answers "Done ke baad next msg kyun nahi gaya?" in one shot — shows each
 * participant's stage/window/nudges and the recent funnel actions. Reminds the
 * operator that Meta does not webhook self-messages (owner testing the flow
 * from the account that owns it sees nothing).
 */
class InstagramFunnelCommand extends Command
{
    protected $signature = 'instagram:funnel {--limit=10 : How many participants to show}';

    protected $description = 'Show live funnel participant states + recent automation actions (DM flow diagnosis)';

    public function handle(): int
    {
        $this->info('=== Instagram funnel diagnosis — '.now()->format('Y-m-d H:i:s').' ===');

        $participants = FunnelParticipant::query()
            ->with('account:id,username')
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($participants->isEmpty()) {
            $this->line('[ -- ] No funnel participants yet.');
        } else {
            $this->line('');
            $this->line('--- Funnel participants (newest first) ---');
            foreach ($participants as $p) {
                $window = $p->dm_thread_opened_at
                    ? ($p->followUpWindowOpen() ? 'open' : 'ELAPSED')
                    : 'not-opened';
                $this->line(sprintf(
                    ' #%d %s @%s stage=%s window=%s nudges=%d comment=%s %s',
                    $p->id,
                    $p->account?->username ?? 'ws#'.$p->workspace_id,
                    $p->username ?? '?',
                    $p->stage,
                    $window,
                    $p->nudge_count,
                    substr((string) $p->comment_id, 0, 12),
                    $p->status === 'expired' || $p->closed_at !== null ? '(closed/expired)' : '',
                ));
            }
        }

        $logs = CommentAutomationLog::query()->orderByDesc('id')->limit(15)->get();

        $this->line('');
        $this->line('--- Last '.count($logs).' automation actions ---');
        foreach ($logs as $log) {
            $this->line(sprintf(
                ' [%s] #%d action=%s stage=%s%s%s',
                $log->created_at?->format('m-d H:i'),
                $log->id,
                $log->action,
                $log->stage ?? '-',
                $log->error !== null ? ' error="'.mb_substr($log->error, 0, 90).'"' : '',
                $log->automation_id !== null ? ' automation='.$log->automation_id : '',
            ));
        }

        $this->line('');
        $this->warn('Reminder: Meta does NOT webhook messages an account sends to ITSELF.');
        $this->line('Testing the "Done" reply from the owner account will never trigger the funnel —');
        $this->line('use a second Instagram account that commented on the post.');

        return self::SUCCESS;
    }
}
