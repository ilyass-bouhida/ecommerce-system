<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for Auth's introspection endpoint. The ONLY integration
 * with the Auth microservice. Never logs tokens or the internal key.
 *
 * The optional $sender override (fn(string $url, string $token): array)
 * exists so tests can simulate transport outcomes without network access.
 * It must return ['status' => int, 'body' => array].
 */
class AuthClient
{
    public function __construct(private $sender = null) {}

    /**
     * @return array{state:string, user?:array{id:int,name:string,email:string,role:string}}
     *   state is one of: ok | invalid | unavailable
     */
    public function introspect(?string $rawToken): array
    {
        if ($rawToken === null || trim($rawToken) === '') {
            return ['state' => 'invalid'];
        }

        $url = rtrim((string) config('website.auth_internal_url'), '/').'/internal/v1/auth/introspect';
        $key = (string) config('website.auth_internal_key');

        try {
            if ($this->sender) {
                $result = ($this->sender)($url, trim($rawToken));
                $status = (int) ($result['status'] ?? 0);
                $body = (array) ($result['body'] ?? []);
            } else {
                $response = Http::timeout((int) config('website.auth_timeout', 5))
                    ->connectTimeout((int) config('website.auth_connect_timeout', 2))
                    ->acceptJson()
                    ->withHeaders([
                        'X-Internal-Key' => $key,
                        'X-Auth-Token' => trim($rawToken),
                    ])
                    ->post($url);
                $status = $response->status();
                $body = $response->json() ?? [];
            }
        } catch (ConnectionException $e) {
            return ['state' => 'unavailable'];
        } catch (\Throwable $e) {
            report($e);

            return ['state' => 'unavailable'];
        }

        if ($status >= 500) {
            return ['state' => 'unavailable'];
        }

        if (($body['valid'] ?? false) !== true || ! isset($body['user']['id'])) {
            return ['state' => 'invalid'];
        }

        return [
            'state' => 'ok',
            'user' => [
                'id' => (int) $body['user']['id'],
                'name' => (string) ($body['user']['name'] ?? ''),
                'email' => (string) ($body['user']['email'] ?? ''),
                'role' => (string) ($body['user']['role'] ?? ''),
            ],
        ];
    }
}
