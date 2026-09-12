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
        $retried = $this->retryStrandedPrivateReplies();
        $expired = $this->expireUnsentPrivateReplies() + $this->closeStaleFollowUps();

        if ($retried + $expired > 0) {
            Log::info('instagram_module: funnel timeouts processed', [
                'retried' => $retried,
                'expired_or_closed' => $expired,
            ]);
        }

        return $retried + $expired;
    }

    /**
     * Self-healing: participants whose private reply never went out (queue
     * retries exhausted while Meta was down) get one more attempt every sweep
     * as long as the 7-day window is still open. Ordered oldest first so the
     * oldest comments — closest to expiry — are served before the limit cuts.
     */
    private function retryStrandedPrivateReplies(): int
    {
        // Heal participants frozen in "dm_sent" — the reply went out but the
        // worker died before the stage advanced. Resume per the gate setting.
        $stuck = FunnelParticipant::query()
            ->where('stage', FunnelParticipant::STAGE_DM_SENT)
            ->where('updated_at', '<', now()->subMinutes(10))
            ->get();

        foreach ($stuck as $participant) {
            $gated = $participant->automation?->follow_gate ?? false;
            $participant->forceFill($gated
                ? ['stage' => FunnelParticipant::STAGE_AWAITING_FOLLOW]
                : ['stage' => FunnelParticipant::STAGE_DELIVERED, 'delivered_at' => now()],
            )->save();
        }

        $stranded = FunnelParticipant::query()
            ->where('stage', FunnelParticipant::STAGE_COMMENTED)
            ->whereNull('private_reply_message_id')
            ->whereNotNull('automation_id')
            ->where('expires_at', '>', now())
            ->where('updated_at', '<', now()->subMinutes(10))
            ->orderBy('expires_at')
            ->limit(25)
            ->get();

        foreach ($stranded as $participant) {
            try {
                // Re-renders the reply and advances the stage on success.
                app(CommentFunnelService::class)->resumePrivateReply($participant);
            } catch (\Throwable $e) {
                // Still throttled / Meta still down — the next sweep retries.
                Log::warning('instagram_module: stranded private-reply retry failed', [
                    'participant_id' => $participant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stranded->count();
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
