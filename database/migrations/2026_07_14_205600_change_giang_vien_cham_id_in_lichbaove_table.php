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
        Schema::table('lichbaove', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['giang_vien_cham_id']);
        });

        Schema::table('lichbaove', function (Blueprint $table) {
            // Change type to text to store multiple IDs
            $table->text('giang_vien_cham_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lichbaove', function (Blueprint $table) {
            // Revert type to integer and add foreign key back
            $table->integer('giang_vien_cham_id')->nullable()->change();
            $table->foreign('giang_vien_cham_id')->references('giang_vien_id')->on('giangvien')->onDelete('set null');
        });
    }
};
