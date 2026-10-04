<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('export_key');
            $table->timestamp('last_export_at')->nullable();
            $table->timestamp('baselined_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('gp_count')->nullable();
            $table->unsignedInteger('odoo_count')->nullable();
            $table->unsignedBigInteger('watermark')->default(0);
            $table->unsignedBigInteger('seen_max')->default(0);
            $table->unsignedInteger('capture_offset')->default(0);
            $table->boolean('capture_done')->default(false);
            $table->boolean('verify_ok')->default(false);
            $table->text('verify_message')->nullable();
            $table->text('user_field_note')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'export_key']);
        });

        Schema::create('sync_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('export_key');
            $table->string('record_key', 191);
            $table->json('user_fields')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'export_key', 'record_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_records');
        Schema::dropIfExists('sync_exports');
    }
};
