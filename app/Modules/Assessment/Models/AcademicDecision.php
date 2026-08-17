<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Academic\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicDecision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['final_score' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
