<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalespadUserField extends Model
{
    protected $fillable = [
        'company_id',
        'model_key',
        'source_table',
        'field_name',
        'label',
        'selected',
        'field_type',
        'options',
        'disabled',
        'sql_type',
        'length',
        'sort',
        'odoo_field',
        'odoo_created_at',
    ];

    protected function casts(): array
    {
        return [
            'selected' => 'boolean',
            'disabled' => 'boolean',
            'odoo_created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function optionList(): array
    {
        return app(\App\Services\SalespadUserFields::class)->optionList($this->options);
    }
}
