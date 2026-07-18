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
        Schema::table('lich_su_hoat_dong', function (Blueprint $table) {
            $table->unsignedBigInteger('dot_id')->nullable()->after('nhom_id');
            $table->index('dot_id');
        });

        // 1. For logs with nhom_id, set dot_id from nhomsvda
        DB::statement("
            UPDATE lich_su_hoat_dong l
            JOIN nhomsvda n ON l.nhom_id = n.nhom_id
            SET l.dot_id = n.dot_id
            WHERE l.nhom_id IS NOT NULL
        ");

        // 2. For logs with no nhom_id but having sinh_vien_id (like TTTN actions)
        DB::statement("
            UPDATE lich_su_hoat_dong l
            JOIN dangkythuctap d ON l.sinh_vien_id = d.sinh_vien_id
            SET l.dot_id = d.dot_id
            WHERE l.nhom_id IS NULL 
              AND l.sinh_vien_id IS NOT NULL
              AND l.action_type IN ('KHAI_BAO_THUC_TAP', 'KHAI_BAO_TTTN', 'DUYET_TTTN', 'XOA_TTTN')
        ");

        // Fallback for TTTN actions using dot_sinhvien
        DB::statement("
            UPDATE lich_su_hoat_dong l
            JOIN (
                SELECT ds.sinh_vien_id, ds.dot_id
                FROM dot_sinhvien ds
                JOIN dot d ON ds.dot_id = d.dot_id
                WHERE d.loai_dot = 'TTTN'
            ) sub ON l.sinh_vien_id = sub.sinh_vien_id
            SET l.dot_id = sub.dot_id
            WHERE l.dot_id IS NULL
              AND l.nhom_id IS NULL
              AND l.sinh_vien_id IS NOT NULL
              AND l.action_type IN ('KHAI_BAO_THUC_TAP', 'KHAI_BAO_TTTN', 'DUYET_TTTN', 'XOA_TTTN')
        ");

        // Fallback for TTTN actions using dot_lop
        DB::statement("
            UPDATE lich_su_hoat_dong l
            JOIN (
                SELECT sv.sinh_vien_id, dl.dot_id
                FROM sinhvien sv
                JOIN dot_lop dl ON sv.lop_id = dl.lop_id
                JOIN dot d ON dl.dot_id = d.dot_id
                WHERE d.loai_dot = 'TTTN'
            ) sub ON l.sinh_vien_id = sub.sinh_vien_id
            SET l.dot_id = sub.dot_id
            WHERE l.dot_id IS NULL
              AND l.nhom_id IS NULL
              AND l.sinh_vien_id IS NOT NULL
              AND l.action_type IN ('KHAI_BAO_THUC_TAP', 'KHAI_BAO_TTTN', 'DUYET_TTTN', 'XOA_TTTN')
        ");

        // 3. For DATN actions with no nhom_id but having sinh_vien_id, associate with their DATN dot using dot_sinhvien
        DB::statement("
            UPDATE lich_su_hoat_dong l
            JOIN (
                SELECT ds.sinh_vien_id, ds.dot_id
                FROM dot_sinhvien ds
                JOIN dot d ON ds.dot_id = d.dot_id
                WHERE d.loai_dot = 'DATN'
            ) sub ON l.sinh_vien_id = sub.sinh_vien_id
            SET l.dot_id = sub.dot_id
            WHERE l.dot_id IS NULL
              AND l.nhom_id IS NULL
              AND l.sinh_vien_id IS NOT NULL
              AND l.action_type NOT IN ('KHAI_BAO_THUC_TAP', 'KHAI_BAO_TTTN', 'DUYET_TTTN', 'XOA_TTTN')
        ");

        // Fallback for DATN actions using dot_lop
        DB::statement("
            UPDATE lich_su_hoat_dong l
            JOIN (
                SELECT sv.sinh_vien_id, dl.dot_id
                FROM sinhvien sv
                JOIN dot_lop dl ON sv.lop_id = dl.lop_id
                JOIN dot d ON dl.dot_id = d.dot_id
                WHERE d.loai_dot = 'DATN'
            ) sub ON l.sinh_vien_id = sub.sinh_vien_id
            SET l.dot_id = sub.dot_id
            WHERE l.dot_id IS NULL
              AND l.nhom_id IS NULL
              AND l.sinh_vien_id IS NOT NULL
              AND l.action_type NOT IN ('KHAI_BAO_THUC_TAP', 'KHAI_BAO_TTTN', 'DUYET_TTTN', 'XOA_TTTN')
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lich_su_hoat_dong', function (Blueprint $table) {
            $table->dropIndex(['dot_id']);
            $table->dropColumn('dot_id');
        });
    }
};
