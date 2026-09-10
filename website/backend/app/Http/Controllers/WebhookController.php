<?php

namespace App\Http\Controllers;

use App\Models\IntegrationLog;
use App\Models\WebhookEvent;
use App\Models\WebsiteConnection;
use App\Services\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * Public endpoint secured by HMAC + timestamp, NOT by user login.
     * Order: headers -> timestamp -> HMAC -> JSON -> idempotency -> STORE.
     * Commerce does not exist: business events stay PENDING, never processed.
     */
    public function handle(Request $request): JsonResponse
    {
        $id = trim((string) $request->header('X-Webhook-Id', ''));
        $timestamp = trim((string) $request->header('X-Webhook-Timestamp', ''));
        $signature = (string) $request->header('X-Webhook-Signature', '');
        $type = trim((string) $request->header('X-Webhook-Event', ''));
        $rawBody = $request->getContent();

        if ($id === '') {
            return $this->reject(422, 'Missing X-Webhook-Id header.');
        }

        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return $this->reject(422, 'Missing or invalid X-Webhook-Timestamp header.');
        }

        $tolerance = (int) config('website.webhook_timestamp_tolerance', 300);
        if (! WebhookSignature::fresh($timestamp, $tolerance)) {
            return $this->reject(422, 'Webhook timestamp outside tolerance window.');
        }

        if ($type === '') {
            return $this->reject(422, 'Missing X-Webhook-Event header.');
        }

        if (strlen($type) > 100) {
            return $this->reject(422, 'Webhook event type is too long.');
        }

        if (strlen($id) > 255) {
            return $this->reject(422, 'Webhook event id is too long.');
        }

        $connection = WebsiteConnection::current();

        if (! filled($connection->webhook_secret)) {
            return $this->reject(403, 'Webhook integration is not configured.');
        }

        if (! WebhookSignature::valid($timestamp, $rawBody, $signature, $connection->webhook_secret)) {
            return $this->reject(401, 'Invalid webhook signature.', 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return $this->reject(422, 'Malformed webhook JSON payload.');
        }

        // Idempotency: a stable external id must never be stored twice.
        $existing = WebhookEvent::query()
            ->where('source', WebhookEvent::SOURCE_WEBSITE)
            ->where('external_event_id', $id)
            ->first();

        if ($existing) {
            return response()->json(['status' => 'already_received', 'id' => $existing->id]);
        }

        try {
            $event = WebhookEvent::query()->create([
                'source' => WebhookEvent::SOURCE_WEBSITE,
                'external_event_id' => $id,
                'event_type' => $type,
                'payload' => $payload,
                'status' => in_array($type, WebhookEvent::SYSTEM_EVENTS, true)
                    ? WebhookEvent::STATUS_RECEIVED
                    : WebhookEvent::STATUS_PENDING,
                'received_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race between the check above and the insert: still idempotent.
            $existing = WebhookEvent::query()
                ->where('source', WebhookEvent::SOURCE_WEBSITE)
                ->where('external_event_id', $id)
                ->first();

            return response()->json(['status' => 'already_received', 'id' => $existing?->id]);
        }

        IntegrationLog::record([
            'direction' => 'INBOUND',
            'event_type' => 'WEBHOOK_RECEIVED',
            'reference_type' => 'webhook_event',
            'reference_id' => (string) $event->id,
            'status' => 'SUCCESS',
            // Deliberately no signature, secret, headers, or body.
            'metadata' => ['external_event_id' => $id, 'event_type' => $type],
            'message' => "Webhook {$type} received.",
        ]);

        return response()->json(['status' => 'accepted', 'id' => $event->id], 202);
    }

    private function reject(int $httpStatus, string $message): JsonResponse
    {
        try {
            IntegrationLog::record([
                'direction' => 'INBOUND',
                'event_type' => 'WEBHOOK_REJECTED',
                'status' => 'FAILED',
                'http_status' => $httpStatus,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => $message], $httpStatus);
    }
}
