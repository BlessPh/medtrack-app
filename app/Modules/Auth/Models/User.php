<?php

namespace App\Modules\Auth\Models;

use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\UserStatus;
use App\Shared\Models\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use HasFactory, HasPublicId, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    protected $fillable = ['name', 'username', 'email', 'phone', 'password', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(
            Institution::class,
            'institution_memberships',
        )->withPivot(['status', 'suspended_at', 'suspended_by', 'suspension_reason'])->withTimestamps();
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return ['public_id' => $this->public_id];
    }

    public function receivesBroadcastNotificationsOn(): string
    {
        return 'users.'.$this->public_id;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
