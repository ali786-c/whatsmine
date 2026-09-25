<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SecureHeaders previously sent `Permissions-Policy: microphone=()`, which
 * makes Chrome deny every same-origin getUserMedia instantly (NotAllowedError,
 * no permission prompt) regardless of the user's site settings — breaking
 * inbox voice-note recording. The header must be `microphone=(self)`.
 */
class SecureHeadersTest extends TestCase
{
    #[Test]
    public function microphone_is_allowed_for_same_origin(): void
    {
        $response = $this->get('/');

        $policy = (string) $response->headers->get('Permissions-Policy');

        $this->assertStringContainsString('microphone=(self)', $policy);
        $this->assertStringNotContainsString('microphone=()', $policy);
    }
}
