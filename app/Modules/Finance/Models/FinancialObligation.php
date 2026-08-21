<?php

namespace App\Modules\Finance\Models;

use App\Modules\Academic\Models\Student;
use App\Shared\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialObligation extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'due_date' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'obligation_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinancialObligationItem::class, 'obligation_id');
    }

    public function institution(): BelongsTo { return $this->belongsTo(\App\Modules\Institution\Models\Institution::class); }
    public function campaign(): BelongsTo { return $this->belongsTo(\App\Modules\Academic\Models\Campaign::class); }
}
