<?php

namespace App\Modules\Academic\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Modules\Scheduling\Models\AttendanceRecord;
use App\Shared\Models\HasPublicId;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected static function newFactory(): StudentFactory
    {
        return StudentFactory::new();
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'birth_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'university_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->where($field ?? 'public_id', $value)->first();
    }
}
