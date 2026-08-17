<?php

namespace App\Modules\Academic\Models;

use App\Modules\Institution\Models\Institution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Modules\Admission\Models\CapacityPool;
use App\Modules\Media\Models\Media;

class CampaignHospital extends Model
{
    protected $guarded = [];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'hospital_id');
    }

    public function capacityPools(): HasMany { return $this->hasMany(CapacityPool::class); }
    public function media(): MorphMany { return $this->morphMany(Media::class, 'mediable'); }
}
