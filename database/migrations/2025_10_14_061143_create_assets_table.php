<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 100)->unique()->nullable();
            $table->string('name', 255);
            $table->foreignId('group_id')->nullable()->constrained('asset_groups')->nullOnDelete();
            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 18, 2);
            $table->unsignedInteger('useful_life_months')->nullable(); // override default group
            $table->decimal('salvage_value', 18, 2)->default(0);
            $table->enum('depreciation_method', ['garis_lurus', 'saldo_menurun'])->nullable();
            $table->enum('status', ['active', 'disposed', 'fully_depreciated'])->default('active');
            // untuk laporan & audit
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['name', 'asset_code']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
