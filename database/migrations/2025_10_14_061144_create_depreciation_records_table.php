<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('depreciation_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->year('fiscal_year');
            $table->decimal('opening_fiscal_book_value', 18, 2)->default(0); // nilai sisa buku fiskal awal tahun
            $table->decimal('depreciation_expense', 18, 2)->default(0); // beban penyusutan fiskal tahun berjalan
            $table->decimal('closing_fiscal_book_value', 18, 2)->default(0); // nilai sisa buku fiskal akhir tahun
            $table->string('commercial_method', 50)->nullable(); // catat metode yang dipakai di tahun tsb (kalau beda2)
            $table->string('fiscal_method', 50)->nullable();
            // optional: bulan-per-bulan untuk rekonsiliasi (aggregate)
            $table->json('monthly_breakdown')->nullable(); // [{month:1,expense:...}, ...]
            $table->timestamps();
            $table->unique(['asset_id', 'fiscal_year']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('depreciation_records');
    }
};
