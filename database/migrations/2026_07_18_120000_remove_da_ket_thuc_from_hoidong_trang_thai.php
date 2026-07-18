<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // DA_KET_THUC không còn được dùng ở bất kỳ đâu (chức năng "Kết thúc" đã bị
        // gỡ khỏi trang quản lý hội đồng), quy các bản ghi cũ (nếu có) về DA_CONG_BO
        // trước khi bỏ giá trị này khỏi enum.
        DB::table('hoidong')->where('trang_thai', 'DA_KET_THUC')->update(['trang_thai' => 'DA_CONG_BO']);

        DB::statement("ALTER TABLE hoidong MODIFY COLUMN trang_thai ENUM('NHAP','DA_CONG_BO','DANG_DIEN_RA') NOT NULL DEFAULT 'NHAP'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE hoidong MODIFY COLUMN trang_thai ENUM('NHAP','DA_CONG_BO','DANG_DIEN_RA','DA_KET_THUC') NOT NULL DEFAULT 'NHAP'");
    }
};
