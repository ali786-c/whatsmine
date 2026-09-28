<?php

namespace App\Events;

use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a tag is attached to a contact (manual import, ecommerce
 * enrichment, or automation). Powers the `contact.tag_added` automation trigger.
 */
class ContactTagAdded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Contact $contact,
        public readonly string $tagName,
    ) {}
}
