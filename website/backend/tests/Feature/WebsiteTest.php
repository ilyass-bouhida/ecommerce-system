<?php

namespace Tests\Feature;

use App\Models\IntegrationLog;
use App\Models\WebhookEvent;
use App\Models\WebsiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $db = DB::connection()->getDatabaseName();
        $this->assertSame('website_test_db', $db, 'Refusing to run: not website_test_db.');

        // JSON test requests only carry cookies with credentials enabled,
        // mirroring the browser's withCredentials behavior.
        $this->withCredentials();
    }

    private function configure(array $overrides = []): WebsiteConnection
    {
        $connection = WebsiteConnection::current();
        $connection->forceFill(array_merge([
            'name' => 'Main Website',
            'website_url' => 'https://example.com',
            'api_base_url' => 'https://example.com/api',
            'api_key' => 'test-api-key-abc123',
            'webhook_secret' => 'test-webhook-secret-12345678',
            'is_active' => true,
            'connection_status' => WebsiteConnection::STATUS_NOT_CONFIGURED,
        ], $overrides))->save();

        return $connection->refresh();
    }

    private function ownerIdentity(): array
    {
        return ['id' => 7, 'name' => 'Ilyass', 'email' => 'ilyass@example.com', 'role' => 'OWNER'];
    }

    private function fakeAuthOk(string $role = 'OWNER'): void
    {
        $user = $this->ownerIdentity();
        $user['role'] = $role;
        Http::fake(['*/internal/*' => Http::response(['valid' => true, 'user' => $user], 200)]);
    }

    private function authed(string $role = 'OWNER'): static
    {
        $this->fakeAuthOk($role);

        return $this->withCookie('auth_token', 'AUDIT-TOKEN-XYZ');
    }

    private function webhookHeaders(string $id, string $type, string $raw, string $secret, ?string $ts = null): array
    {
        $ts ??= (string) time();

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_ID' => $id,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, $secret),
            'HTTP_X_WEBHOOK_EVENT' => $type,
        ];
    }

    private function webhookCall(string $id, string $type, mixed $payload, string $secret, array $headerOverrides = [], ?string $ts = null): \Illuminate\Testing\TestResponse
    {
        $raw = is_string($payload) ? $payload : json_encode($payload);

        return $this->call(
            'POST', '/webhooks/v1/website', [], [], [],
            array_merge($this->webhookHeaders($id, $type, $raw, $secret, $ts), $headerOverrides),
            $raw
        );
    }

    // ---------- HEALTH + DB ----------

    public function test_health_checks_real_mysql(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'service' => 'website', 'database' => 'up']);
    }

    public function test_migrations_apply_cleanly_and_core_tables_absent(): void
    {
        foreach (['website_connections', 'integration_logs', 'webhook_events', 'migrations'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "Missing {$t}.");
        }
        $this->assertFalse(Schema::hasTable('users'), 'Website DB must not contain users.');
        $this->assertFalse(Schema::hasTable('sessions'), 'No sessions dependency allowed.');
        $this->assertFalse(Schema::hasTable('personal_access_tokens'));
        $this->assertFalse(Schema::hasTable('products'));
        $this->assertFalse(Schema::hasTable('orders'));
        $this->assertFalse(Schema::hasTable('customers'));
    }

    // ---------- AUTH (1-10) ----------

    public function test_01_no_cookie_is_401(): void
    {
        $this->getJson('/api/v1/integrations/website')->assertUnauthorized();
    }

    public function test_02_invalid_token_is_401(): void
    {
        Http::fake(['*/internal/*' => Http::response(['valid' => false], 200)]);

        $this->withCookie('auth_token', 'BAD-TOKEN')
            ->getJson('/api/v1/integrations/website')->assertUnauthorized();
    }

    public function test_03_valid_owner_allowed(): void
    {
        $this->configure();

        $this->authed('OWNER')->getJson('/api/v1/integrations/website')->assertOk();
    }

    public function test_04_valid_admin_is_403(): void
    {
        $this->configure();

        $this->authed('ADMIN')->getJson('/api/v1/integrations/website')->assertForbidden();
    }

    public function test_05_valid_staff_is_403(): void
    {
        $this->configure();

        $this->authed('STAFF')->getJson('/api/v1/integrations/website')->assertForbidden();
    }

    public function test_06_auth_timeout_is_503(): void
    {
        config(['website.auth_internal_url' => 'http://127.0.0.1:9']);

        $this->withCookie('auth_token', 'TOK')
            ->getJson('/api/v1/integrations/website')->assertServiceUnavailable();
    }

    public function test_07_auth_connection_failure_is_503(): void
    {
        config(['website.auth_internal_url' => 'http://127.0.0.1:9']);

        $this->withCookie('auth_token', 'TOK')
            ->getJson('/api/v1/integrations/website/logs')->assertServiceUnavailable();
    }

    public function test_08_auth_5xx_is_503(): void
    {
        Http::fake(['*/internal/*' => Http::response('bad gateway', 502)]);

        $this->withCookie('auth_token', 'TOK')
            ->getJson('/api/v1/integrations/website')->assertServiceUnavailable();
    }

    public function test_09_internal_key_never_exposed(): void
    {
        $this->configure();

        $res = $this->authed()->getJson('/api/v1/integrations/website')->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('internal', $res->getContent());
    }

    public function test_10_auth_token_never_exposed(): void
    {
        $this->configure();

        foreach ([
            $this->authed()->getJson('/api/v1/integrations/website')->assertOk(),
            $this->authed()->getJson('/api/v1/integrations/website/logs')->assertOk(),
        ] as $res) {
            $this->assertStringNotContainsString('AUDIT-TOKEN-XYZ', $res->getContent());
        }
    }

    // ---------- CONFIG (11-24) ----------

    public function test_11_owner_reads_safe_config(): void
    {
        $this->configure();

        $this->authed()->getJson('/api/v1/integrations/website')
            ->assertOk()
            ->assertJsonPath('has_api_key', true)
            ->assertJsonPath('has_webhook_secret', true)
            ->assertJsonMissing(['api_key', 'webhook_secret'], true);
    }

    public function test_12_owner_updates_website_url(): void
    {
        $this->configure();

        $this->authed()->patchJson('/api/v1/integrations/website', [
            'name' => 'Main Website', 'website_url' => 'https://shop.example.com', 'is_active' => true,
        ])->assertOk()->assertJsonPath('website_url', 'https://shop.example.com');
    }

    public function test_13_owner_updates_api_base_url(): void
    {
        $this->configure();

        $this->authed()->patchJson('/api/v1/integrations/website', [
            'name' => 'Main Website', 'api_base_url' => 'https://api.example.com', 'is_active' => true,
        ])->assertOk()->assertJsonPath('api_base_url', 'https://api.example.com');
    }

    public function test_14_api_key_encrypted_at_rest(): void
    {
        $this->configure(['api_key' => 'plain-marker-key-001']);

        $raw = DB::table('website_connections')->first();
        $this->assertNotSame('plain-marker-key-001', $raw->api_key);
        $this->assertSame('plain-marker-key-001', WebsiteConnection::current()->api_key);
    }

    public function test_15_webhook_secret_encrypted_at_rest(): void
    {
        $this->configure(['webhook_secret' => 'plain-marker-secret-002-xyzab']);

        $raw = DB::table('website_connections')->first();
        $this->assertNotSame('plain-marker-secret-002-xyzab', $raw->webhook_secret);
    }

    public function test_16_get_hides_api_key(): void
    {
        $this->configure(['api_key' => 'hide-me-key-zzz']);

        $body = $this->authed()->getJson('/api/v1/integrations/website')->assertOk()->json();
        $this->assertArrayNotHasKey('api_key', $body);
        $this->assertStringNotContainsString('hide-me-key-zzz', json_encode($body));
    }

    public function test_17_get_hides_webhook_secret(): void
    {
        $this->configure(['webhook_secret' => 'hide-me-secret-zzz-123456']);

        $body = $this->authed()->getJson('/api/v1/integrations/website')->assertOk()->json();
        $this->assertArrayNotHasKey('webhook_secret', $body);
        $this->assertStringNotContainsString('hide-me-secret-zzz-123456', json_encode($body));
    }

    public function test_18_19_secret_presence_flags(): void
    {
        $this->configure(['api_key' => 'k', 'webhook_secret' => '1234567890123456']);

        $this->authed()->getJson('/api/v1/integrations/website')
            ->assertOk()
            ->assertJsonPath('has_api_key', true)
            ->assertJsonPath('has_webhook_secret', true);
    }

    public function test_20_omitted_api_key_preserved(): void
    {
        $this->configure(['api_key' => 'keep-key-001']);

        $this->authed()->patchJson('/api/v1/integrations/website', [
            'name' => 'Renamed', 'is_active' => true,
        ])->assertOk()->assertJsonPath('has_api_key', true);

        $this->assertSame('keep-key-001', WebsiteConnection::current()->api_key);
    }

    public function test_21_omitted_webhook_secret_preserved(): void
    {
        $this->configure(['webhook_secret' => 'keep-secret-001-xyzab']);

        $this->authed()->patchJson('/api/v1/integrations/website', [
            'name' => 'Renamed', 'is_active' => true,
        ])->assertOk()->assertJsonPath('has_webhook_secret', true);

        $this->assertSame('keep-secret-001-xyzab', WebsiteConnection::current()->webhook_secret);
    }

    public function test_explicit_secret_removal(): void
    {
        $this->configure();

        $this->authed()->patchJson('/api/v1/integrations/website', [
            'name' => 'W', 'api_key' => '__REMOVE__', 'webhook_secret' => '__REMOVE__', 'is_active' => false,
        ])->assertOk()->assertJsonPath('has_api_key', false)->assertJsonPath('has_webhook_secret', false);
    }

    public function test_22_invalid_urls_rejected(): void
    {
        $this->configure();

        foreach (['not-a-url', 'ftp://example.com/x', 'file:///etc/passwd', 'javascript:alert(1)'] as $bad) {
            $this->authed()->patchJson('/api/v1/integrations/website', [
                'name' => 'W', 'website_url' => $bad, 'is_active' => false,
            ])->assertStatus(422)->assertJsonValidationErrors(['website_url']);

            $this->authed()->patchJson('/api/v1/integrations/website', [
                'name' => 'W', 'api_base_url' => $bad, 'is_active' => false,
            ])->assertStatus(422)->assertJsonValidationErrors(['api_base_url']);
        }
    }

    public function test_23_24_non_owner_denied_config(): void
    {
        $this->configure();

        $this->authed('STAFF')->getJson('/api/v1/integrations/website')->assertForbidden();
        $this->authed('ADMIN')->getJson('/api/v1/integrations/website')->assertForbidden();
    }

    public function test_material_change_resets_status(): void
    {
        $this->configure(['connection_status' => WebsiteConnection::STATUS_CONNECTED]);

        $this->authed()->patchJson('/api/v1/integrations/website', [
            'name' => 'W', 'api_base_url' => 'https://new.example.com', 'is_active' => true,
        ])->assertOk()->assertJsonPath('connection_status', 'NOT_CONFIGURED');
    }

    // ---------- CONNECTION (25-34) ----------

    public function test_25_success_marks_connected(): void
    {
        $this->configure();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/internal/')) {
                return Http::response(['valid' => true, 'user' => $this->ownerIdentity()], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $this->authed()->postJson('/api/v1/integrations/website/test')
            ->assertOk()
            ->assertJson(['connected' => true]);

        $connection = WebsiteConnection::current();
        $this->assertSame('CONNECTED', $connection->connection_status);
        $this->assertNotNull($connection->last_tested_at);
        $this->assertNotNull($connection->last_connected_at);
        $this->assertNull($connection->last_error);
    }

    public function test_external_health_contract_and_key_header(): void
    {
        $this->configure(['api_base_url' => 'https://shop.example.com/', 'api_key' => 'wire-key-007']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/internal/')) {
                return Http::response(['valid' => true, 'user' => $this->ownerIdentity()], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $this->authed()->postJson('/api/v1/integrations/website/test')->assertOk();

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/api/v1/health')
                && $request->hasHeader('X-API-Key', 'wire-key-007');
        });
    }

    public function test_28_29_external_errors_mark_error(): void
    {
        $this->configure();

        foreach ([401, 500] as $status) {
            Http::fake(function ($request) use ($status) {
                if (str_contains($request->url(), '/internal/')) {
                    return Http::response(['valid' => true, 'user' => $this->ownerIdentity()], 200);
                }

                return Http::response('nope', $status);
            });

            $this->authed()->postJson('/api/v1/integrations/website/test')
                ->assertStatus(502)
                ->assertJsonPath('connected', false);

            $this->assertSame('ERROR', WebsiteConnection::current()->connection_status);
            $this->assertNotNull(WebsiteConnection::current()->last_tested_at);
        }
    }

    public function test_30_31_transport_failures_mark_error(): void
    {
        // Real refused connection (closed port, instant): the introspection
        // call stays faked while the website URL goes over real transport.
        $this->configure(['api_base_url' => 'http://127.0.0.1:9']);
        Http::fake(['*/internal/*' => Http::response(['valid' => true, 'user' => $this->ownerIdentity()], 200)]);

        $this->authed()->postJson('/api/v1/integrations/website/test')
            ->assertStatus(502)
            ->assertJsonPath('connected', false);

        $this->assertSame('ERROR', WebsiteConnection::current()->connection_status);

        // Fake 500 exercises the same failure branch deterministically.
        $this->configure(['api_base_url' => 'https://example.com/api']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/internal/')) {
                return Http::response(['valid' => true, 'user' => $this->ownerIdentity()], 200);
            }

            return Http::response('bad', 500);
        });

        $this->authed()->postJson('/api/v1/integrations/website/test')
            ->assertStatus(502)
            ->assertJsonPath('connected', false);
    }

    public function test_32_34_safe_logs_without_key(): void
    {
        $this->configure(['api_key' => 'log-marker-key-999']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/internal/')) {
                return Http::response(['valid' => true, 'user' => $this->ownerIdentity()], 200);
            }

            return Http::response('bad', 500);
        });

        $this->authed()->postJson('/api/v1/integrations/website/test')->assertStatus(502);

        $log = IntegrationLog::where('event_type', 'CONNECTION_TEST')->latest('id')->firstOrFail();
        $this->assertSame('FAILED', $log->status);
        $dump = json_encode([$log->message, $log->metadata, $log->endpoint]);
        $this->assertStringNotContainsString('log-marker-key-999', $dump);
        $this->assertStringNotContainsStringIgnoringCase('bearer', $dump);
    }

    // ---------- WEBHOOK (35-52) ----------

    public function test_35_missing_id_rejected(): void
    {
        $this->configure();
        $raw = json_encode(['type' => 'order.created']);
        $ts = (string) time();

        $this->call('POST', '/webhooks/v1/website', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, 'test-webhook-secret-12345678'),
            'HTTP_X_WEBHOOK_EVENT' => 'order.created',
        ], $raw)->assertStatus(422);
    }

    public function test_36_missing_timestamp_rejected(): void
    {
        $this->configure();

        $this->webhookCall('e1', 'order.created', ['x' => 1], 'test-webhook-secret-12345678', ['HTTP_X_WEBHOOK_TIMESTAMP' => ''])
            ->assertStatus(422);
    }

    public function test_37_missing_signature_rejected(): void
    {
        $this->configure();

        $this->webhookCall('e1', 'order.created', ['x' => 1], 'test-webhook-secret-12345678', ['HTTP_X_WEBHOOK_SIGNATURE' => ''])
            ->assertStatus(401);
    }

    public function test_38_missing_event_rejected(): void
    {
        $this->configure();
        $raw = json_encode(['event_id' => 'e1']);
        $ts = (string) time();

        $this->call('POST', '/webhooks/v1/website', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_ID' => 'e1',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, 'test-webhook-secret-12345678'),
            'HTTP_X_WEBHOOK_EVENT' => '',
        ], $raw)->assertStatus(422);
    }

    public function test_39_wrong_signature_rejected(): void
    {
        $this->configure();

        $this->webhookCall('e1', 'order.created', ['x' => 1], 'wrong-secret', ['HTTP_X_WEBHOOK_SIGNATURE' => 'deadbeef'])
            ->assertStatus(401);
    }

    public function test_40_expired_timestamp_rejected(): void
    {
        $this->configure();

        $this->webhookCall('e1', 'order.created', ['x' => 1], 'test-webhook-secret-12345678', [], (string) (time() - 600))
            ->assertStatus(422);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_41_valid_fresh_signature_accepted(): void
    {
        $this->configure();

        $this->webhookCall('evt_fresh_1', 'order.created', ['total' => 5], 'test-webhook-secret-12345678')
            ->assertStatus(202)
            ->assertJson(['status' => 'accepted']);
    }

    public function test_42_raw_body_consistency(): void
    {
        // Signature over DIFFERENT bytes than sent must fail even though
        // both bodies are valid JSON.
        $this->configure();
        $secret = 'test-webhook-secret-12345678';
        $sig = hash_hmac('sha256', (string) time().'.{"total":10}', $secret);

        $raw = '{"total":9999,"padding":0}';
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.{"total":10}', $secret);

        $this->call('POST', '/webhooks/v1/website', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_ID' => 'e-tamper',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_X_WEBHOOK_SIGNATURE' => $sig,
            'HTTP_X_WEBHOOK_EVENT' => 'order.created',
        ], $raw)->assertStatus(401);
    }

    public function test_43_45_business_event_stored_pending(): void
    {
        $this->configure();

        $this->webhookCall('evt_ord_1', 'order.created', ['total' => 99, 'test' => true], 'test-webhook-secret-12345678')
            ->assertStatus(202);

        $event = WebhookEvent::where('external_event_id', 'evt_ord_1')->firstOrFail();
        $this->assertSame('PENDING', $event->status);
        $this->assertSame('order.created', $event->event_type);
        // Note: MySQL normalizes JSON key order on storage.
        $this->assertEquals(['total' => 99, 'test' => true], $event->payload);
        $this->assertNotNull($event->received_at);
        $this->assertDatabaseHas('integration_logs', ['event_type' => 'WEBHOOK_RECEIVED', 'status' => 'SUCCESS']);
    }

    public function test_46_47_48_duplicate_idempotent(): void
    {
        $this->configure();
        $payload = ['event_id' => 'evt_dup_1', 'type' => 'order.created', 'data' => []];

        // Note: envelope body event_id is informational; identity comes
        // from the X-Webhook-Id header per contract.
        $raw = json_encode($payload);
        $send = function () use ($raw) {
            $ts = (string) time();

            return $this->call('POST', '/webhooks/v1/website', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_ID' => 'evt_dup_1',
                'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
                'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, 'test-webhook-secret-12345678'),
                'HTTP_X_WEBHOOK_EVENT' => 'order.created',
            ], $raw);
        };

        $send()->assertStatus(202)->assertJson(['status' => 'accepted']);
        $send()->assertOk()->assertJson(['status' => 'already_received']);

        $this->assertSame(1, WebhookEvent::where('external_event_id', 'evt_dup_1')->count());
        $this->assertSame(1, IntegrationLog::where('event_type', 'WEBHOOK_RECEIVED')->count());
    }

    public function test_49_payload_stored_correctly(): void
    {
        $this->configure();
        $data = ['order' => ['id' => 42, 'lines' => [['sku' => 'A', 'qty' => 2]]]];

        $this->webhookCall('evt_pay_1', 'order.created', $data, 'test-webhook-secret-12345678')
            ->assertStatus(202);

        $this->assertEquals($data, WebhookEvent::where('external_event_id', 'evt_pay_1')->firstOrFail()->payload);
    }

    public function test_50_51_secrets_absent_from_logs(): void
    {
        $this->configure(['webhook_secret' => 'hide-hook-secret-xyz-12345678']);
        $marker = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef01';

        $this->webhookCall('e-sig', 't', ['x' => 1], 'whatever-secret', ['HTTP_X_WEBHOOK_SIGNATURE' => $marker])
            ->assertStatus(401);

        foreach (IntegrationLog::all() as $log) {
            $dump = json_encode([$log->message, $log->metadata]);
            $this->assertStringNotContainsString($marker, $dump);
            $this->assertStringNotContainsString('hide-hook-secret-xyz-12345678', $dump);
        }
    }

    public function test_52_no_browser_auth_for_webhook(): void
    {
        $this->configure();

        // No cookies at all — HMAC alone must suffice.
        $this->webhookCall('evt_nocookie', 'ping', ['a' => 1], 'test-webhook-secret-12345678')
            ->assertStatus(202);
    }

    public function test_malformed_json_rejected(): void
    {
        $this->configure();
        $secret = 'test-webhook-secret-12345678';
        $raw = '{oops not json';
        $ts = (string) time();

        $this->call('POST', '/webhooks/v1/website', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_ID' => 'e-bad',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, $secret),
            'HTTP_X_WEBHOOK_EVENT' => 'order.created',
        ], $raw)->assertStatus(422);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_system_event_received_not_pending(): void
    {
        $this->configure();

        $this->webhookCall('evt_ping_1', 'ping', ['a' => 1], 'test-webhook-secret-12345678')
            ->assertStatus(202);

        $this->assertSame('RECEIVED', WebhookEvent::where('external_event_id', 'evt_ping_1')->firstOrFail()->status);
    }

    public function test_oversized_fields_rejected_cleanly(): void
    {
        $this->configure();

        $this->webhookCall('e1', str_repeat('t', 200), ['x' => 1], 'test-webhook-secret-12345678')
            ->assertStatus(422);

        $this->webhookCall(str_repeat('e', 300), 'ping', ['x' => 1], 'test-webhook-secret-12345678')
            ->assertStatus(422);

        $this->assertSame(0, WebhookEvent::count());
    }

    // ---------- LOGS/EVENTS (53-61) ----------

    public function test_53_54_55_logs_list_paginate_filter(): void
    {
        $this->configure();
        for ($i = 0; $i < 12; $i++) {
            IntegrationLog::record([
                'direction' => $i % 2 ? 'INBOUND' : 'OUTBOUND',
                'event_type' => 'MANUAL_X', 'status' => $i % 3 ? 'SUCCESS' : 'FAILED',
            ]);
        }

        $page = $this->authed()->getJson('/api/v1/integrations/website/logs?per_page=5')
            ->assertOk();
        $page->assertJsonPath('meta.per_page', 5)->assertJsonPath('meta.total', 12);
        $this->assertCount(5, $page->json('data'));

        $failed = $this->authed()->getJson('/api/v1/integrations/website/logs?status=FAILED')->assertOk()->json('data');
        $this->assertNotEmpty($failed);
        foreach ($failed as $row) {
            $this->assertSame('FAILED', $row['status']);
        }
    }

    public function test_56_57_58_59_events_list_paginate_filter_details(): void
    {
        $this->configure();
        WebhookEvent::query()->create([
            'source' => 'website', 'external_event_id' => 'evt_l_1',
            'event_type' => 'order.created', 'payload' => ['a' => 1],
            'status' => 'PENDING', 'received_at' => now(),
        ]);
        WebhookEvent::query()->create([
            'source' => 'website', 'external_event_id' => 'evt_l_2',
            'event_type' => 'ping', 'payload' => ['b' => 2],
            'status' => 'RECEIVED', 'received_at' => now(),
        ]);

        $list = $this->authed()->getJson('/api/v1/integrations/website/events?per_page=1')
            ->assertOk();
        $list->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        // Newest first.
        $this->assertSame('evt_l_2', $list->json('data.0.external_event_id'));

        $filtered = $this->authed()
            ->getJson('/api/v1/integrations/website/events?event_type=order.created&external_event_id=evt_l_1')
            ->assertOk()->json('data');
        $this->assertCount(1, $filtered);

        $id = $filtered[0]['id'];
        $detail = $this->authed()->getJson("/api/v1/integrations/website/events/{$id}")
            ->assertOk()->json('event');
        $this->assertSame(['a' => 1], $detail['payload']);
        $this->assertStringNotContainsStringIgnoringCase('signature', json_encode($detail));
    }

    public function test_60_61_non_owner_denied_logs_events(): void
    {
        $this->configure();

        $this->authed('STAFF')->getJson('/api/v1/integrations/website/logs')->assertForbidden();
        $this->authed('STAFF')->getJson('/api/v1/integrations/website/events')->assertForbidden();
        $this->authed('ADMIN')->getJson('/api/v1/integrations/website/logs')->assertForbidden();
        $this->authed('ADMIN')->getJson('/api/v1/integrations/website/events')->assertForbidden();
    }
}
