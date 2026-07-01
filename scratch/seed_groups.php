<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    DB::beginTransaction();

    // 1. Ensure nhom 101 is in nhomsvda with dot_id: 3, de_tai_id: 101, trang_thai_duyet: 'CHO_DUYET'
    DB::table('nhomsvda')->updateOrInsert(
        ['nhom_id' => 101],
        ['dot_id' => 3, 'de_tai_id' => 101, 'trang_thai_duyet' => 'CHO_DUYET']
    );

    // 2. Ensure dangkydetai has record for nhom 101
    DB::table('dangkydetai')->updateOrInsert(
        ['nhom_id' => 101],
        ['de_tai_id' => 101, 'trang_thai_duyet' => 'CHO_DUYET', 'ngay_dang_ky' => date('Y-m-d H:i:s')]
    );

    // 3. Ensure nhom 102 is in nhomsvda with dot_id: 3, de_tai_id: 102, trang_thai_duyet: 'DA_DUYET'
    DB::table('nhomsvda')->updateOrInsert(
        ['nhom_id' => 102],
        ['dot_id' => 3, 'de_tai_id' => 102, 'trang_thai_duyet' => 'DA_DUYET']
    );

    // 4. Ensure dangkydetai has record for nhom 102
    DB::table('dangkydetai')->updateOrInsert(
        ['nhom_id' => 102],
        ['de_tai_id' => 102, 'trang_thai_duyet' => 'DA_DUYET', 'ngay_dang_ky' => date('Y-m-d H:i:s')]
    );
    
    // Ensure thanhviennhom has members for nhom 102
    DB::table('thanhviennhom')->updateOrInsert(
        ['nhom_id' => 102, 'sinh_vien_id' => 4],
        ['la_truong_nhom' => 1]
    );
    DB::table('thanhviennhom')->updateOrInsert(
        ['nhom_id' => 102, 'sinh_vien_id' => 5],
        ['la_truong_nhom' => 0]
    );

    // 5. Create a new nhom 103 for dot_id: 3, de_tai_id: 103, trang_thai_duyet: 'TU_CHOI'
    DB::table('nhomsvda')->updateOrInsert(
        ['nhom_id' => 103],
        ['dot_id' => 3, 'de_tai_id' => 103, 'trang_thai_duyet' => 'TU_CHOI']
    );

    // Ensure dangkydetai has record for nhom 103
    DB::table('dangkydetai')->updateOrInsert(
        ['nhom_id' => 103],
        ['de_tai_id' => 103, 'trang_thai_duyet' => 'TU_CHOI', 'ly_do_tu_choi' => 'Đề tài không phù hợp với năng lực nhóm', 'ngay_dang_ky' => date('Y-m-d H:i:s')]
    );

    // Ensure thanhviennhom has members for nhom 103
    DB::table('thanhviennhom')->updateOrInsert(
        ['nhom_id' => 103, 'sinh_vien_id' => 6],
        ['la_truong_nhom' => 1]
    );
    DB::table('thanhviennhom')->updateOrInsert(
        ['nhom_id' => 103, 'sinh_vien_id' => 7],
        ['la_truong_nhom' => 0]
    );

    DB::commit();
    echo "Seed groups successfully!\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
