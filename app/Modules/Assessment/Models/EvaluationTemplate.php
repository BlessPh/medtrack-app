<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Institution\Models\Institution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'maximum_score' => 'decimal:2'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
