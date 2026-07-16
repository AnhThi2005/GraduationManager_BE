<?php

return [
    // Số tháng giữ lại bản ghi trong bảng lich_su_hoat_dong trước khi bị dọn bởi
    // lệnh `log:prune-activity` (xem app/Console/Commands/PruneActivityLog.php).
    'retention_months' => env('ACTIVITY_LOG_RETENTION_MONTHS', 24),
];
