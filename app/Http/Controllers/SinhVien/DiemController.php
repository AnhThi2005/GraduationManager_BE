<?php

namespace App\Http\Controllers\SinhVien;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
        $diemTttn = DB::table('diemthuctap')->where('sinh_vien_id', $sinhVienId)->first();

        // Lấy điểm ĐATN
        $diemDatn = DB::table('diemtongketdatn')->where('sinh_vien_id', $sinhVienId)->first();

        // 1. Dữ liệu TTTN
        $tttnFinal = $diemTttn && $diemTttn->diem_so !== null ? (string)round($diemTttn->diem_so, 2) : 'Chưa chấm';
        $tttnBaoCao = 'Chưa chấm';
        $tttnHuongDan = $diemTttn && $diemTttn->diem_so !== null ? (string)round($diemTttn->diem_so, 2) : 'Chưa chấm';
        $tttnStatus = $diemTttn ? 'Hoàn thành' : 'Đang tổng hợp';
        $tttnNote = $diemTttn ? 'Sinh viên đã hoàn tất quá trình thực tập và được giảng viên chấm điểm.' : 'Đang trong quá trình tổng hợp kết quả báo cáo thực tập.';

        // 2. Dữ liệu ĐATN
        $datnReport = $diemDatn && $diemDatn->diem_bao_cao_chung !== null ? (string)round($diemDatn->diem_bao_cao_chung, 2) : 'Chưa chấm';
        $datnFinal = $diemDatn && $diemDatn->diem_tong_ket !== null ? (string)round($diemDatn->diem_tong_ket, 2) : 'Chưa chấm';
        $datnDefense = $diemDatn && $diemDatn->diem_bao_ve_rieng !== null ? (string)round($diemDatn->diem_bao_ve_rieng, 2) : 'Chưa chấm';
        $datnStatus = $diemDatn && $diemDatn->diem_tong_ket >= 5.0 ? 'ĐẠT' : ($diemDatn ? 'KHÔNG ĐẠT' : 'Đang chấm');

        // 3. Quy đổi xếp loại dựa trên điểm ĐATN
        $classification = 'Chưa xếp loại';
        if ($diemDatn && $diemDatn->diem_tong_ket !== null) {
            $classification = $this->layXepLoai($diemDatn->diem_tong_ket);
        } else if ($diemTttn && $diemTttn->diem_so !== null) {
            $classification = $this->layXepLoai($diemTttn->diem_so);
        }

        $tttnUpdateStr = 'Chưa cập nhật';
        $datnUpdateStr = 'Chưa cập nhật';
        $summaryUpdatedAt = 'Đang cập nhật';

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
