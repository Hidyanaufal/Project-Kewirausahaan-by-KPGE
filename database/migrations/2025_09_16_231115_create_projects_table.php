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
        Schema::create('project_types', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->softDeletes();
            $table->string('name');
            $table->string('code');
            $table->foreignId('company_id')->constrained();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('project_type_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('pic_id')->constrained('users');
            $table->string('number')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('request_number_date')->nullable();
            $table->decimal('project_value', 15, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('work_basis')->nullable();
            $table->string('status')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->constrained();
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->constrained();
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
