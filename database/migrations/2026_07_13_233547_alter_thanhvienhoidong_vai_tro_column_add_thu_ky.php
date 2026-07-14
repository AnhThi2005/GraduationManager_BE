<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE thanhvienhoidong MODIFY COLUMN vai_tro ENUM('CHU_TICH','PHAN_BIEN','UY_VIEN','THU_KY') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE thanhvienhoidong MODIFY COLUMN vai_tro ENUM('CHU_TICH','PHAN_BIEN','UY_VIEN') NOT NULL");
    }
};
