<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($request->query('status') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->query('status') === 'inactive') {
            $query->where('is_active', false);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $paginator = $query->orderByDesc('id')->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => collect($paginator->items())->map(fn (User $u) => $u->toSafeArray())->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->string('name'),
            'email' => $request->input('email'),
            'password' => $request->input('password'),
            'role' => $request->input('role', User::ROLE_STAFF),
            'is_active' => true,
        ]);

        AuditLogger::log('USER_CREATED', $request->user(), 'user', $user->id, [
            'email' => $user->email,
            'role' => $user->role,
        ], $request);

        return response()->json(['user' => $user->refresh()->toSafeArray()], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['user' => $user->toSafeArray()]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $newRole = $request->has('role') ? $request->input('role') : $user->role;

        if ($user->isLastActiveOwner() && $newRole !== User::ROLE_OWNER) {
            return response()->json([
                'message' => 'The system must keep at least one active OWNER.',
            ], 422);
        }

        $user->fill($request->only(['name', 'email', 'role']));

        if ($request->filled('password')) {
            $user->password = $request->input('password');
        }

        $user->save();

        AuditLogger::log('USER_UPDATED', $request->user(), 'user', $user->id, [
            'email' => $user->email,
            'role' => $user->role,
        ], $request);

        return response()->json(['user' => $user->refresh()->toSafeArray()]);
    }

    /**
     * Soft deactivate — never a physical SQL DELETE. Revokes all tokens.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->isLastActiveOwner()) {
            return response()->json([
                'message' => 'The system must keep at least one active OWNER.',
            ], 422);
        }

        $user->forceFill(['is_active' => false])->save();
        $user->tokens()->delete();

        AuditLogger::log('USER_DEACTIVATED', $request->user(), 'user', $user->id, [
            'email' => $user->email,
        ], $request);

        return response()->json(['user' => $user->refresh()->toSafeArray()]);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $user->forceFill(['is_active' => true])->save();

        AuditLogger::log('USER_ACTIVATED', $request->user(), 'user', $user->id, [
            'email' => $user->email,
        ], $request);

        return response()->json(['user' => $user->refresh()->toSafeArray()]);
    }
}
