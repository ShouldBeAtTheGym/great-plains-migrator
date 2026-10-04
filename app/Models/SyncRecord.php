<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRecord extends Model
{
    protected $fillable = [
        'company_id',
        'export_key',
        'record_key',
        'user_fields',
        'exported_at',
    ];

    protected function casts(): array
    {
        return [
            'user_fields' => 'array',
            'exported_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
