<?php

namespace App\Modules\Academic\Models;

use App\Modules\Institution\Models\Institution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicProgram extends Model
{
    protected $guarded = [];

    public function university(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'university_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Institution\Models\InstitutionUnit::class, 'faculty_unit_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class, 'program_id');
    }
}
