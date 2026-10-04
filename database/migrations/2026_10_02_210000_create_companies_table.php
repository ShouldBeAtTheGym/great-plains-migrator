<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Company');
            $table->string('gp_server')->nullable();
            $table->string('gp_database')->nullable();
            $table->string('gp_username')->nullable();
            $table->text('gp_password')->nullable();
            $table->boolean('gp_trust_cert')->default(true);
            $table->timestamp('gp_tested_at')->nullable();
            $table->boolean('salespad_installed')->default(false);
            $table->json('salespad_report')->nullable();
            $table->string('gp_company_name')->nullable();
            $table->string('gp_site_name')->nullable();
            $table->text('gp_address')->nullable();
            $table->string('gp_phone')->nullable();
            $table->string('gp_version')->nullable();
            $table->string('gp_currency')->nullable();
            $table->string('odoo_url')->nullable();
            $table->string('odoo_database')->nullable();
            $table->string('odoo_username')->nullable();
            $table->text('odoo_api_key')->nullable();
            $table->boolean('odoo_trust_tls')->default(false);
            $table->timestamp('odoo_tested_at')->nullable();
            $table->string('odoo_company_name')->nullable();
            $table->string('odoo_version')->nullable();
            $table->json('odoo_company')->nullable();
            $table->timestamp('preflight_passed_at')->nullable();
            $table->json('preflight_report')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
