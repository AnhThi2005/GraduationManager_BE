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
        Schema::create('diemsinhvien', function (Blueprint $table) {
            $table->increments('diem_id');
            $table->integer('sinh_vien_id');
            $table->integer('dot_id');
            $table->enum('loai', ['THUC_TAP', 'DO_AN']);
            $table->decimal('diem_thuyet_trinh', 4, 2)->nullable();
            $table->decimal('diem_demo', 4, 2)->nullable();
            $table->decimal('diem_van_dap', 4, 2)->nullable();
            $table->decimal('diem_bao_cao', 4, 2)->nullable();
            $table->decimal('diem_tong_ket', 4, 2)->nullable();
            $table->enum('trang_thai', ['draft', 'reviewing', 'finalized'])->default('draft');
            $table->timestamps();

            $table->unique(['sinh_vien_id', 'dot_id', 'loai']);

            $table->foreign('sinh_vien_id')->references('sinh_vien_id')->on('sinhvien')->onDelete('cascade');
            $table->foreign('dot_id')->references('dot_id')->on('dot')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diemsinhvien');
    }
};
