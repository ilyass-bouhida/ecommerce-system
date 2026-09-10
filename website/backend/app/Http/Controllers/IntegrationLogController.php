<?php

namespace App\Http\Controllers;

use App\Models\IntegrationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = IntegrationLog::query();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('event_type', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%");
            });
        }

        foreach (['status', 'direction', 'event_type'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $paginator = $query->orderByDesc('id')->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => collect($paginator->items())->map(fn (IntegrationLog $log) => [
                'id' => $log->id,
                'direction' => $log->direction,
                'event_type' => $log->event_type,
                'reference_type' => $log->reference_type,
                'reference_id' => $log->reference_id,
                'status' => $log->status,
                'http_method' => $log->http_method,
                'endpoint' => $log->endpoint,
                'http_status' => $log->http_status,
                'message' => $log->message,
                'metadata' => $log->metadata,
                'actor_user_id' => $log->actor_user_id,
                'created_at' => $log->created_at?->toISOString(),
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
