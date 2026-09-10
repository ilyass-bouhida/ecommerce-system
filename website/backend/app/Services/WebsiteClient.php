<?php

namespace App\Services;

use App\Models\WebsiteConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Outbound client for the external website's health contract:
 * GET {api_base_url}/api/v1/health with X-API-Key when configured.
 */
class WebsiteClient
{
    public function __construct(private WebsiteConnection $connection, private $probe = null) {}

    public function testConnection(): array
    {
        $url = rtrim((string) $this->connection->api_base_url, '/').'/api/v1/health';

        try {
            if ($this->probe) {
                $result = ($this->probe)($url);
                $status = (int) ($result['status'] ?? 0);
            } else {
                $request = Http::timeout((int) config('website.http_timeout', 5))
                    ->connectTimeout((int) config('website.http_connect_timeout', 2))
                    ->acceptJson()
                    ->withOptions(['allow_redirects' => ['max' => 3]]);

                if (filled($this->connection->api_key)) {
                    $request = $request->withHeaders(['X-API-Key' => $this->connection->api_key]);
                }

                $status = $request->get($url)->status();
            }
        } catch (ConnectionException $e) {
            return ['ok' => false, 'http_status' => null, 'error' => 'Unable to reach website: connection failed or timed out.'];
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'http_status' => null, 'error' => 'Website request failed.'];
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'http_status' => $status, 'url' => $url];
        }

        if (in_array($status, [401, 403], true)) {
            return ['ok' => false, 'http_status' => $status, 'url' => $url, 'error' => "Website rejected credentials (HTTP {$status})."];
        }

        return ['ok' => false, 'http_status' => $status, 'url' => $url, 'error' => "Website answered with HTTP {$status}."];
    }
}
