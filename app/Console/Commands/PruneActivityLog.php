<?php

namespace App\Console\Commands;

use App\Models\LichSuHoatDong;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneActivityLog extends Command
{
    /**
     * Mặc định giữ lại 24 tháng lịch sử hoạt động — đây là dữ liệu audit trail (SV/GV xem lại
     * ở trang "Lịch sử hoạt động"), nên chọn mốc dài, có thể chỉnh qua --months hoặc
     * config('activitylog.retention_months') thay vì xoá sớm/hardcode.
     */
    protected $signature = 'log:prune-activity
        {--months= : Số tháng giữ lại, mặc định lấy từ config(activitylog.retention_months)}
        {--dry-run : Chỉ đếm số dòng sẽ bị xoá, không xoá thật}';

    protected $description = 'Dọn các bản ghi cũ trong bảng lich_su_hoat_dong (audit trail) quá hạn giữ lại';

    public function handle(): int
    {
        $months = (int) ($this->option('months') ?: config('activitylog.retention_months', 24));
        $cutoff = now()->subMonths($months);

        $query = LichSuHoatDong::where('created_at', '<', $cutoff);
        $count = $query->count();

        if ($count === 0) {
            $this->info("Không có bản ghi nào cũ hơn {$months} tháng (trước {$cutoff->toDateString()}).");
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Sẽ xoá {$count} bản ghi lich_su_hoat_dong cũ hơn {$months} tháng (trước {$cutoff->toDateString()}).");
            return self::SUCCESS;
        }

        try {
            $deleted = $query->delete();
            $this->info("Đã xoá {$deleted} bản ghi lich_su_hoat_dong cũ hơn {$months} tháng (trước {$cutoff->toDateString()}).");
            Log::info("[log:prune-activity] Đã xoá {$deleted} bản ghi cũ hơn {$months} tháng.");
        } catch (\Throwable $e) {
            $this->error('Dọn lich_su_hoat_dong thất bại: '.$e->getMessage());
            Log::error('[log:prune-activity] Thất bại: '.$e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
