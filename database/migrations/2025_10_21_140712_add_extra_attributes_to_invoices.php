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
            $table->string('contract_number', 100)->nullable();
            $table->string('po_reference_number', 100)->nullable();
            $table->date('po_date')->nullable();
            $table->string('spk_number', 100)->nullable();
            $table->date('spk_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('contract_number');
            $table->dropColumn('po_reference_number');
            $table->dropColumn('po_date');
            $table->dropColumn('spk_number');
            $table->dropColumn('spk_date');
        });
    }
};
