<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncExport extends Model
{
    protected $fillable = [
        'company_id',
        'export_key',
        'last_export_at',
        'baselined_at',
        'downloaded_at',
        'verified_at',
        'record_count',
        'new_count',
        'gp_count',
        'odoo_count',
        'watermark',
        'seen_max',
        'capture_offset',
        'capture_done',
        'verify_ok',
        'verify_message',
        'user_field_note',
    ];

    protected function casts(): array
    {
        return [
            'last_export_at' => 'datetime',
            'baselined_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'verified_at' => 'datetime',
            'record_count' => 'integer',
            'new_count' => 'integer',
            'gp_count' => 'integer',
            'odoo_count' => 'integer',
            'watermark' => 'integer',
            'seen_max' => 'integer',
            'capture_offset' => 'integer',
            'capture_done' => 'boolean',
            'verify_ok' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

}
