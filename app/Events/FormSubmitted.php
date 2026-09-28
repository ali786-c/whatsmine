<?php

namespace App\Events;

use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a WhatsApp Flow (or any structured form response) is completed by
 * a contact. Powers the `form.submitted` automation trigger. Carries the raw
 * response payload so automations can act on the submitted data.
 */
class FormSubmitted
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $responses  structured field → value map
     */
    public function __construct(
        public readonly ?Contact $contact,
        public readonly ?int $conversationId,
        public readonly array $responses,
    ) {}
}
