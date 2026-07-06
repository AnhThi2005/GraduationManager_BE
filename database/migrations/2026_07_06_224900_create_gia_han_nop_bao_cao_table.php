<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gia_han_nop_bao_cao', function (Blueprint $table) {
            $table->id();
            $table->integer('sinh_vien_id');
            $table->integer('dot_id');
            $table->string('loai_bao_cao', 10); // 'THUC_TAP' hoặc 'DO_AN'
            $table->integer('tuan');
            $table->dateTime('han_nop_moi');
            $table->integer('nguoi_gia_han_id')->nullable();
            $table->timestamp('ngay_gia_han')->useCurrent();

            // Foreign keys
            $table->foreign('sinh_vien_id')->references('sinh_vien_id')->on('sinhvien')->onDelete('cascade');
            $table->foreign('dot_id')->references('dot_id')->on('dot')->onDelete('cascade');
            $table->foreign('nguoi_gia_han_id')->references('giang_vien_id')->on('giangvien')->onDelete('set null');

            // Unique constraint
            $table->unique(['sinh_vien_id', 'dot_id', 'loai_bao_cao', 'tuan'], 'sv_dot_loai_tuan_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gia_han_nop_bao_cao');
    }
};
