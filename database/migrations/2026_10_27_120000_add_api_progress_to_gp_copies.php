<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_copies', function (Blueprint $table) {
            $table->unsignedInteger('api_loaded')->default(0);
            $table->unsignedInteger('api_failed')->default(0);
            $table->json('api_errors')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gp_copies', function (Blueprint $table) {
            $table->dropColumn(['api_loaded', 'api_failed', 'api_errors']);
        });
    }
};
