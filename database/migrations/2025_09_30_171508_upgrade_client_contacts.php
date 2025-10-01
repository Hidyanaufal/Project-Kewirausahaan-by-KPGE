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
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->string('position')->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('pic_client_id')->nullable()->constrained('contacts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['pic_client_id']);
            $table->dropColumn('pic_client_id');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['title', 'position']);
        });
    }
};
