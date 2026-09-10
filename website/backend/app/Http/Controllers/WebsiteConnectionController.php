<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWebsiteConnectionRequest;
use App\Models\IntegrationLog;
use App\Models\WebsiteConnection;
use App\Services\WebsiteClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteConnectionController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(WebsiteConnection::current()->toSafeArray());
    }

    public function update(UpdateWebsiteConnectionRequest $request): JsonResponse
    {
        $connection = WebsiteConnection::current();

        $before = [$connection->website_url, $connection->api_base_url, $connection->is_active];

        $connection->fill($request->only(['name', 'website_url', 'api_base_url']));
        if ($request->has('is_active')) {
            $connection->is_active = $request->boolean('is_active');
        }

        $this->applySecret($connection, 'api_key', $request);
        $this->applySecret($connection, 'webhook_secret', $request);

        // Material connection changes invalidate the previous verdict.
        $after = [$connection->website_url, $connection->api_base_url, $connection->is_active];
        if ($before !== $after) {
            $connection->connection_status = WebsiteConnection::STATUS_NOT_CONFIGURED;
            $connection->last_error = null;
        }

        $connection->save();

        return response()->json($connection->refresh()->toSafeArray());
    }

    /**
     * Absent/null/'' keeps the stored secret; '__REMOVE__' clears it;
     * any other value replaces it.
     */
    private function applySecret(WebsiteConnection $connection, string $field, Request $request): void
    {
        if (! array_key_exists($field, $request->all())) {
            return;
        }

        $incoming = $request->input($field);

        if ($incoming === null || $incoming === '') {
            return;
        }

        $connection->setAttribute($field, $incoming === '__REMOVE__' ? null : (string) $incoming);
    }

    public function test(Request $request): JsonResponse
    {
        $connection = WebsiteConnection::current();
        $actor = $request->attributes->get('auth.identity');

        if (! $connection->isConfigured()) {
            IntegrationLog::record([
                'direction' => 'OUTBOUND',
                'event_type' => 'CONNECTION_TEST',
                'status' => 'FAILED',
                'http_method' => 'GET',
                'message' => 'Integration is not configured (api_base_url missing).',
                'actor_user_id' => $actor['id'] ?? null,
            ]);

            return response()->json(['connected' => false, 'message' => 'Website integration is not configured.'], 422);
        }

        $result = (new WebsiteClient($connection))->testConnection();

        $connection->forceFill([
            'connection_status' => $result['ok'] ? WebsiteConnection::STATUS_CONNECTED : WebsiteConnection::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_connected_at' => $result['ok'] ? now() : $connection->last_connected_at,
            'last_error' => $result['ok'] ? null : ($result['error'] ?? 'Connection failed.'),
        ])->save();

        IntegrationLog::record([
            'direction' => 'OUTBOUND',
            'event_type' => 'CONNECTION_TEST',
            'status' => $result['ok'] ? 'SUCCESS' : 'FAILED',
            'http_method' => 'GET',
            'endpoint' => $result['url'] ?? rtrim((string) $connection->api_base_url, '/').'/api/v1/health',
            'http_status' => $result['http_status'] ?? null,
            'message' => $result['ok'] ? 'Connection successful.' : ($result['error'] ?? 'Connection failed.'),
            'actor_user_id' => $actor['id'] ?? null,
        ]);

        return response()->json([
            'connected' => $result['ok'],
            'message' => $result['ok'] ? 'Connection successful.' : ($result['error'] ?? 'Unable to connect to website.'),
        ], $result['ok'] ? 200 : 502);
    }
}
