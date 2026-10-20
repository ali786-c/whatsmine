<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiAbilities;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    /**
     * Issue a new personal access token.
     * Used from the UI token management page.
     */
    public function store(Request $request): JsonResponse
    {
        $validAbilities = ApiAbilities::all();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'in:*,'.implode(',', $validAbilities)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $abilities = $validated['abilities'] ?? ['*'];
        $expiresAt = isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;

        // A personal access token must never mint a token stronger than
        // itself. Session-authenticated requests (the token management UI)
        // carry a TransientToken instead and keep full control, exactly like
        // being logged in. Previously ANY scoped token could call this
        // endpoint and mint a permanent '*' token (privilege escalation).
        $current = $request->user()->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $callerAbilities = (array) $current->abilities;

            if (! in_array('*', $callerAbilities, true)) {
                if (in_array('*', $abilities, true)) {
                    return response()->json([
                        'error' => 'Forbidden. This token cannot grant wildcard access.',
                    ], 403);
                }

                $exceeding = array_values(array_diff($abilities, $callerAbilities));
                if ($exceeding !== []) {
                    return response()->json([
                        'error' => 'Forbidden. This token cannot grant scopes it does not hold.',
                        'exceeding_scopes' => $exceeding,
                    ], 403);
                }
            }

            // The new token must not outlive the token that created it.
            if ($current->expires_at && (! $expiresAt || $expiresAt->gt($current->expires_at))) {
                $expiresAt = Carbon::parse((string) $current->expires_at);
            }
        }

        // Tokens no longer live forever by default: 30 days when the caller
        // did not pick an expiry.
        $expiresAt ??= now()->addDays(30);

        $token = $request->user()->createToken($validated['name'], $abilities, $expiresAt);

        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $validated['name'],
            'token' => $token->plainTextToken,
            'abilities' => $abilities,
            'expires_at' => $expiresAt?->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ], 201);
    }

    /**
     * List all tokens for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->latest()->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'abilities' => $t->abilities,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'created_at' => $t->created_at->toIso8601String(),
        ]);

        return response()->json(['data' => $tokens]);
    }

    /**
     * Revoke a token.
     */
    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if (! $deleted) {
            return response()->json(['error' => 'Token not found.'], 404);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Return the list of available ability scopes (for the UI).
     */
    public function scopes(): JsonResponse
    {
        $labels = ApiAbilities::labels();

        return response()->json([
            'data' => array_map(fn ($scope, $label) => ['scope' => $scope, 'label' => $label], array_keys($labels), $labels),
        ]);
    }
}
