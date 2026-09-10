<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\InternalKey;
use App\Support\TokenAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntrospectController extends Controller
{
    /**
     * Microservice identity check. Never touches sessions; validates the
     * opaque token presented by a sibling service.
     */
    public function introspect(Request $request): JsonResponse
    {
        if (! InternalKey::valid($request->header('X-Internal-Key'))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $rawToken = trim((string) $request->header('X-Auth-Token', ''));

        if ($rawToken === '') {
            return response()->json(['valid' => false], 401);
        }

        $user = TokenAuth::resolve($rawToken);

        if (! $user instanceof User) {
            return response()->json(['valid' => false]);
        }

        return response()->json([
            'valid' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }
}
