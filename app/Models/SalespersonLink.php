<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalespersonLink extends Model
{
    protected $fillable = [
        'company_id',
        'gp_id',
        'odoo_user_id',
        'external_id',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
