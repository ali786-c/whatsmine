<?php

namespace App\Modules\Instagram\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowParticipant extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'instagram_flow_participants';

    protected $fillable = [
        'workspace_id', 'flow_id', 'instagram_account_id', 'commenter_igsid', 'username',
        'comment_id', 'media_id', 'current_node_id', 'waiting_for', 'context',
        'wait_started_at', 'dm_thread_opened_at', 'retry_count', 'status', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'wait_started_at' => 'datetime',
            'dm_thread_opened_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class, 'flow_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class, 'instagram_account_id');
    }

    /** Meta's 24h customer window (opened by the participant's first reply). */
    public function windowOpen(): bool
    {
        return $this->dm_thread_opened_at !== null
            && $this->dm_thread_opened_at->copy()->addDay()->isFuture();
    }

    /**
     * Record an inbound user message, opening the 24h window on first reply.
     * $matchText is the keyword-comparable payload (flow postbacks strip their
     * "FLOW:auto:" prefix); defaults to the display text.
     */
    public function markInbound(string $text, ?string $matchText = null): void
    {
        if ($this->dm_thread_opened_at === null) {
            $this->forceFill(['dm_thread_opened_at' => now()])->save();
        }

        $context = (array) $this->context;
        $context['last_inbound_text'] = $text;
        $context['last_match_text'] = $matchText ?? $text;
        $this->forceFill(['context' => $context])->save();
    }
}
