<?php

namespace App\Modules\Internship\Models;

use App\Modules\Admission\Models\Admission;
use App\Shared\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Internship extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function rotations(): HasMany
    {
        return $this->hasMany(Rotation::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Academic\Models\Student::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'supervisor_id');
    }

    public function pathTemplate(): BelongsTo
    {
        return $this->belongsTo(PathTemplate::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(SupervisorObservation::class);
    }
}
