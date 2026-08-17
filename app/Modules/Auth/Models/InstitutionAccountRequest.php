<?php

namespace App\Modules\Auth\Models;

use App\Shared\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionAccountRequest extends Model
{
    use HasPublicId;

    protected $fillable = [
        'reference', 'institution_type', 'institution_name', 'last_name', 'middle_name',
        'first_name', 'gender', 'email', 'phone', 'job_title', 'password_hash', 'status',
        'reviewed_by', 'created_user_id', 'rejection_reason', 'reviewed_at',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }
}
