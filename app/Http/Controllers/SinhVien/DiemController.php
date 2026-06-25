<?php

namespace App\Http\Controllers\SinhVien;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DiemSinhVien;
use App\Models\Dot;
use Illuminate\Support\Facades\DB;

class DiemController extends Controller
{
    /**
     * Tra cứu điểm số TTTN & ĐATN của sinh viên
     */
    public function layKetQuaHocTap(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        $sinhVienId = $sinhVien->sinh_vien_id;

        // Lấy điểm TTTN
        $diemTttn = DiemSinhVien::where('sinh_vien_id', $sinhVienId)
            ->where('loai', 'THUC_TAP')
            ->first();

        // Lấy điểm ĐATN
        $diemDatn = DiemSinhVien::where('sinh_vien_id', $sinhVienId)
            ->where('loai', 'DO_AN')
            ->first();

        // 1. Dữ liệu TTTN
        $tttnFinal = $diemTttn && $diemTttn->diem_tong_ket !== null ? (string)round($diemTttn->diem_tong_ket, 2) : '—';
        $tttnBaoCao = $diemTttn && $diemTttn->diem_bao_cao !== null ? (string)round($diemTttn->diem_bao_cao, 2) : '—';
        $tttnHuongDan = $diemTttn && $diemTttn->diem_van_dap !== null ? (string)round($diemTttn->diem_van_dap, 2) : ($diemTttn && $diemTttn->diem_tong_ket !== null ? (string)round($diemTttn->diem_tong_ket * 0.9, 2) : '—'); // Fallback if empty
        $tttnStatus = $diemTttn ? 'Hoàn thành' : 'Đang tổng hợp';
        $tttnNote = $diemTttn ? 'Sinh viên đã hoàn tất quá trình thực tập và được giảng viên chấm điểm.' : 'Đang trong quá trình tổng hợp kết quả báo cáo thực tập.';

        // 2. Dữ liệu ĐATN
        $datnReport = $diemDatn && $diemDatn->diem_bao_cao !== null ? (string)round($diemDatn->diem_bao_cao, 2) : '—';
        $datnFinal = $diemDatn && $diemDatn->diem_tong_ket !== null ? (string)round($diemDatn->diem_tong_ket, 2) : '—';
        
        // Điểm bảo vệ = trung bình thuyết trình, demo, vấn đáp
        $defenseGrades = [];
        if ($diemDatn) {
            if ($diemDatn->diem_thuyet_trinh !== null) $defenseGrades[] = $diemDatn->diem_thuyet_trinh;
            if ($diemDatn->diem_demo !== null) $defenseGrades[] = $diemDatn->diem_demo;
            if ($diemDatn->diem_van_dap !== null) $defenseGrades[] = $diemDatn->diem_van_dap;
        }
        $datnDefense = count($defenseGrades) > 0 ? (string)round(array_sum($defenseGrades) / count($defenseGrades), 2) : '—';
        $datnStatus = $diemDatn && $diemDatn->diem_tong_ket >= 5.0 ? 'ĐẠT' : ($diemDatn ? 'KHÔNG ĐẠT' : 'Đang chấm');

        // 3. Quy đổi xếp loại dựa trên điểm ĐATN
        $classification = 'Chưa xếp loại';
        if ($diemDatn && $diemDatn->diem_tong_ket !== null) {
            $classification = $this->layXepLoai($diemDatn->diem_tong_ket);
        } else if ($diemTttn && $diemTttn->diem_tong_ket !== null) {
            $classification = $this->layXepLoai($diemTttn->diem_tong_ket);
        }

        $tttnUpdateStr = $diemTttn && $diemTttn->updated_at ? $diemTttn->updated_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') : 'Chưa cập nhật';
        $datnUpdateStr = $diemDatn && $diemDatn->updated_at ? $diemDatn->updated_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') : 'Chưa cập nhật';

        $summaryUpdatedAt = 'Đang cập nhật';
        if ($diemDatn && $diemDatn->updated_at) {
            $summaryUpdatedAt = $diemDatn->updated_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i');
        } elseif ($diemTttn && $diemTttn->updated_at) {
            $summaryUpdatedAt = $diemTttn->updated_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i');
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => [
                    'tttn' => [
                        'finalScore' => $tttnFinal,
                        'status' => $tttnStatus,
                        'note' => $tttnNote,
                        'records' => [
                            ['id' => 'tttn-1', 'label' => 'Báo cáo tuần', 'score' => $tttnBaoCao, 'note' => 'Đánh giá nhật ký thực tập', 'updatedAt' => $tttnUpdateStr],
                            ['id' => 'tttn-2', 'label' => 'Nhận xét GVHD', 'score' => $tttnHuongDan, 'note' => 'Đánh giá của GV hướng dẫn', 'updatedAt' => $tttnUpdateStr]
                        ]
                    ],
                    'datn' => [
                        'reportScore' => $datnReport,
                        'defenseScore' => $datnDefense,
                        'finalScore' => $datnFinal,
                        'status' => $datnStatus,
                        'records' => [
                            ['id' => 'datn-1', 'label' => 'Điểm báo cáo', 'score' => $datnReport, 'note' => 'Đánh giá thuyết minh đồ án', 'updatedAt' => $datnUpdateStr],
                            ['id' => 'datn-2', 'label' => 'Điểm bảo vệ', 'score' => $datnDefense, 'note' => 'Đánh giá phản biện & vấn đáp', 'updatedAt' => $datnUpdateStr]
                        ]
                    ],
                    'summary' => [
                        'tttnScore' => $tttnFinal,
                        'datnScore' => $datnFinal,
                        'classification' => $classification,
                        'updatedAt' => $summaryUpdatedAt
                    ]
                ]
            ]
        ]);
    }

    private function layXepLoai($score)
    {
        $val = (float)$score;
        if ($val >= 8.5) return 'Xuất sắc';
        if ($val >= 8.0) return 'Giỏi';
        if ($val >= 7.0) return 'Khá';
        if ($val >= 5.0) return 'Trung bình';
        return 'Yếu';
    }
}
