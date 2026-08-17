<?php

namespace App\Modules\Internship\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PathStep extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PathTemplate::class, 'path_template_id');
    }
}
