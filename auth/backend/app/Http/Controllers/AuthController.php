<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\AuthCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
{
    $email = $request->string('email')
        ->trim()
        ->lower()
        ->toString();

    $password = (string) $request->input('password');

    $user = User::query()
        ->where('email', $email)
        ->first();

    if (!$user || !Hash::check($password, $user->password)) {
        AuditLogger::log(
            'LOGIN_FAILED',
            null,
            null,
            null,
            ['email' => $email],
            $request
        );

        return response()->json([
            'message' => 'Invalid credentials.'
        ], 422);
    }

    if (!$user->is_active) {
        AuditLogger::log(
            'LOGIN_FAILED',
            null,
            null,
            null,
            [
                'email' => $email,
                'reason' => 'inactive',
            ],
            $request
        );

        return response()->json([
            'message' => 'Invalid credentials.'
        ], 422);
    }

    $rawToken = $user->createToken(
        'auth',
        ['*'],
        now()->addMinutes(AuthCookie::ttlMinutes())
    )->plainTextToken;

    $user->forceFill([
        'last_login_at' => now(),
    ])->save();

    AuditLogger::log(
        'LOGIN_SUCCESS',
        $user,
        null,
        null,
        ['email' => $user->email],
        $request
    );

    return response()
        ->json([
            'user' => $user->refresh()->toSafeArray(),
        ])
        ->withCookie(AuthCookie::make($rawToken));
}
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $rawToken = (string) $request->cookie(AuthCookie::name(), '');

        $accessToken = \App\Support\TokenAuth::findAccessToken($rawToken);
        $accessToken?->delete();

        if ($user instanceof User) {
            AuditLogger::log('LOGOUT', $user, null, null, [], $request);
            AuditLogger::log('TOKEN_REVOKED', $user, 'token', $accessToken?->id, [], $request);
        }

        return response()->json(['message' => 'Logged out.'])
            ->withCookie(AuthCookie::forget());
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['user' => $user->toSafeArray()]);
    }
}
