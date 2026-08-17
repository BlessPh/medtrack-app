<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Academic\Models\Student;
use App\Modules\Internship\Models\Rotation;
use App\Shared\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['answers' => 'array', 'score' => 'decimal:2', 'submitted_at' => 'datetime'];
    }

    public function rotation(): BelongsTo
    {
        return $this->belongsTo(Rotation::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
