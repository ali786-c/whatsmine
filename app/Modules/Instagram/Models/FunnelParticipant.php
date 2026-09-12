<?php

namespace App\Modules\Instagram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FunnelParticipant extends Model
{
    public const STAGE_COMMENTED = 'commented';
    public const STAGE_DM_SENT = 'dm_sent';
    public const STAGE_AWAITING_FOLLOW = 'awaiting_follow';
    public const STAGE_REPLIED = 'replied';
    public const STAGE_DELIVERED = 'delivered';
    public const STAGE_CLOSED = 'closed';
    public const STAGE_EXPIRED = 'expired';

    protected $fillable = [
        'workspace_id', 'instagram_account_id', 'automation_id', 'commenter_igsid',
        'username', 'comment_id', 'media_id', 'media_product_type', 'stage',
        'private_reply_message_id', 'dm_thread_opened_at', 'nudge_count',
        'delivered_at', 'closed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'dm_thread_opened_at' => 'datetime',
            'delivered_at' => 'datetime',
            'closed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class, 'instagram_account_id');
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(CommentAutomation::class, 'automation_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CommentAutomationLog::class, 'funnel_participant_id');
    }

    /** True while a private reply is still permitted by Meta's 7-day rule. */
    public function privateReplyWindowOpen(): bool
    {
        return $this->expires_at === null || now()->lessThan($this->expires_at);
    }

    /** True while the 24h follow-up window (opened by the commenter's reply) is open. */
    public function followUpWindowOpen(): bool
    {
        return $this->dm_thread_opened_at !== null
            && now()->lessThan($this->dm_thread_opened_at->copy()->addDay());
    }
}
