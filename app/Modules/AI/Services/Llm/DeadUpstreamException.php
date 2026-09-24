<?php

namespace App\Modules\AI\Services\Llm;

/**
 * Thrown when an upstream answers with garbage (canned greeting loop) or a
 * 200-wrapped upstream error — callers must fall back, never send this to a
 * customer.
 */
class DeadUpstreamException extends \RuntimeException {}
