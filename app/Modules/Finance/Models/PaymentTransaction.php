<?php

namespace App\Modules\Finance\Models;

use App\Modules\Academic\Models\Student;
use App\Shared\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTransaction extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'transaction_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class, 'transaction_id');
    }

    public function institution(): BelongsTo { return $this->belongsTo(\App\Modules\Institution\Models\Institution::class); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'recorded_by'); }
    public function verifiedBy(): BelongsTo { return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'verified_by'); }
}
