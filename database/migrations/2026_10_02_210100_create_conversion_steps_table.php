<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('export_key');
            $table->unsignedInteger('sort')->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('odoo_checked')->nullable();
            $table->unsignedInteger('odoo_found')->nullable();
            $table->text('confirm_message')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'export_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_steps');
    }
};
