<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dot_sinhvien', function (Blueprint $table) {
            $table->string('dieu_kien_lam_do_an', 20)->default('DAT');
        });
    }

    public function down(): void
    {
        Schema::table('dot_sinhvien', function (Blueprint $table) {
            $table->dropColumn('dieu_kien_lam_do_an');
        });
    }
};
