<?php

namespace App\Modules\Instagram\Exceptions;

use RuntimeException;

/**
 * Typed wrapper for Graph API failures inside the Instagram module. Carries the
 * Graph error code so callers can decide between retrying (transient) and
 * giving up permanently (validation/permission errors) without string matching.
 */
class InstagramGraphException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $graphErrorCode = null,
        public readonly ?int $graphErrorSubcode = null,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message);
    }

    /** Transient failures (5xx / rate limits / network) are safe to retry. */
    public function shouldRetry(): bool
    {
        if ($this->httpStatus >= 500 || $this->httpStatus === 429 || $this->httpStatus === 0) {
            return true;
        }

        // Meta's throttling error codes.
        return in_array($this->graphErrorCode, [2, 4, 17, 32, 613], true);
    }

    /** Token revoked / expired, or the app lost a required permission. */
    public function isTokenPermissionError(): bool
    {
        return in_array($this->graphErrorCode, [190, 3, 10, 102], true);
    }
}
