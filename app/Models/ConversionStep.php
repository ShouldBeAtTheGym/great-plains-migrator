<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversionStep extends Model
{
    protected $fillable = [
        'company_id',
        'export_key',
        'sort',
        'status',
        'completed_at',
        'odoo_checked',
        'odoo_found',
        'confirm_message',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public static function currentFor(Company $company): ?self
    {
        return static::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', 'complete')
            ->orderBy('sort')
            ->orderBy('id')
            ->first();
    }
}
