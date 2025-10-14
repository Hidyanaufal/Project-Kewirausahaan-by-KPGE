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
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedBigInteger('acquisition_cost')->change();
            $table->unsignedBigInteger('salvage_value')->default(0)->change();
        });

        Schema::table('depreciation_records', function (Blueprint $table) {
            $table->unsignedBigInteger('opening_fiscal_book_value')->default(0)->change();
            $table->unsignedBigInteger('depreciation_expense')->default(0)->change();
            $table->unsignedBigInteger('closing_fiscal_book_value')->default(0)->change();
        });

        Schema::table('asset_disposals', function (Blueprint $table) {
            $table->unsignedBigInteger('disposal_value')->default(0)->change();
            $table->unsignedBigInteger('gain_loss')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            //
        });
    }
};
