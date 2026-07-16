<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dọn định kỳ bảng lich_su_hoat_dong (audit trail) — chạy hàng tháng, tránh chồng lệnh nếu
// lần chạy trước chưa xong. Xem app/Console/Commands/PruneActivityLog.php.
Schedule::command('log:prune-activity')->monthly()->withoutOverlapping();
