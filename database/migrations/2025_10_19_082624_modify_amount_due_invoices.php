<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('amount_due');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('amount_due')->storedAs('actual_total - amount_paid');
        });

        DB::statement("
            UPDATE invoices
            SET actual_total = total
            WHERE actual_total = 0
               OR actual_total IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('amount_due');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('amount_due')->storedAs('total - amount_paid');
        });
    }
};
