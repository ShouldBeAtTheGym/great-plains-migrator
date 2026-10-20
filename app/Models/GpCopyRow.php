<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GpCopyRow extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'export_key',
        'row_no',
        'record_key',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'row_no' => 'integer',
        ];
    }
}
