<?php

namespace App\Modules\Media\Models;

use App\Modules\Auth\Models\User;
use App\Shared\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Media extends Model
{
    use HasPublicId;

    protected $table = 'media';
    protected $guarded = [];
    protected $hidden = ['disk', 'path', 'checksum', 'mediable_type', 'mediable_id'];

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
