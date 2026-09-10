<?php

namespace Tests\Unit;

use App\Models\IntegrationLog;
use App\Models\WebsiteConnection;
use App\Services\AuthClient;
use App\Services\WebhookSignature;
use App\Services\WebsiteClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebsiteModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $db = DB::connection()->getDatabaseName();
        $this->assertSame('website_test_db', $db, 'Refusing to run: not website_test_db.');
    }

    // ---------- WebhookSignature ----------

    public function test_signature_round_trip(): void
    {
        $ts = (string) time();
        $body = '{"a":1}';
        $secret = 'unit-secret-12345678';

        $this->assertTrue(WebhookSignature::valid($ts, $body, WebhookSignature::sign($ts, $body, $secret), $secret));
    }

    public function test_signature_rejects_tampered_body_and_wrong_secret(): void
    {
        $ts = (string) time();
        $sig = WebhookSignature::sign($ts, '{"total":10}', 'right-secret-12345678');

        $this->assertFalse(WebhookSignature::valid($ts, '{"total":9999}', $sig, 'right-secret-12345678'));
        $this->assertFalse(WebhookSignature::valid($ts, '{"total":10}', $sig, 'wrong-secret-12345678'));
    }

    public function test_signature_rejects_empty_and_malformed_without_exception(): void
    {
        foreach (['', '   ', 'sha256=', 'not-hex!!', 'zz'] as $bad) {
            $this->assertFalse(WebhookSignature::valid('123', '{}', $bad, 'secret-123456789012'), "header [{$bad}]");
        }
        $this->assertFalse(WebhookSignature::valid('123', '{}', WebhookSignature::sign('123', '{}', 's'), ''));
    }

    public function test_signature_accepts_prefix_and_uppercase(): void
    {
        $ts = (string) time();
        $sig = WebhookSignature::sign($ts, '{}', 'unit-secret-12345678');

        $this->assertTrue(WebhookSignature::valid($ts, '{}', 'sha256='.$sig, 'unit-secret-12345678'));
        $this->assertTrue(WebhookSignature::valid($ts, '{}', strtoupper($sig), 'unit-secret-12345678'));
    }

    public function test_timestamp_freshness_window(): void
    {
        $this->assertTrue(WebhookSignature::fresh((string) time(), 300));
        $this->assertTrue(WebhookSignature::fresh((string) (time() - 299), 300));
        $this->assertFalse(WebhookSignature::fresh((string) (time() - 301), 300));
        $this->assertFalse(WebhookSignature::fresh((string) (time() + 301), 300));
        $this->assertFalse(WebhookSignature::fresh('', 300));
        $this->assertFalse(WebhookSignature::fresh('not-a-time', 300));
    }

    // ---------- AuthClient mapping (injected sender, no network) ----------

    public function test_auth_client_maps_valid_identity(): void
    {
        $client = new AuthClient(fn () => ['status' => 200, 'body' => [
            'valid' => true, 'user' => ['id' => 3, 'name' => 'N', 'email' => 'n@e.co', 'role' => 'OWNER'],
        ]]);

        $result = $client->introspect('tok');

        $this->assertSame('ok', $result['state']);
        $this->assertSame(3, $result['user']['id']);
        $this->assertArrayNotHasKey('password', $result['user']);
    }

    public function test_auth_client_maps_invalid_and_server_errors(): void
    {
        $invalid = new AuthClient(fn () => ['status' => 200, 'body' => ['valid' => false]]);
        $this->assertSame('invalid', $invalid->introspect('tok')['state']);

        $missing = new AuthClient(fn () => ['status' => 200, 'body' => []]);
        $this->assertSame('invalid', $missing->introspect('tok')['state']);

        $this->assertSame('invalid', (new AuthClient)->introspect('')['state']);
        $this->assertSame('invalid', (new AuthClient)->introspect(null)['state']);

        foreach ([500, 502, 503] as $status) {
            $client = new AuthClient(fn () => ['status' => $status, 'body' => []]);
            $this->assertSame('unavailable', $client->introspect('tok')['state'], "HTTP {$status}");
        }
    }

    public function test_auth_client_maps_transport_failures(): void
    {
        $timeout = new AuthClient(function () {
            throw new ConnectionException('timed out');
        });
        $this->assertSame('unavailable', $timeout->introspect('tok')['state']);

        $boom = new AuthClient(function () {
            throw new \RuntimeException('boom');
        });
        $this->assertSame('unavailable', $boom->introspect('tok')['state']);
    }

    // ---------- WebsiteClient mapping ----------

    private function connection(): WebsiteConnection
    {
        return WebsiteConnection::current()->forceFill([
            'api_base_url' => 'https://example.com',
            'api_key' => 'unit-key',
        ]);
    }

    public function test_website_client_success_and_errors(): void
    {
        $ok = new WebsiteClient($this->connection(), fn () => ['status' => 200]);
        $this->assertTrue($ok->testConnection()['ok']);

        $denied = new WebsiteClient($this->connection(), fn () => ['status' => 401]);
        $r = $denied->testConnection();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('401', $r['error']);

        $err = new WebsiteClient($this->connection(), fn () => ['status' => 500]);
        $this->assertFalse($err->testConnection()['ok']);

        $down = new WebsiteClient($this->connection(), function () {
            throw new ConnectionException('down');
        });
        $r = $down->testConnection();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('timed out', $r['error']);
    }

    // ---------- Sanitizing + masking ----------

    public function test_sanitize_redacts_transport_secrets(): void
    {
        $clean = IntegrationLog::sanitize([
            'X-Auth-Token' => 'T', 'X-Internal-Key' => 'K',
            'Authorization' => 'Bearer T', 'nested' => ['api_key' => 'K', 'note' => 'keep'],
            'event' => 'X',
        ]);

        $this->assertSame('[redacted]', $clean['X-Auth-Token']);
        $this->assertSame('[redacted]', $clean['X-Internal-Key']);
        $this->assertSame('[redacted]', $clean['Authorization']);
        $this->assertSame('[redacted]', $clean['nested']['api_key']);
        $this->assertSame('keep', $clean['nested']['note']);
    }

    public function test_safe_array_flags_without_db(): void
    {
        $bare = (new WebsiteConnection(['name' => 'W']))->toSafeArray();
        $this->assertFalse($bare['has_api_key']);
        $this->assertFalse($bare['has_webhook_secret']);

        $full = (new WebsiteConnection(['api_key' => 'K', 'webhook_secret' => 'S']))->toSafeArray();
        $this->assertTrue($full['has_api_key']);
        $this->assertTrue($full['has_webhook_secret']);
        $this->assertStringNotContainsString('K', json_encode($full));
    }
}
