<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WebhookEvent::query();

        foreach (['status', 'event_type', 'external_event_id'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $paginator = $query->orderByDesc('id')->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => collect($paginator->items())->map(fn (WebhookEvent $e) => $this->serialize($e))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(WebhookEvent $event): JsonResponse
    {
        return response()->json(['event' => $this->serialize($event)]);
    }

    private function serialize(WebhookEvent $event): array
    {
        // Payload is the sender's JSON body only; transport/auth headers are
        // never captured, so nothing sensitive can leak here.
        return [
            'id' => $event->id,
            'source' => $event->source,
            'external_event_id' => $event->external_event_id,
            'event_type' => $event->event_type,
            'payload' => $event->payload,
            'status' => $event->status,
            'attempts' => $event->attempts,
            'received_at' => $event->received_at?->toISOString(),
            'processed_at' => $event->processed_at?->toISOString(),
            'last_error' => $event->last_error,
            'created_at' => $event->created_at?->toISOString(),
            'updated_at' => $event->updated_at?->toISOString(),
        ];
    }
}
