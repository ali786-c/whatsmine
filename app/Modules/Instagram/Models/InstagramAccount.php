<?php

namespace App\Modules\Instagram\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstagramAccount extends Model
{
    protected $fillable = [
        'workspace_id', 'ig_user_id', 'username', 'display_name',
        'page_id', 'page_token', 'status', 'meta_json',
    ];

    protected $hidden = ['page_token'];

    protected function casts(): array
    {
        return [
            'page_token' => 'encrypted',
            'meta_json' => 'array',
        ];
    }

    public function automations(): HasMany
    {
        return $this->hasMany(CommentAutomation::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(FunnelParticipant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Mark the token as expired (UI shows a reconnect banner). */
    public function markTokenExpired(): void
    {
        if ($this->status !== 'token_expired') {
            $this->forceFill(['status' => 'token_expired'])->save();
        }
    }
}
