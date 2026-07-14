
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
        Schema::table('lichbaove', function (Blueprint $table) {
            $table->integer('giang_vien_pb_id')->nullable()->after('nhom_id');
            $table->foreign('giang_vien_pb_id')->references('giang_vien_id')->on('giangvien')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lichbaove', function (Blueprint $table) {
            $table->dropForeign(['giang_vien_pb_id']);
            $table->dropColumn('giang_vien_pb_id');
        });
    }
};
