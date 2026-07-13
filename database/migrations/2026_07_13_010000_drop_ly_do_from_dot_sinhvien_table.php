<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cột ly_do không còn được dùng ở đâu nữa — bỏ hẳn theo yêu cầu, thay vì chỉ ẩn ở UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE dot_sinhvien DROP COLUMN ly_do');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dot_sinhvien ADD COLUMN ly_do VARCHAR(255) NULL DEFAULT NULL');
    }
};
