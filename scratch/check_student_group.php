<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SinhVien;
use App\Models\Dot;
use App\Models\Nhom;
use Illuminate\Support\Facades\DB;

$sinhVien = SinhVien::first();
if (!$sinhVien) {
    echo "No student found!\n";
    exit(1);
}

$lopId = $sinhVien->lop_id;
$activePeriod = Dot::where('loai_dot', 'DATN')
    ->whereHas('lops', function($q) use ($lopId) {
        $q->where('lop.lop_id', $lopId);
    })->orderBy('dot_id', 'desc')->first();

echo "Student: {$sinhVien->ho_ten} (Class ID: {$lopId})\n";
if ($activePeriod) {
    echo "Active Period: {$activePeriod->ten_dot} (ID: {$activePeriod->dot_id})\n";
} else {
    echo "Active Period: NOT FOUND for Class ID {$lopId}\n";
}

$nhom = Nhom::whereHas('members', function($q) use ($sinhVien) {
    $q->where('sinhvien.sinh_vien_id', $sinhVien->sinh_vien_id);
})->first();

if ($nhom) {
    echo "Group Found: ID {$nhom->nhom_id} | Status: {$nhom->trang_thai_duyet} | Period ID: {$nhom->dot_id}\n";
} else {
    echo "Group Found: NONE\n";
}
