<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->bigInteger('pph23')->nullable()->after('total');
            $table->bigInteger('actual_total')->default(0)->after('pph23');
        });

        DB::statement("
            UPDATE bills
            SET actual_total = total
            WHERE actual_total = 0
               OR actual_total IS NULL
        ");

        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('amount_due');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->bigInteger('amount_due')->storedAs('actual_total - amount_paid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('amount_due');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->bigInteger('amount_due')->storedAs('total - amount_paid');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['pph23', 'actual_total']);
        });
    }
};
