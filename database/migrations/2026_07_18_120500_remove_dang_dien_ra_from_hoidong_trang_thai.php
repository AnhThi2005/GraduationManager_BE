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
        // DANG_DIEN_RA chưa từng được dùng ở bất kỳ đâu trong hệ thống (BE lẫn FE),
        // quy các bản ghi cũ (nếu có) về DA_CONG_BO trước khi bỏ giá trị này khỏi enum.
        DB::table('hoidong')->where('trang_thai', 'DANG_DIEN_RA')->update(['trang_thai' => 'DA_CONG_BO']);

        DB::statement("ALTER TABLE hoidong MODIFY COLUMN trang_thai ENUM('NHAP','DA_CONG_BO') NOT NULL DEFAULT 'NHAP'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE hoidong MODIFY COLUMN trang_thai ENUM('NHAP','DA_CONG_BO','DANG_DIEN_RA') NOT NULL DEFAULT 'NHAP'");
    }
};
