<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('is_imported')->default(false);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_imported')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('is_imported');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('is_imported');
        });
    }
};
