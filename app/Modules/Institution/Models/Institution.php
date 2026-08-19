<?php

namespace App\Modules\Institution\Models;

use App\Modules\Auth\Models\User;
use App\Shared\Models\HasPublicId;
use Database\Factories\InstitutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use App\Modules\Media\Models\Media;
use Illuminate\Database\Eloquent\SoftDeletes;

class Institution extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected static function newFactory(): InstitutionFactory
    {
        return InstitutionFactory::new();
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'founded_on' => 'date'];
    }

    public function units(): HasMany
    {
        return $this->hasMany(InstitutionUnit::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(InstitutionAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(InstitutionContact::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'institution_memberships',
        )->withPivot(['status', 'suspended_at', 'suspended_by', 'suspension_reason'])->withTimestamps();
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function logo(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')->where('collection', 'LOGO')->latestOfMany();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(InstitutionMemberInvitation::class);
    }
}
