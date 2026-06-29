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

echo "\n--- Internship Scores (diemthuctap) ---\n";
$internshipScores = DB::table('diemthuctap')->get();
foreach ($internshipScores as $score) {
    echo "ID: {$score->diem_id}, SV_ID: {$score->sinh_vien_id}, Dot_ID: {$score->dot_id}, Score: {$score->diem_so}\n";
}

echo "\n--- Thesis Scores (diemtongketdatn) ---\n";
$thesisScores = DB::table('diemtongketdatn')->get();
foreach ($thesisScores as $score) {
    echo "ID: {$score->tong_ket_id}, SV_ID: {$score->sinh_vien_id}, Report: {$score->diem_bao_cao_chung}, Defense: {$score->diem_bao_ve_rieng}, Final: {$score->diem_tong_ket}\n";
}
