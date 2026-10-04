<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salespad_user_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('model_key');
            $table->string('source_table');
            $table->string('field_name');
            $table->string('label');
            $table->boolean('selected')->default(false);
            $table->string('field_type')->default('text');
            $table->text('options')->nullable();
            $table->boolean('disabled')->default(false);
            $table->string('sql_type')->nullable();
            $table->unsignedInteger('length')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->string('odoo_field')->nullable();
            $table->timestamp('odoo_created_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'model_key', 'field_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salespad_user_fields');
    }
};
