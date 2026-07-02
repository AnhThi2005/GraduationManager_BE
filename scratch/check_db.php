<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo "Columns in sinhvien:\n";
print_r(Schema::getColumnListing('sinhvien'));

echo "\nColumns in lop:\n";
print_r(Schema::getColumnListing('lop'));

$studentWithClass = DB::table('sinhvien')
    ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
    ->select('sinhvien.ho_ten', 'sinhvien.ma_so_sinh_vien', 'lop.ten_lop')
    ->first();
    
print_r($studentWithClass);
