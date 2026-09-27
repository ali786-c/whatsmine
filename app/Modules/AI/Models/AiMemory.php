<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiMemory extends Model
{
    public const MAX_MEMORIES = 200;

    public const MAX_CONTENT_CHARS = 300;

    protected $table = 'ai_memories';

    protected $fillable = [
        'workspace_id', 'chatbot_id', 'kind', 'content', 'usefulness',
        'use_count', 'last_used_at', 'source_conversation_id', 'source',
    ];

    protected function casts(): array
    {
        return [
            'usefulness' => 'integer',
            'use_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }
}
