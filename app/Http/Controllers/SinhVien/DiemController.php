<?php

namespace App\Http\Controllers\SinhVien;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Dot;

class DiemController extends Controller
{
    /**
     * Tra cứu điểm số TTTN & ĐATN của sinh viên
     */
    public function layKetQuaHocTap(Request $request)
    {
        $sinhVien = $request->user();
        if (! $sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        $sinhVienId = $sinhVien->sinh_vien_id;
        $lopId = $sinhVien->lop_id;

        // 1. Kiểm tra xem sinh viên có đợt TTTN nào đang hoạt động (chưa đóng) hay không
        $hasActiveTttn = Dot::where('loai_dot', 'TTTN')
            ->where('trang_thai', '!=', 'DA_DONG')
            ->where(function ($query) use ($lopId, $sinhVienId) {
                $query->whereHas('lops', function ($q) use ($lopId) {
                    $q->where('lop.lop_id', $lopId);
                })->orWhereHas('sinhViens', function ($q) use ($sinhVienId) {
                    $q->where('sinhvien.sinh_vien_id', $sinhVienId);
                });
            })
            ->exists();

        if ($hasActiveTttn) {
            // Nếu đang trong đợt hoạt động, bắt buộc lấy điểm đợt hoạt động (chấp nhận chưa có điểm)
            $diemTttn = DB::table('diemthuctap')
                ->join('dot', 'diemthuctap.dot_id', '=', 'dot.dot_id')
                ->where('diemthuctap.sinh_vien_id', $sinhVienId)
                ->where('dot.trang_thai', '!=', 'DA_DONG')
                ->select('diemthuctap.*')
                ->orderBy('dot.dot_id', 'desc')
                ->first();
        } else {
            // Nếu không có đợt hoạt động nào, mới lấy điểm của đợt cũ gần nhất
            $diemTttn = DB::table('diemthuctap')
                ->where('sinh_vien_id', $sinhVienId)
                ->orderBy('dot_id', 'desc')
                ->first();
        }

        // 2. Kiểm tra xem sinh viên có đợt ĐATN nào đang hoạt động (chưa đóng) hay không
        $hasActiveDatn = Dot::where('loai_dot', 'DATN')
            ->where('trang_thai', '!=', 'DA_DONG')
            ->where(function ($query) use ($lopId, $sinhVienId) {
                $query->whereHas('lops', function ($q) use ($lopId) {
                    $q->where('lop.lop_id', $lopId);
                })->orWhereHas('sinhViens', function ($q) use ($sinhVienId) {
                    $q->where('sinhvien.sinh_vien_id', $sinhVienId);
                });
            })
            ->exists();

        if ($hasActiveDatn) {
            // Nếu đang trong đợt hoạt động, bắt buộc lấy điểm đợt hoạt động (chấp nhận chưa có điểm)
            $diemDatn = DB::table('diemtongketdatn')
                ->join('nhomsvda', 'diemtongketdatn.nhom_id', '=', 'nhomsvda.nhom_id')
                ->join('dot', 'nhomsvda.dot_id', '=', 'dot.dot_id')
                ->where('diemtongketdatn.sinh_vien_id', $sinhVienId)
                ->where('dot.trang_thai', '!=', 'DA_DONG')
                ->select('diemtongketdatn.*')
                ->orderBy('nhomsvda.dot_id', 'desc')
                ->first();
        } else {
            // Nếu không có đợt hoạt động nào, mới lấy điểm của đợt cũ gần nhất
            $diemDatn = DB::table('diemtongketdatn')
                ->join('nhomsvda', 'diemtongketdatn.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('diemtongketdatn.sinh_vien_id', $sinhVienId)
                ->orderBy('nhomsvda.dot_id', 'desc')
                ->select('diemtongketdatn.*')
                ->first();
        }

        // Hiển thị điểm ngay lập tức (không cần chờ thời gian công bố)
        $showTttnScore = true;
        $showDatnScore = true;

        // 1. Dữ liệu TTTN
        $tttnFinal = ($showTttnScore && $diemTttn && $diemTttn->diem_so !== null) ? (string) round($diemTttn->diem_so, 2) : 'Chưa chấm';
        $tttnBaoCao = $diemTttn ? 'Hoàn thành' : 'Chưa chấm';
        $tttnHuongDan = ($showTttnScore && $diemTttn && $diemTttn->diem_so !== null) ? (string) round($diemTttn->diem_so, 2) : 'Chưa chấm';
        $tttnStatus = $diemTttn ? 'Hoàn thành' : 'Đang chấm';
        $tttnNote = $diemTttn ? 'Sinh viên đã hoàn tất quá trình thực tập và được giảng viên chấm điểm.' : 'Kết quả thực tập đang được tổng hợp và chấm điểm.';

        // 2. Dữ liệu ĐATN
        $datnReport = ($showDatnScore && $diemDatn && $diemDatn->diem_bao_cao_chung !== null) ? (string) round($diemDatn->diem_bao_cao_chung, 2) : 'Chưa chấm';
        $datnFinal = ($showDatnScore && $diemDatn && $diemDatn->diem_tong_ket !== null) ? (string) round($diemDatn->diem_tong_ket, 2) : 'Chưa chấm';
        $datnDefense = ($showDatnScore && $diemDatn && $diemDatn->diem_bao_ve_rieng !== null) ? (string) round($diemDatn->diem_bao_ve_rieng, 2) : 'Chưa chấm';
        $datnStatus = $showDatnScore ? ($diemDatn && $diemDatn->diem_tong_ket >= 5.0 ? 'ĐẠT' : ($diemDatn ? 'KHÔNG ĐẠT' : 'Chưa chấm')) : 'Chưa chấm';

        // 3. Quy đổi xếp loại dựa trên điểm ĐATN/TTTN
        $classification = 'Chưa xếp loại';
        if ($showDatnScore && $diemDatn && $diemDatn->diem_tong_ket !== null) {
            $classification = $this->layXepLoai($diemDatn->diem_tong_ket);
        } elseif ($showTttnScore && $diemTttn && $diemTttn->diem_so !== null) {
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
                            ['id' => 'tttn-2', 'label' => 'Nhận xét GVHD', 'score' => $tttnHuongDan, 'note' => 'Đánh giá của GV hướng dẫn', 'updatedAt' => $tttnUpdateStr],
                        ],
                    ],
                    'datn' => [
                        'reportScore' => $datnReport,
                        'defenseScore' => $datnDefense,
                        'finalScore' => $datnFinal,
                        'status' => $datnStatus,
                        'records' => [
                            ['id' => 'datn-1', 'label' => 'Điểm báo cáo', 'score' => $datnReport, 'note' => 'Đánh giá thuyết minh đồ án', 'updatedAt' => $datnUpdateStr],
                            ['id' => 'datn-2', 'label' => 'Điểm bảo vệ', 'score' => $datnDefense, 'note' => 'Đánh giá phản biện & vấn đáp', 'updatedAt' => $datnUpdateStr],
                        ],
                    ],
                    'summary' => [
                        'tttnScore' => $tttnFinal,
                        'datnScore' => $datnFinal,
                        'classification' => $classification,
                        'updatedAt' => $summaryUpdatedAt,
                    ],
                ],
            ],
        ]);
    }

    private function layXepLoai($score)
    {
        $val = (float) $score;
        if ($val >= 8.5) {
            return 'Xuất sắc';
        }
        if ($val >= 8.0) {
            return 'Giỏi';
        }
        if ($val >= 7.0) {
            return 'Khá';
        }
        if ($val >= 5.0) {
            return 'Trung bình';
        }

        return 'Yếu';
    }
}
