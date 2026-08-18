<?php

namespace App\Modules\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Modules\Media\Models\Media;
use App\Shared\Models\HasPublicId;

class Campaign extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'rules' => 'array'];
    }

    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class, 'campaign_promotions');
    }

    public function hospitals(): HasMany
    {
        return $this->hasMany(CampaignHospital::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_reference_id');
    }

    public function university(): BelongsTo { return $this->belongsTo(\App\Modules\Institution\Models\Institution::class, 'university_id'); }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        $data['id'] = $this->public_id;
        unset($data['public_id']);

        return $data;
    }
}
