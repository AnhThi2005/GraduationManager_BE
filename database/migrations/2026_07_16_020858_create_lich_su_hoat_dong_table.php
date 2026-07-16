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
        Schema::create('lich_su_hoat_dong', function (Blueprint $table) {
            $table->id('log_id');
            $table->unsignedBigInteger('sinh_vien_id')->nullable();
            $table->string('ma_so_sinh_vien')->nullable();
            $table->unsignedBigInteger('nhom_id')->nullable();
            $table->string('role')->nullable(); // 'sinh_vien', 'giang_vien', 'admin'
            $table->string('user_name')->nullable();
            $table->string('action_type'); 
            $table->text('description');
            $table->text('details')->nullable(); // JSON formatted extra info
            $table->timestamps();
        });
    }
 
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lich_su_hoat_dong');
    }
};
