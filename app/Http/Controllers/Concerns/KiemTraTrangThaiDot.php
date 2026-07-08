<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Dot;
use Illuminate\Http\JsonResponse;

/**
 * Dùng chung ở mọi controller cần khóa thao tác theo trạng thái đợt — nguồn duy nhất
 * cho quy tắc này là các method trên Dot model (daKhoaHoanToan/daKhoaThaoTacSinhVien/
 * daKhoaSuaDiem), tránh mỗi controller tự so sánh trang_thai một kiểu khác nhau.
 *
 * Mỗi hàm trả về JsonResponse lỗi (423 Locked) nếu bị khóa, hoặc null nếu được phép
 * tiếp tục — gọi ngay sau khi xác định được $dot, trước khi ghi dữ liệu:
 *   if ($resp = $this->chanNeuDotDaDong($dot)) return $resp;
 */
trait KiemTraTrangThaiDot
{
    /**
     * Chặn mọi thao tác ghi (kể cả của admin) khi đợt đã đóng.
     */
    protected function chanNeuDotDaDong(?Dot $dot): ?JsonResponse
    {
        if ($dot && $dot->daKhoaHoanToan()) {
            return response()->json([
                'success' => false,
                'message' => "Đợt \"{$dot->ten_dot}\" đã đóng, không thể chỉnh sửa dữ liệu của đợt này nữa.",
            ], 423);
        }

        return null;
    }

    /**
     * Chặn sinh viên tự sửa dữ liệu của mình khi đợt đã bắt đầu chấm điểm trở đi.
     */
    protected function chanNeuSinhVienKhongDuocSua(?Dot $dot): ?JsonResponse
    {
        if ($dot && $dot->daKhoaThaoTacSinhVien()) {
            $lyDo = $dot->trang_thai === 'DA_DONG' ? 'đã đóng' : 'đã bắt đầu chấm điểm';

            return response()->json([
                'success' => false,
                'message' => "Đợt \"{$dot->ten_dot}\" {$lyDo}, bạn không thể chỉnh sửa được nữa.",
            ], 423);
        }

        return null;
    }

    /**
     * Chặn giảng viên sửa điểm khi đợt đã công bố hoặc đã đóng.
     */
    protected function chanNeuKhongDuocSuaDiem(?Dot $dot): ?JsonResponse
    {
        if ($dot && $dot->daKhoaSuaDiem()) {
            $lyDo = $dot->trang_thai === 'DA_DONG' ? 'đã đóng' : 'đã công bố kết quả';

            return response()->json([
                'success' => false,
                'message' => "Đợt \"{$dot->ten_dot}\" {$lyDo}, không thể sửa điểm được nữa.",
            ], 423);
        }

        return null;
    }
}
