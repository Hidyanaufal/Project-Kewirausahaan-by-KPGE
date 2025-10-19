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
            /**
             * ppn wapu         = sub_total - (sub_total_pph23)
             * ppn non_wapu     = sub_total + tax - (sub_total_pph23)
             */
            $table->bigInteger('actual_total')->default(0); // field untuk simpan nilai invoice sebenarnya tanpa pajak
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('actual_total');
        });
    }
};
