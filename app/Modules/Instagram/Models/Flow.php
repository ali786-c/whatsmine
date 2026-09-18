<?php

namespace App\Modules\Instagram\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flow extends Model
{
    protected $table = 'instagram_flows';

    protected $fillable = [
        'workspace_id', 'instagram_account_id', 'name', 'status', 'graph', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'graph' => 'array',
        ];
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class, 'instagram_account_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(FlowParticipant::class, 'flow_id');
    }

    /** @return array<int, array<string, mixed>> */
    public function nodes(): array
    {
        return array_values(array_filter((array) ($this->graph['nodes'] ?? []), fn ($n) => is_array($n)));
    }

    /** @return array<int, array<string, mixed>> */
    public function edges(): array
    {
        return array_values(array_filter((array) ($this->graph['edges'] ?? []), fn ($e) => is_array($e)));
    }

    /** Node whose type is 'trigger' (exactly one is enforced at save time). */
    public function triggerNode(): ?array
    {
        foreach ($this->nodes() as $node) {
            if (($node['type'] ?? '') === 'trigger') {
                return $node;
            }
        }

        return null;
    }
}
