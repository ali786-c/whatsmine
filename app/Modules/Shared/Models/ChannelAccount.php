<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChannelAccount extends Model
{
    protected $fillable = [
        'workspace_id', 'channel', 'provider', 'type', 'credentials',
        'display_name', 'phone_number_id', 'business_account_id', 'status', 'meta_json',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'meta_json' => 'array',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get the QR session linked to this channel account (if any).
     */
    public function qrSession(): HasOne
    {
        return $this->hasOne(\App\Modules\WhatsappQR\Models\WhatsappQRSession::class);
    }

    /**
     * Whether this channel account was connected via QR (Baileys) rather than Meta Cloud API.
     */
    public function isQrType(): bool
    {
        return ($this->type ?? 'cloud_api') === 'qr';
    }

    /**
     * Whether this channel account was connected via Meta Cloud API.
     */
    public function isCloudApiType(): bool
    {
        return ($this->type ?? 'cloud_api') === 'cloud_api';
    }
}
