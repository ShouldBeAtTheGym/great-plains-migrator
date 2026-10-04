<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('odoo_import_company_id')->nullable()->after('odoo_company');
            $table->string('odoo_import_company_name')->nullable()->after('odoo_import_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['odoo_import_company_id', 'odoo_import_company_name']);
        });
    }
};
