<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'phone',
        'company',
        'role',
        'is_active',
        'otp',
        'otp_expires_at',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'otp',
        'otp_hash',
        'otp_attempts',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'otp_expires_at'    => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        // Lets admin middleware reject tokens issued before a password change.
        return ['pwv' => $this->passwordVersion()];
    }

    /** Short keyed fingerprint of the current password hash (never the hash itself). */
    public function passwordVersion(): string
    {
        return substr(hash_hmac('sha256', (string) $this->password, (string) config('app.key')), 0, 16);
    }

    /** True when the current request's JWT was issued for this user's current password. */
    public function tokenMatchesCurrentPassword(): bool
    {
        return auth('api')->payload()->get('pwv') === $this->passwordVersion();
    }

    /** An admin account that is currently active (deactivated admins get no admin access). */
    public function isAdmin(): bool
    {
        return $this->role === 'admin' && (int) $this->is_active === 1;
    }

    public function isInternalTeam(): bool
    {
        return $this->role === 'internal_team';
    }

    public function isApprovedPartner(): bool
    {
        return $this->role === 'referral_partner' && $this->is_active === 1;
    }
}
