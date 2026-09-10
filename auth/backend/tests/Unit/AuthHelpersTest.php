<?php

namespace Tests\Unit;

use App\Support\AuthCookie;
use App\Support\EmailNormalizer;
use App\Support\InternalKey;
use Tests\TestCase;

class AuthHelpersTest extends TestCase
{
    public function test_email_normalization_trims_and_lowercases(): void
    {
        $this->assertSame('owner@example.com', EmailNormalizer::normalize('  OWNER@Example.COM  '));
        $this->assertSame('a@b.co', EmailNormalizer::normalize('a@b.co'));
        $this->assertSame('', EmailNormalizer::normalize('   '));
    }

    public function test_internal_key_accepts_exact_match_only(): void
    {
        config(['auth_token.internal_key' => 'correct-key-123']);

        $this->assertTrue(InternalKey::valid('correct-key-123'));
        $this->assertFalse(InternalKey::valid('wrong-key'));
        $this->assertFalse(InternalKey::valid(''));
        $this->assertFalse(InternalKey::valid(null));
        $this->assertFalse(InternalKey::valid('correct-key-1234'));
        $this->assertFalse(InternalKey::valid('CORRECT-KEY-123'));
    }

    public function test_internal_key_rejects_everything_when_unconfigured(): void
    {
        config(['auth_token.internal_key' => '']);

        $this->assertFalse(InternalKey::valid('anything'));
    }

    public function test_auth_cookie_is_httponly_lax_with_configured_ttl(): void
    {
        config(['auth_token.cookie' => 'auth_token', 'auth_token.ttl_minutes' => 720]);

        $cookie = AuthCookie::make('raw-token-value');

        $this->assertSame('auth_token', $cookie->getName());
        $this->assertSame('raw-token-value', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertEqualsWithDelta(time() + 720 * 60, $cookie->getExpiresTime(), 5);
    }

    public function test_auth_cookie_forget_clears_value(): void
    {
        $cookie = AuthCookie::forget();

        $this->assertSame('auth_token', $cookie->getName());
        $this->assertTrue($cookie->getExpiresTime() < time());
    }
}
