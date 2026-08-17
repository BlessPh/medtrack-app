<?php

namespace App\Modules\Internship\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PathTemplate extends Model
{
    protected $guarded = [];

    public function steps(): HasMany
    {
        return $this->hasMany(PathStep::class)->orderBy('position');
    }
}
