<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * uq_dbc_nhom_new là UNIQUE trên riêng nhom_id, nhưng diembaocao lưu điểm theo TỪNG sinh viên
     * (có cột sinh_vien_id, diem_gvhd, diem_gvpb riêng cho mỗi SV). Với nhóm ≥2 thành viên, SV đầu
     * tiên được chấm/sửa điểm sẽ "chiếm" duy nhất dòng của nhom_id đó; mọi SV còn lại insert sau đó
     * đều đụng UNIQUE này và lỗi 1062 Duplicate entry -> 500. Đổi thành UNIQUE composite theo cả
     * (nhom_id, sinh_vien_id) để mỗi SV trong nhóm có 1 dòng điểm riêng, khớp đúng thiết kế bảng.
     */
    public function up(): void
    {
        // uq_dbc_nhom_new còn đang gánh vai trò index hỗ trợ khóa ngoại fk_dbc_nhom_new (nhom_id),
        // nên phải tạo index composite mới (nhom_id đứng đầu, MySQL vẫn dùng được cho FK) TRƯỚC,
        // rồi mới drop index cũ — không thể drop trước vì MySQL sẽ báo lỗi 1553.
        Schema::table('diembaocao', function (Blueprint $table) {
            $table->unique(['nhom_id', 'sinh_vien_id'], 'uq_dbc_nhom_sv');
        });
        Schema::table('diembaocao', function (Blueprint $table) {
            $table->dropUnique('uq_dbc_nhom_new');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('diembaocao', function (Blueprint $table) {
            $table->unique('nhom_id', 'uq_dbc_nhom_new');
        });
        Schema::table('diembaocao', function (Blueprint $table) {
            $table->dropUnique('uq_dbc_nhom_sv');
        });
    }
};
