<?php

namespace App\Modules\Academic\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentImport extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['error_report' => 'array', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function university(): BelongsTo { return $this->belongsTo(Institution::class); }
    public function promotion(): BelongsTo { return $this->belongsTo(Promotion::class); }
    public function academicYear(): BelongsTo { return $this->belongsTo(AcademicYear::class, 'academic_year_reference_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
