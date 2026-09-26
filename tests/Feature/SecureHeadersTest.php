<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SecureHeaders previously sent `Permissions-Policy: microphone=()`, which
 * makes Chrome deny every same-origin getUserMedia instantly (NotAllowedError,
 * no permission prompt) regardless of the user's site settings — breaking
 * inbox voice-note recording. The header must be `microphone=(self)`.
 *
 * The CSP is also asserted to allow blob: media: without a media-src directive
 * the <audio src="blob:..."> in the composer voice-note preview falls back to
 * default-src 'self', gets blocked, and the play button silently does nothing.
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

    #[Test]
    public function csp_allows_blob_media_sources(): void
    {
        $response = $this->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('media-src', $csp);
        $this->assertStringContainsString("media-src 'self' blob: https:", $csp);
        $this->assertStringNotContainsString('microphone=()', (string) $response->headers->get('Permissions-Policy'));
    }
}
