<?php

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;

class WaFlowResponse extends Model
{
    protected $table = 'wa_flow_responses';

    protected $fillable = [
        'workspace_id', 'contact_id', 'conversation_id', 'message_id',
        'flow_name', 'flow_token', 'responses', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'responses' => 'array',
            'raw' => 'array',
        ];
    }
}
