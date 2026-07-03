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
        Schema::table('phanconghdtt', function (Blueprint $table) {
            $table->timestamp('ngay_phan_cong')->nullable()->after('da_cong_bo');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('phanconghdtt', function (Blueprint $table) {
            $table->dropColumn(['ngay_phan_cong', 'deleted_at']);
        });
    }
};
