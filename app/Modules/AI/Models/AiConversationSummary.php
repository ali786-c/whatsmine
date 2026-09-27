<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversationSummary extends Model
{
    /** Soft cap on the stored summary text (chars). */
    public const MAX_CHARS = 1400;

    protected $table = 'ai_conversation_summaries';

    protected $fillable = [
        'conversation_id', 'workspace_id', 'summary',
        'last_summarized_message_id', 'message_count',
    ];

    protected function casts(): array
    {
        return [
            'message_count' => 'integer',
            'last_summarized_message_id' => 'integer',
        ];
    }
}
