<?php

namespace App\Events;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after one recipient successfully receives a campaign message.
 * Powers the `campaign.sent` automation trigger (fires per contact).
 */
class CampaignMessageSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Campaign $campaign,
        public readonly Contact $contact,
    ) {}
}
