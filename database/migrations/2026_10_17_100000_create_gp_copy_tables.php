<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('export_key');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('saved_count')->default(0);
            $table->string('current_record', 180)->nullable();
            $table->json('columns')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('run_token', 36)->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'export_key']);
        });

        Schema::create('gp_copy_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('export_key');
            $table->unsignedInteger('row_no');
            $table->string('record_key', 180);
            $table->longText('payload');

            $table->unique(['company_id', 'export_key', 'row_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_copy_rows');
        Schema::dropIfExists('gp_copies');
    }
};
