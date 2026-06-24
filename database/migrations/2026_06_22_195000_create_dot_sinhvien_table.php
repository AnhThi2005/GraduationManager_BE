<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dot_sinhvien', function (Blueprint $table) {
            $table->integer('dot_id');
            $table->integer('sinh_vien_id');
            $table->string('ly_do')->nullable()->default('Rớt đợt trước');
            
            $table->primary(['dot_id', 'sinh_vien_id']);
            
            $table->foreign('dot_id')->references('dot_id')->on('dot')->onDelete('cascade');
            $table->foreign('sinh_vien_id')->references('sinh_vien_id')->on('sinhvien')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dot_sinhvien');
    }
};
