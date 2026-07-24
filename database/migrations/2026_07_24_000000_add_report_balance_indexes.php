<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes aligned with report balance aggregation filters
     * (company-scoped transactions by posted_at, journal lines by account).
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['company_id', 'posted_at'], 'transactions_company_id_posted_at_index');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['company_id', 'account_id'], 'journal_entries_company_id_account_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_company_id_posted_at_index');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('journal_entries_company_id_account_id_index');
        });
    }
};
