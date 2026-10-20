<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GpCopy extends Model
{
    protected $fillable = [
        'company_id',
        'export_key',
        'status',
        'record_count',
        'processed_count',
        'saved_count',
        'current_record',
        'columns',
        'source_hash',
        'run_token',
        'pid',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'record_count' => 'integer',
            'processed_count' => 'integer',
            'saved_count' => 'integer',
            'columns' => 'array',
            'pid' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
