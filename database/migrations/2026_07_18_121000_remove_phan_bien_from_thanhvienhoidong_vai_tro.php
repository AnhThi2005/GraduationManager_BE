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
        // PHAN_BIEN không còn là vai trò thành viên hội đồng — giảng viên phản biện của
        // từng nhóm được xác định qua lichbaove.giang_vien_pb_id. Quy các bản ghi cũ về
        // UY_VIEN trước khi bỏ giá trị này khỏi enum.
        DB::table('thanhvienhoidong')->where('vai_tro', 'PHAN_BIEN')->update(['vai_tro' => 'UY_VIEN']);

        DB::statement("ALTER TABLE thanhvienhoidong MODIFY COLUMN vai_tro ENUM('CHU_TICH','UY_VIEN','THU_KY') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE thanhvienhoidong MODIFY COLUMN vai_tro ENUM('CHU_TICH','PHAN_BIEN','UY_VIEN','THU_KY') NOT NULL");
    }
};
