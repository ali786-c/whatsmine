<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Instagram\Models\CommentAutomationLog;
use App\Modules\Instagram\Models\FunnelParticipant;
use Illuminate\Support\Facades\Log;

/**
 * Enforces Meta's hard windows:
 *  - participants whose 7-day private-reply window passed without a send → expired;
 *  - participants whose 24h follow-up window (opened by the user's reply) passed
 *    without a delivery → closed.
 */
class FunnelTimeoutService
{
    public function expireStaleParticipants(): int
    {
        $expired = $this->expireUnsentPrivateReplies() + $this->closeStaleFollowUps();

        if ($expired > 0) {
            Log::info('instagram_module: funnel timeouts processed', ['count' => $expired]);
        }

        return $expired;
    }

    private function expireUnsentPrivateReplies(): int
    {
        $stale = FunnelParticipant::query()
            ->whereIn('stage', [FunnelParticipant::STAGE_COMMENTED, FunnelParticipant::STAGE_DM_SENT])
            ->where('expires_at', '<', now())
            ->get();

        foreach ($stale as $participant) {
            $participant->forceFill([
                'stage' => FunnelParticipant::STAGE_EXPIRED,
                'closed_at' => now(),
            ])->save();

            CommentAutomationLog::create([
                'workspace_id' => $participant->workspace_id,
                'instagram_account_id' => $participant->instagram_account_id,
                'automation_id' => $participant->automation_id,
                'funnel_participant_id' => $participant->id,
                'comment_id' => $participant->comment_id,
                'stage' => $participant->stage,
                'action' => CommentAutomationLog::ACTION_EXPIRED,
                'error' => '7-day private reply window elapsed before send',
            ]);
        }

        return $stale->count();
    }

    private function closeStaleFollowUps(): int
    {
        $stale = FunnelParticipant::query()
            ->where('stage', FunnelParticipant::STAGE_AWAITING_FOLLOW)
            ->whereNotNull('dm_thread_opened_at')
            ->where('dm_thread_opened_at', '<', now()->subDay())
            ->get();

        foreach ($stale as $participant) {
            $participant->forceFill([
                'stage' => FunnelParticipant::STAGE_CLOSED,
                'closed_at' => now(),
            ])->save();

            CommentAutomationLog::create([
                'workspace_id' => $participant->workspace_id,
                'instagram_account_id' => $participant->instagram_account_id,
                'automation_id' => $participant->automation_id,
                'funnel_participant_id' => $participant->id,
                'comment_id' => $participant->comment_id,
                'stage' => $participant->stage,
                'action' => CommentAutomationLog::ACTION_CLOSED,
                'error' => '24h follow-up window elapsed before delivery',
            ]);
        }

        return $stale->count();
    }
}
