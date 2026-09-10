<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_OWNER = 'OWNER';

    public const ROLE_ADMIN = 'ADMIN';

    public const ROLE_STAFF = 'STAFF';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_STAFF];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isLastActiveOwner(): bool
    {
        if ($this->role !== self::ROLE_OWNER || ! $this->is_active) {
            return false;
        }

        return static::query()
            ->where('role', self::ROLE_OWNER)
            ->where('is_active', true)
            ->where('id', '!=', $this->id)
            ->doesntExist();
    }

    /**
     * Safe public representation — never includes the password hash.
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => (bool) $this->is_active,
            'last_login_at' => $this->last_login_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
