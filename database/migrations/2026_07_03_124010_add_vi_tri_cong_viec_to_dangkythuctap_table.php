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
        Schema::table('dangkythuctap', function (Blueprint $table) {
            // Vị trí/chức danh công việc thực tập (VD: "Thực tập sinh Backend"),
            // khác với vi_tri_thuc_tap hiện có (đang được dùng cho địa điểm thực tập)
            $table->string('vi_tri_cong_viec')->nullable()->after('vi_tri_thuc_tap');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dangkythuctap', function (Blueprint $table) {
            $table->dropColumn('vi_tri_cong_viec');
        });
    }
};
