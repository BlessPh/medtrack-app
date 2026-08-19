<?php

namespace App\Modules\Institution\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HospitalSupervisorProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['stages_enabled' => 'boolean'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(InstitutionUnit::class, 'hospital_supervisor_services', 'supervisor_profile_id', 'institution_unit_id')->withTimestamps();
    }
}
