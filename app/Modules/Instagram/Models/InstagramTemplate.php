<?php

namespace App\Modules\Instagram\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InstagramTemplate extends Model
{
    protected $fillable = [
        'workspace_id', 'name', 'type', 'definition', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }
}
