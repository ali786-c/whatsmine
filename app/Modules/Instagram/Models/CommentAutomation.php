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
        'is_active', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'delivery' => 'array',
            'media_filter' => 'array',
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
}
