<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dot_lop', function (Blueprint $table) {
            $table->integer('dot_id');
            $table->integer('lop_id');
            $table->primary(['dot_id', 'lop_id']);
            
            $table->foreign('dot_id')->references('dot_id')->on('dot')->onDelete('cascade');
            $table->foreign('lop_id')->references('lop_id')->on('lop')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dot_lop');
    }
};
