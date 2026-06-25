<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "--- Students (sinhvien) ---\n";
$students = DB::table('sinhvien')->get();
foreach ($students as $student) {
    echo "ID: {$student->sinh_vien_id}, MSSV: {$student->ma_so_sinh_vien}, Name: {$student->ho_ten}, Email: {$student->email}\n";
}

echo "\n--- Scores (diemsinhvien) ---\n";
$scores = DB::table('diemsinhvien')->get();
foreach ($scores as $score) {
    echo "ID: {$score->diem_id}, SV_ID: {$score->sinh_vien_id}, Dot_ID: {$score->dot_id}, Type: {$score->loai}, Final: {$score->diem_tong_ket}\n";
}
