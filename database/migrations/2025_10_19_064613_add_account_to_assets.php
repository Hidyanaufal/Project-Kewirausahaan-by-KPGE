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
        Schema::table('asset_groups', function (Blueprint $table) {
            $table->foreignId('asset_account_id')->nullable()->constrained('accounts');
            $table->foreignId('contra_account_id')->nullable()->constrained('accounts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_groups', function (Blueprint $table) {
            $table->foreignId('asset_account_id')->nullable()->constrained('accounts');
            $table->foreignId('contra_account_id')->nullable()->constrained('accounts');
        });
    }
};
