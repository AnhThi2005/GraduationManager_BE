<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * uq_dtk_new là UNIQUE trên riêng sinh_vien_id — cùng lớp lỗi đã fix ở diembaocao.uq_dbc_nhom_new
     * (migration 2026_07_06_174252). Một sinh viên có thể có bản ghi diemtongketdatn cũ từ một nhóm
     * khác (VD: đổi nhóm/đề tài giữa chừng), nên chỉ cần sinh_vien_id đã tồn tại ở BẤT KỲ nhóm nào là
     * updateOrInsert(['sinh_vien_id'=>X,'nhom_id'=>Y]) trong DiemSinhVienService::recalculateScores()
     * sẽ cố INSERT (vì WHERE không khớp nhom_id khác) và đụng UNIQUE này -> 1062 -> 500 mỗi lần lưu
     * điểm. Đổi thành UNIQUE composite (nhom_id, sinh_vien_id) để đúng thiết kế "1 dòng điểm tổng kết
     * cho mỗi (nhóm, sinh viên)".
     */
    public function up(): void
    {
        // Tạo index composite mới + 1 index thường cho riêng sinh_vien_id (để fk_dtk_sv_new vẫn có
        // index hỗ trợ, vì sinh_vien_id không đứng đầu composite nên không dùng leftmost-prefix được)
        // TRƯỚC khi drop unique cũ, tránh lỗi 1553 do MySQL không cho drop index đang phục vụ FK.
        Schema::table('diemtongketdatn', function (Blueprint $table) {
            $table->unique(['nhom_id', 'sinh_vien_id'], 'uq_dtk_nhom_sv');
            $table->index('sinh_vien_id', 'idx_dtk_sv');
        });
        Schema::table('diemtongketdatn', function (Blueprint $table) {
            $table->dropUnique('uq_dtk_new');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('diemtongketdatn', function (Blueprint $table) {
            $table->unique('sinh_vien_id', 'uq_dtk_new');
        });
        Schema::table('diemtongketdatn', function (Blueprint $table) {
            $table->dropUnique('uq_dtk_nhom_sv');
            $table->dropIndex('idx_dtk_sv');
        });
    }
};
