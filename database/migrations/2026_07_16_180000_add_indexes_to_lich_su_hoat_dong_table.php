<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng lich_su_hoat_dong được ghi liên tục (mọi thao tác của SV/GV đều log vào đây)
     * nhưng chưa có index nào ngoài khóa chính — mọi truy vấn lọc theo sinh_vien_id,
     * ma_so_sinh_vien, nhom_id hoặc sắp xếp theo created_at đang phải quét toàn bảng.
     */
    public function up(): void
    {
        Schema::table('lich_su_hoat_dong', function (Blueprint $table) {
            $table->index('sinh_vien_id');
            $table->index('ma_so_sinh_vien');
            $table->index('nhom_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('lich_su_hoat_dong', function (Blueprint $table) {
            $table->dropIndex(['sinh_vien_id']);
            $table->dropIndex(['ma_so_sinh_vien']);
            $table->dropIndex(['nhom_id']);
            $table->dropIndex(['created_at']);
        });
    }
};
