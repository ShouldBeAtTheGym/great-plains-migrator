<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesperson_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('gp_id');
            $table->unsignedInteger('odoo_user_id');
            $table->string('external_id');
            $table->timestamps();

            $table->unique(['company_id', 'gp_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesperson_links');
    }
};
