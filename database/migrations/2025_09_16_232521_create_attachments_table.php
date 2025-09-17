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
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->morphs('attachable'); // Polymorphic relation (attachable_id, attachable_type)
            $table->string('name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->boolean('is_file')->default(true);
            $table->string('file_path')->nullable();
            $table->string('file_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->bigInteger('size')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
