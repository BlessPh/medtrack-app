<?php

namespace App\Modules\Media\Models;

use App\Shared\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasPublicId, SoftDeletes;

    protected $guarded = [];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
