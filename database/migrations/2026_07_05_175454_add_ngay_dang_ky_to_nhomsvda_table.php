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
        Schema::table('nhomsvda', function (Blueprint $table) {
            $table->timestamp('ngay_dang_ky')->nullable()->after('trang_thai_duyet');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhomsvda', function (Blueprint $table) {
            $table->dropColumn('ngay_dang_ky');
        });
    }
};
