<?php

namespace App\Modules\Instagram\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommentAutomation extends Model
{
    protected $fillable = [
        'workspace_id', 'instagram_account_id', 'name', 'trigger_type',
        'keywords', 'match_mode', 'reply_message', 'follow_gate',
        'follow_prompt_message', 'reply_keyword', 'delivery', 'media_filter',
        'media_ids', 'is_active', 'priority',
        'cta_message', 'cta_button_label', 'gate_message',
        'visit_profile_label', 'confirm_follow_label',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'delivery' => 'array',
            'media_filter' => 'array',
            'media_ids' => 'array',
            'follow_gate' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class, 'instagram_account_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(FunnelParticipant::class, 'automation_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CommentAutomationLog::class, 'automation_id');
    }

    /** Active automations ordered so the first match wins (priority, then newest). */
    public function scopeForMatching(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('priority')
            ->orderByDesc('created_at');
    }

    /** True when the automation is limited to specific posts (media ids). */
    public function isPostScoped(): bool
    {
        return filled($this->media_ids);
    }

    /** Post-scoped automations only apply to their listed media ids; others apply everywhere. */
    public function appliesToMedia(?string $mediaId): bool
    {
        if (! $this->isPostScoped()) {
            return true;
        }

        $ids = array_map(fn ($v) => (string) $v, (array) $this->media_ids);

        return $mediaId !== null && $mediaId !== '' && in_array($mediaId, $ids, true);
    }
}
