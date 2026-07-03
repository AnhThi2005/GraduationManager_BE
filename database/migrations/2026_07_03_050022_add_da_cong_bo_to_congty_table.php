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
        Schema::table('congty', function (Blueprint $table) {
            $table->boolean('da_cong_bo')->default(false)->after('trang_thai');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('congty', function (Blueprint $table) {
            $table->dropColumn('da_cong_bo');
        });
    }
};
