<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * giang_vien_hd_id/giang_vien_pb_id đang NOT NULL + có khóa ngoại tới giangvien — nên trước khi
     * xác định được GVHD/GVPB thật (đề tài chưa gắn GV, hoặc chưa xếp lịch bảo vệ có phản biện),
     * không có giá trị "int" nào hợp lệ để lưu tạm (0 vi phạm khóa ngoại). Cho phép NULL để phản ánh
     * đúng "chưa xác định", thay vì phải chặn hẳn việc lưu điểm báo cáo lúc đó.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE diembaocao MODIFY giang_vien_hd_id INT(11) NULL');
        DB::statement('ALTER TABLE diembaocao MODIFY giang_vien_pb_id INT(11) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE diembaocao MODIFY giang_vien_hd_id INT(11) NOT NULL');
        DB::statement('ALTER TABLE diembaocao MODIFY giang_vien_pb_id INT(11) NOT NULL');
    }
};
