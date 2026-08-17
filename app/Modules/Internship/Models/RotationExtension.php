<?php

namespace App\Modules\Internship\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RotationExtension extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['previous_end_date' => 'date', 'new_end_date' => 'date'];
    }

    public function rotation(): BelongsTo
    {
        return $this->belongsTo(Rotation::class);
    }
}
