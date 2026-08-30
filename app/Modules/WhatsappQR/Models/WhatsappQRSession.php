<?php

namespace App\Modules\WhatsappQR\Models;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class WhatsappQRSession extends Model
{
    protected $table = 'whatsapp_qr_sessions';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'user_id',
        'session_id',
        'title',
        'phone_number',
        'whatsapp_jid',
        'status',
        'qr_code',
        'channel_account_id',
        'meta',
        'last_active_at',
        'connected_at',
        'disconnected_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_active_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->session_id)) {
                $model->session_id = 'qr_' . Str::random(32);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ─── Relationships ────────────────────────────────────────────────────

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    // ─── Status Helpers ───────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isWaiting(): bool
    {
        return $this->status === 'waiting_scan';
    }

    public function isGenerating(): bool
    {
        return $this->status === 'generating';
    }

    public function isDisconnected(): bool
    {
        return $this->status === 'disconnected';
    }

    public function isLoggedOut(): bool
    {
        return $this->status === 'logged_out';
    }

    public function isUsable(): bool
    {
        return $this->status === 'active';
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeNotLoggedOut($query)
    {
        return $query->where('status', '!=', 'logged_out');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'active' => 'green',
            'waiting_scan', 'generating' => 'yellow',
            'disconnected' => 'orange',
            'logged_out' => 'red',
            default => 'gray',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'active' => 'Connected',
            'waiting_scan' => 'Waiting for Scan',
            'generating' => 'Generating QR...',
            'disconnected' => 'Disconnected',
            'logged_out' => 'Logged Out',
            default => 'Unknown',
        };
    }
}
