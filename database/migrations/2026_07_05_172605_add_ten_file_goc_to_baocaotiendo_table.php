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
        Schema::table('baocaotiendo', function (Blueprint $table) {
            $table->string('ten_file_goc')->nullable()->after('duong_dan_file');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('baocaotiendo', function (Blueprint $table) {
            $table->dropColumn('ten_file_goc');
        });
    }
};
