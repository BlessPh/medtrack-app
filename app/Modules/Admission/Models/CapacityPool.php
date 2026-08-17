<?php

namespace App\Modules\Admission\Models;

use App\Modules\Academic\Models\CampaignHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapacityPool extends Model
{
    protected $guarded = [];

    public function campaignHospital(): BelongsTo
    {
        return $this->belongsTo(CampaignHospital::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(CapacityReservation::class);
    }
}
