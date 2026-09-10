<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const INTERNAL_KEY = 'test-internal-key-12345';

    protected function setUp(): void
    {
        parent::setUp();

        // Hard safety: destructive tests must NEVER touch auth_db.
        $db = DB::connection()->getDatabaseName();
        $this->assertSame('auth_test_db', $db, 'Refusing to run: test database is not auth_test_db.');

        // JSON test requests only carry cookies with credentials enabled,
        // mirroring the browser's withCredentials behavior.
        $this->withCredentials();
    }

    private function makeOwner(string $email = 'owner@example.com'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_OWNER,
            'is_active' => true,
        ]);
    }

    private function rawTokenFor(User $user, $expiresAt = null): string
    {
        return $user->createToken('auth', ['*'], $expiresAt ?? now()->addHours(12))->plainTextToken;
    }

    private function asUser(User $user, ?string $token = null): static
    {
        return $this->withUnencryptedCookie('auth_token', $token ?? $this->rawTokenFor($user));
    }

    private function introspect(?string $token, ?string $key = self::INTERNAL_KEY): \Illuminate\Testing\TestResponse
    {
        $headers = ['Accept' => 'application/json'];
        if ($key !== null) {
            $headers['X-Internal-Key'] = $key;
        }
        if ($token !== null) {
            $headers['X-Auth-Token'] = $token;
        }

        return $this->postJson('/internal/v1/auth/introspect', [], $headers);
    }

    // ---------- HEALTH ----------

    public function test_01_health_returns_200_with_mysql(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'service' => 'auth', 'database' => 'up']);
    }

    // ---------- LOGIN ----------

    public function test_02_valid_owner_login_succeeds(): void
    {
        $this->makeOwner();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ])->assertOk()->assertJsonPath('user.role', 'OWNER');
    }

    public function test_03_email_normalization_works(): void
    {
        $this->makeOwner('owner@example.com');

        $this->postJson('/api/v1/auth/login', [
            'email' => '  OWNER@Example.COM  ',
            'password' => 'secret123',
        ])->assertOk()->assertJsonPath('user.email', 'owner@example.com');
    }

    public function test_04_wrong_password_rejected(): void
    {
        $this->makeOwner();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'wrongpass1',
        ])->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_05_unknown_email_generic_rejection(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever123',
        ])->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_06_inactive_user_rejected(): void
    {
        $user = $this->makeOwner();
        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ])->assertStatus(422);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_07_auth_token_cookie_set(): void
    {
        $this->makeOwner();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ])->assertOk()->assertCookie('auth_token');

        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_08_auth_cookie_httponly(): void
    {
        $this->makeOwner();

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $raw = (string) $res->headers->get('Set-Cookie');
        $this->assertStringContainsStringIgnoringCase('httponly', $raw);
        $this->assertStringNotContainsStringIgnoringCase('samesite=none', $raw);
    }

    public function test_09_raw_token_not_returned_in_json(): void
    {
        $this->makeOwner();

        $body = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ])->assertOk()->json();

        $this->assertArrayNotHasKey('token', $body);
        $this->assertArrayNotHasKey('plainTextToken', $body);
        $this->assertArrayNotHasKey('accessToken', $body);
        $this->assertSame(['id', 'name', 'email', 'role', 'is_active', 'last_login_at', 'created_at', 'updated_at'], array_keys($body['user']));
    }

    public function test_10_login_audit_created(): void
    {
        $this->makeOwner();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN_SUCCESS']);
    }

    public function test_11_password_absent_from_audit(): void
    {
        $this->makeOwner();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ])->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'wrongpass1',
        ])->assertStatus(422);

        foreach (AuditLog::all() as $log) {
            $dump = json_encode($log->metadata);
            $this->assertStringNotContainsStringIgnoringCase('secret123', $dump);
            $this->assertStringNotContainsStringIgnoringCase('wrongpass1', $dump);
        }
    }

    // ---------- ME ----------

    public function test_12_unauthenticated_me_is_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_13_authenticated_me_is_200(): void
    {
        $owner = $this->makeOwner();

        $this->asUser($owner)->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.email', 'owner@example.com');
    }

    public function test_14_revoked_token_rejected(): void
    {
        $owner = $this->makeOwner();
        $token = $this->rawTokenFor($owner);
        PersonalAccessToken::query()->delete();

        $this->withUnencryptedCookie('auth_token', $token)
            ->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_expired_token_rejected(): void
    {
        $owner = $this->makeOwner();
        $token = $this->rawTokenFor($owner, now()->subMinute());

        $this->withUnencryptedCookie('auth_token', $token)
            ->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    // ---------- LOGOUT ----------

    public function test_15_logout_succeeds(): void
    {
        $owner = $this->makeOwner();

        $this->asUser($owner)->postJson('/api/v1/auth/logout')
            ->assertOk()->assertJsonPath('message', 'Logged out.');
    }

    public function test_16_cookie_cleared(): void
    {
        $owner = $this->makeOwner();

        $this->asUser($owner)->postJson('/api/v1/auth/logout')
            ->assertOk()->assertCookieExpired('auth_token');
    }

    public function test_17_token_revoked(): void
    {
        $owner = $this->makeOwner();
        $token = $this->rawTokenFor($owner);
        $id = PersonalAccessToken::query()->latest('id')->first()->id;

        $this->withUnencryptedCookie('auth_token', $token)
            ->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
    }

    public function test_18_old_token_introspection_fails(): void
    {
        $owner = $this->makeOwner();
        $token = $this->rawTokenFor($owner);

        $this->withUnencryptedCookie('auth_token', $token)
            ->postJson('/api/v1/auth/logout')->assertOk();

        $this->introspect($token)->assertOk()->assertJson(['valid' => false]);
    }

    // ---------- INTROSPECTION ----------

    public function test_19_missing_internal_key_rejected(): void
    {
        $owner = $this->makeOwner();

        $this->introspect($this->rawTokenFor($owner), null)->assertUnauthorized();
    }

    public function test_20_wrong_internal_key_rejected(): void
    {
        $owner = $this->makeOwner();

        $res = $this->introspect($this->rawTokenFor($owner), 'wrong-key');
        $res->assertUnauthorized();
        $this->assertStringNotContainsString('test-internal-key', $res->getContent());
    }

    public function test_21_missing_auth_token_rejected(): void
    {
        $this->postJson('/internal/v1/auth/introspect', [], [
            'Accept' => 'application/json',
            'X-Internal-Key' => self::INTERNAL_KEY,
        ])->assertUnauthorized()->assertJson(['valid' => false]);
    }

    public function test_22_valid_token_introspects_correctly(): void
    {
        $user = User::factory()->create([
            'name' => 'Ilyass', 'email' => 'ilyass@example.com',
            'password' => Hash::make('secret123'), 'role' => 'OWNER', 'is_active' => true,
        ]);

        $this->introspect($this->rawTokenFor($user))
            ->assertOk()
            ->assertExactJson(['valid' => true, 'user' => [
                'id' => $user->id, 'name' => 'Ilyass',
                'email' => 'ilyass@example.com', 'role' => 'OWNER',
            ]]);
    }

    public function test_23_revoked_token_invalid(): void
    {
        $owner = $this->makeOwner();
        $token = $this->rawTokenFor($owner);
        PersonalAccessToken::query()->delete();

        $this->introspect($token)->assertOk()->assertJson(['valid' => false]);
    }

    public function test_24_inactive_user_token_invalid(): void
    {
        $owner = $this->makeOwner();
        $token = $this->rawTokenFor($owner);
        $owner->forceFill(['is_active' => false])->save();

        $this->introspect($token)->assertOk()->assertJson(['valid' => false]);
    }

    public function test_25_introspection_never_returns_secrets(): void
    {
        $owner = $this->makeOwner();

        $body = $this->introspect($this->rawTokenFor($owner))->assertOk()->json();

        $dump = json_encode($body);
        $this->assertArrayNotHasKey('token', $body);
        $this->assertArrayNotHasKey('password', $body['user']);
        $this->assertStringNotContainsString('test-internal-key', $dump);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'INTROSPECT']);
    }

    // ---------- USERS ----------

    public function test_26_admin_cannot_list_users(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->asUser($admin)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_27_staff_cannot_list_users(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);

        $this->asUser($staff)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_28_owner_can_list_users(): void
    {
        $owner = $this->makeOwner();

        $this->asUser($owner)->getJson('/api/v1/users')
            ->assertOk()->assertJsonStructure(['data', 'meta']);
    }

    public function test_29_owner_creates_admin(): void
    {
        $owner = $this->makeOwner();

        $this->asUser($owner)->postJson('/api/v1/users', [
            'name' => 'Amina', 'email' => 'admin@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'ADMIN',
        ])->assertCreated()->assertJsonPath('user.role', 'ADMIN');
    }

    public function test_30_owner_creates_staff(): void
    {
        $owner = $this->makeOwner();

        $this->asUser($owner)->postJson('/api/v1/users', [
            'name' => 'Yassine', 'email' => 'staff@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'STAFF',
        ])->assertCreated();
    }

    public function test_31_duplicate_email_rejected(): void
    {
        $owner = $this->makeOwner();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->asUser($owner)->postJson('/api/v1/users', [
            'name' => 'Dup', 'email' => 'TAKEN@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'STAFF',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_32_email_normalized(): void
    {
        $owner = $this->makeOwner();

        $this->asUser($owner)->postJson('/api/v1/users', [
            'name' => 'Norm', 'email' => '  NORM@Example.COM ',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'STAFF',
        ])->assertCreated()->assertJsonPath('user.email', 'norm@example.com');
    }

    public function test_33_invalid_role_rejected(): void
    {
        $owner = $this->makeOwner();

        $this->asUser($owner)->postJson('/api/v1/users', [
            'name' => 'Bad', 'email' => 'bad@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'SUPERADMIN',
        ])->assertStatus(422)->assertJsonValidationErrors(['role']);
    }

    public function test_34_owner_can_view_user(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create();

        $res = $this->asUser($owner)->getJson("/api/v1/users/{$staff->id}")->assertOk();
        $this->assertArrayNotHasKey('password', $res->json('user'));
    }

    public function test_35_owner_can_update_user(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create();

        $this->asUser($owner)->patchJson("/api/v1/users/{$staff->id}", [
            'name' => 'Renamed',
        ])->assertOk()->assertJsonPath('user.name', 'Renamed');
    }

    public function test_36_password_hash_never_returned(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create();

        foreach ([
            $this->asUser($owner)->getJson('/api/v1/users')->assertOk()->json('data'),
        ] as $list) {
            foreach ($list as $row) {
                $this->assertArrayNotHasKey('password', $row);
            }
        }

        $this->assertArrayNotHasKey('password', $this->asUser($owner)->getJson("/api/v1/users/{$staff->id}")->assertOk()->json('user'));
        $this->assertArrayNotHasKey('password', $this->asUser($owner)->getJson('/api/v1/auth/me')->assertOk()->json('user'));
    }

    public function test_37_optional_password_update_hashes_password(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create([
            'email' => 'changeme@example.com',
            'password' => Hash::make('oldpass123'),
        ]);

        $this->asUser($owner)->patchJson("/api/v1/users/{$staff->id}", [
            'password' => 'newpass456', 'password_confirmation' => 'newpass456',
        ])->assertOk();

        $fresh = User::find($staff->id);
        $this->assertTrue(Hash::check('newpass456', $fresh->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'changeme@example.com', 'password' => 'newpass456',
        ])->assertOk();
    }

    public function test_38_owner_can_deactivate_user(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create();

        $this->asUser($owner)->deleteJson("/api/v1/users/{$staff->id}")
            ->assertOk()->assertJsonPath('user.is_active', false);
    }

    public function test_39_database_row_remains_after_deactivate(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create();

        $this->asUser($owner)->deleteJson("/api/v1/users/{$staff->id}")->assertOk();

        $this->assertDatabaseHas('users', ['id' => $staff->id, 'is_active' => false]);
    }

    public function test_40_all_deactivated_user_tokens_revoked(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create();
        $t1 = $this->rawTokenFor($staff);
        $t2 = $this->rawTokenFor($staff);
        $this->assertSame(2, $staff->tokens()->count());

        $this->asUser($owner)->deleteJson("/api/v1/users/{$staff->id}")->assertOk();

        $this->assertSame(0, User::find($staff->id)->tokens()->count());
        $this->withUnencryptedCookie('auth_token', $t1)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withUnencryptedCookie('auth_token', $t2)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->introspect($t1)->assertOk()->assertJson(['valid' => false]);
    }

    public function test_41_owner_can_reactivate_user(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create([
            'email' => 'back@example.com', 'password' => Hash::make('secret123'),
            'is_active' => false,
        ]);

        $this->asUser($owner)->postJson("/api/v1/users/{$staff->id}/activate")
            ->assertOk()->assertJsonPath('user.is_active', true);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'back@example.com', 'password' => 'secret123',
        ])->assertOk();
    }

    public function test_42_last_active_owner_cannot_be_deactivated(): void
    {
        $owner = $this->makeOwner();

        $this->asUser($owner)->deleteJson("/api/v1/users/{$owner->id}")->assertStatus(422);

        $this->assertTrue(User::find($owner->id)->is_active);
    }

    public function test_43_last_active_owner_cannot_be_downgraded(): void
    {
        $owner = $this->makeOwner();

        foreach (['ADMIN', 'STAFF'] as $role) {
            $this->asUser($owner)->patchJson("/api/v1/users/{$owner->id}", [
                'role' => $role,
            ])->assertStatus(422);
        }

        $this->assertSame('OWNER', User::find($owner->id)->role);
    }

    // ---------- AUDIT ----------

    public function test_44_user_creation_audit_exists(): void
    {
        $owner = $this->makeOwner();

        $res = $this->asUser($owner)->postJson('/api/v1/users', [
            'name' => 'A', 'email' => 'a@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'STAFF',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'USER_CREATED', 'entity_type' => 'user',
            'entity_id' => (string) $res->json('user.id'),
        ]);
    }

    public function test_45_update_audit_exists(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create();

        $this->asUser($owner)->patchJson("/api/v1/users/{$staff->id}", ['name' => 'X'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_UPDATED']);
    }

    public function test_46_deactivation_audit_exists(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create();

        $this->asUser($owner)->deleteJson("/api/v1/users/{$staff->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_DEACTIVATED']);
    }

    public function test_47_activation_audit_exists(): void
    {
        $owner = $this->makeOwner();
        $staff = User::factory()->create(['is_active' => false]);

        $this->asUser($owner)->postJson("/api/v1/users/{$staff->id}/activate")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_ACTIVATED']);
    }

    public function test_48_audit_api_owner_only(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
        $this->asUser($admin)->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_49_no_raw_auth_tokens_in_audit(): void
    {
        $owner = $this->makeOwner();
        $token = $this->rawTokenFor($owner);
        $hash = (string) PersonalAccessToken::query()->latest('id')->value('token');

        $this->withUnencryptedCookie('auth_token', $token)->postJson('/api/v1/auth/logout')->assertOk();

        foreach (AuditLog::all() as $log) {
            $dump = json_encode($log->metadata);
            $this->assertStringNotContainsString($token, $dump);
            $this->assertStringNotContainsString($hash, $dump);
        }
    }

    // ---------- RATE LIMIT ----------

    public function test_50_repeated_failed_logins_trigger_rate_limit(): void
    {
        $this->makeOwner();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'owner@example.com', 'password' => 'wrongpass1',
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com', 'password' => 'wrongpass1',
        ])->assertStatus(429);
    }

    // ---------- SESSION REGRESSION ----------

    public function test_session_driver_is_not_database(): void
    {
        // Token auth must never depend on database sessions. The test env
        // itself uses array sessions; local/Docker runtime uses file.
        $this->assertNotSame('database', config('session.driver'));
        $this->assertSame('auth-session', config('session.cookie'));

        // Guard the shipped local default (phpunit overrides SESSION_DRIVER
        // at runtime, so assert the file itself too).
        $env = @file_get_contents(base_path('.env')) ?: '';
        $this->assertStringContainsString('SESSION_DRIVER=file', $env);
        $this->assertStringContainsString('SESSION_COOKIE=auth-session', $env);
    }

    public function test_app_works_without_sessions_table(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('sessions'),
            'A sessions table must not exist for token auth.'
        );

        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/')->assertOk()->assertJson(['service' => 'auth']);
    }

    public function test_root_returns_service_info(): void
    {
        $this->getJson('/')->assertOk()->assertExactJson(['service' => 'auth', 'status' => 'ok']);
    }

    public function test_logout_audit_exists(): void
    {
        $owner = $this->makeOwner();

        $this->asUser($owner)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'LOGOUT']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TOKEN_REVOKED']);
    }

    public function test_me_rejected_after_logout(): void
    {
        $owner = $this->makeOwner();
        $token = $this->rawTokenFor($owner);

        $this->withUnencryptedCookie('auth_token', $token)
            ->postJson('/api/v1/auth/logout')->assertOk();

        $this->withUnencryptedCookie('auth_token', $token)
            ->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_token_hash_stored_via_sanctum(): void
    {
        $owner = $this->makeOwner();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $row = PersonalAccessToken::query()->latest('id')->firstOrFail();
        $this->assertSame($owner->id, $row->tokenable_id);
        $this->assertNotNull($row->expires_at);
    }
}
