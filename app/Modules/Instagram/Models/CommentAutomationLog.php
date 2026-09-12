<?php

namespace App\Modules\Instagram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentAutomationLog extends Model
{
    public const ACTION_RECEIVED = 'received';
    public const ACTION_IGNORED_OWN_COMMENT = 'ignored_own_comment';
    public const ACTION_IGNORED_REPLY = 'ignored_reply';
    public const ACTION_NO_MATCH = 'no_match';
    public const ACTION_MATCHED = 'matched';
    public const ACTION_MATCHED_INACTIVE = 'matched_inactive';
    public const ACTION_DM_SENT = 'dm_sent';
    public const ACTION_DM_FAILED = 'dm_failed';
    public const ACTION_AWAITING_FOLLOW = 'awaiting_follow';
    public const ACTION_NUDGED = 'nudged';
    public const ACTION_DELIVERED = 'delivered';
    public const ACTION_DELIVERY_FAILED = 'delivery_failed';
    public const ACTION_EXPIRED = 'expired';
    public const ACTION_CLOSED = 'closed';
    public const ACTION_RATE_LIMITED = 'rate_limited';
    public const ACTION_SKIPPED = 'skipped';

    protected $fillable = [
        'workspace_id', 'instagram_account_id', 'automation_id', 'funnel_participant_id',
        'comment_id', 'action', 'stage', 'request_json', 'response_json', 'error',
    ];

    protected function casts(): array
    {
        return [
            'request_json' => 'array',
            'response_json' => 'array',
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

    public function participant(): BelongsTo
    {
        return $this->belongsTo(FunnelParticipant::class, 'funnel_participant_id');
    }
}
