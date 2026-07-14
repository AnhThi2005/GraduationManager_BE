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
        Schema::table('dot', function (Blueprint $table) {
            $table->date('ngay_bat_dau_phan_bien')->nullable();
            $table->date('ngay_ket_thuc_phan_bien')->nullable();
            $table->date('ngay_bat_dau_bao_ve')->nullable();
            $table->date('ngay_ket_thuc_bao_ve')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dot', function (Blueprint $table) {
            $table->dropColumn([
                'ngay_bat_dau_phan_bien',
                'ngay_ket_thuc_phan_bien',
                'ngay_bat_dau_bao_ve',
                'ngay_ket_thuc_bao_ve'
            ]);
        });
    }
};
