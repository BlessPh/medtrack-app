<?php

namespace App\Modules\Institution\Models;

use Illuminate\Database\Eloquent\Model;

class InstitutionAuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
