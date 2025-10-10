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
        Schema::table('estimates', function (Blueprint $table) {
            $table->string('profit_computation')->default('percentage');
            $table->bigInteger('profit_rate')->default(0);
            $table->bigInteger('profit_total')->default(0);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('profit_computation')->default('percentage');
            $table->bigInteger('profit_rate')->default(0);
            $table->bigInteger('profit_total')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('profit_computation');
            $table->dropColumn('profit_rate');
            $table->dropColumn('profit_total');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('profit_computation');
            $table->dropColumn('profit_rate');
            $table->dropColumn('profit_total');
        });
    }
};
