<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DiemSinhVienService
{
    /**
     * Lấy danh sách điểm tốt nghiệp/thực tập của sinh viên
     */
    public function getScoresList(array $filters, $perPage = 10, $page = 1)
    {
        $dotId = $filters['periodId'] ?? null;
        $loai = isset($filters['mode']) && $filters['mode'] === 'project' ? 'DO_AN' : 'THUC_TAP';

        if (! $dotId) {
            // Lấy đợt tốt nghiệp mới nhất tương ứng với loại
            $latestPeriod = DB::table('dot')
                ->where('loai_dot', $loai === 'DO_AN' ? 'DATN' : 'TTTN')
                ->orderBy('dot_id', 'desc')
                ->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : null;
        }

        if (! $dotId) {
            return ['rows' => [], 'total' => 0];
        }

        if ($loai === 'THUC_TAP') {
            // Điểm thực tập tốt nghiệp (TTTN)
            $studentsQuery = DB::table('dangkythuctap')
                ->where('dangkythuctap.dot_id', $dotId)
                ->join('sinhvien', 'dangkythuctap.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
                ->leftJoin('congty', 'dangkythuctap.cong_ty_id', '=', 'congty.cong_ty_id')
                ->leftJoin('phanconghdtt', function ($join) use ($dotId) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'phanconghdtt.sinh_vien_id')
                        ->where('phanconghdtt.dot_id', '=', $dotId);
                })
                ->leftJoin('giangvien', 'phanconghdtt.giang_vien_id', '=', 'giangvien.giang_vien_id')
                ->leftJoin('diemthuctap', function ($join) use ($dotId) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'diemthuctap.sinh_vien_id')
                        ->where('diemthuctap.dot_id', '=', $dotId);
                })
                ->leftJoin('dot', 'dangkythuctap.dot_id', '=', 'dot.dot_id')
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'congty.ten_cong_ty as companyName',
                    'giangvien.ho_ten as mentor',
                    'diemthuctap.diem_id as id',
                    'diemthuctap.diem_so as finalScore',
                    'dot.ten_dot as period',
                    DB::raw("CASE WHEN diemthuctap.diem_so IS NOT NULL THEN 'finalized' ELSE 'draft' END as status"),
                ]);
        } else {
            // Điểm đồ án tốt nghiệp (DATN)
            // Gộp 3 correlated subquery/dòng (diem_thuyet_trinh, diem_demo, diem_van_dap) +
            // 3 subquery đếm thanhvienhoidong lặp lại y hệt thành 2 bảng tổng hợp (SUM/COUNT
            // theo GROUP BY) rồi LEFT JOIN 1 lần, thay vì để MySQL chạy lại subquery cho từng dòng.
            $dhbvAgg = DB::table('diemhoidongbaove')
                ->select(
                    'sinh_vien_id',
                    'nhom_id',
                    DB::raw('SUM(diem_thuyet_trinh) as sum_thuyet_trinh'),
                    DB::raw('SUM(diem_demo) as sum_demo'),
                    DB::raw('SUM(diem_van_dap) as sum_van_dap')
                )
                ->groupBy('sinh_vien_id', 'nhom_id');

            $thdAgg = DB::table('thanhvienhoidong')
                ->select('hoi_dong_id', DB::raw('COUNT(*) as total_lecturers'))
                ->groupBy('hoi_dong_id');

            // Đếm số giám khảo đã chấm ĐỦ cả 3 mục (thuyết trình/demo/vấn đáp) cho từng sinh viên,
            // dùng để xác định "đã chấm xong" thay vì dựa vào trang_thai (vốn là kết quả Đạt/Không đạt).
            $gradedAgg = DB::table('diemhoidongbaove')
                ->select('sinh_vien_id', 'nhom_id', DB::raw('COUNT(*) as graded_count'))
                ->whereNotNull('diem_thuyet_trinh')
                ->whereNotNull('diem_demo')
                ->whereNotNull('diem_van_dap')
                ->groupBy('sinh_vien_id', 'nhom_id');

            $studentsQuery = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.dot_id', $dotId)
                ->join('sinhvien', 'thanhviennhom.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
                ->leftJoin('detai', 'nhomsvda.de_tai_id', '=', 'detai.de_tai_id')
                ->leftJoin('giangvien', 'detai.giang_vien_id', '=', 'giangvien.giang_vien_id')
                ->leftJoin('diemtongketdatn', function ($join) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'diemtongketdatn.sinh_vien_id')
                        ->on('nhomsvda.nhom_id', '=', 'diemtongketdatn.nhom_id');
                })
                ->leftJoinSub($dhbvAgg, 'dhbv', function ($join) {
                    $join->on('dhbv.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                        ->on('dhbv.nhom_id', '=', 'nhomsvda.nhom_id');
                })
                ->leftJoinSub($thdAgg, 'thd', function ($join) {
                    $join->on('thd.hoi_dong_id', '=', 'nhomsvda.hoi_dong_id');
                })
                ->leftJoinSub($gradedAgg, 'graded', function ($join) {
                    $join->on('graded.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                        ->on('graded.nhom_id', '=', 'nhomsvda.nhom_id');
                })
                ->leftJoin('dot', 'nhomsvda.dot_id', '=', 'dot.dot_id')
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'detai.ten_de_tai as topicName',
                    'giangvien.ho_ten as mentor',
                    'diemtongketdatn.tong_ket_id as id',
                    DB::raw('dhbv.sum_thuyet_trinh / COALESCE(NULLIF(thd.total_lecturers, 0), 1) as defenseScore'),
                    DB::raw('dhbv.sum_demo / COALESCE(NULLIF(thd.total_lecturers, 0), 1) as demoScore'),
                    DB::raw('dhbv.sum_van_dap / COALESCE(NULLIF(thd.total_lecturers, 0), 1) as qaScore'),
                    'diemtongketdatn.diem_bao_cao_chung as reportScore',
                    'diemtongketdatn.diem_tong_ket as finalScore',
                    'dot.ten_dot as period',
                    // "Đã chấm" = có điểm báo cáo VÀ toàn bộ thành viên hội đồng đã chấm đủ 3 mục.
                    // (Không dùng trang_thai vì đó là kết quả Đạt/Không đạt, không phải trạng thái chấm điểm.)
                    DB::raw('CASE WHEN diemtongketdatn.diem_bao_cao_chung IS NOT NULL
                        AND COALESCE(thd.total_lecturers, 0) > 0
                        AND COALESCE(graded.graded_count, 0) >= thd.total_lecturers
                        THEN \'finalized\' ELSE \'draft\' END as status'),
                ]);

            // Chỉ hiển thị sinh viên đã có ít nhất 1 điểm thành phần được chấm THẬT
            // (bảo vệ/demo/vấn đáp từ hội đồng, hoặc điểm báo cáo) - ẩn sinh viên chưa chấm.
            // Không dùng whereExists đơn thuần vì hội đồng có thể đã có sẵn bản ghi rỗng (toàn NULL)
            // ngay khi được phân công, trước khi ai thực sự chấm điểm.
            $studentsQuery->where(function ($q) {
                $q->whereNotNull('diemtongketdatn.diem_bao_cao_chung')
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('diemhoidongbaove')
                            ->whereColumn('diemhoidongbaove.sinh_vien_id', 'sinhvien.sinh_vien_id')
                            ->whereColumn('diemhoidongbaove.nhom_id', 'nhomsvda.nhom_id')
                            ->where(function ($w) {
                                $w->whereNotNull('diem_thuyet_trinh')
                                    ->orWhereNotNull('diem_demo')
                                    ->orWhereNotNull('diem_van_dap');
                            });
                    });
            });
        }

        // Áp dụng bộ lọc tìm kiếm theo từ khóa
        if (! empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $studentsQuery->where(function ($q) use ($keyword, $loai) {
                $q->where('sinhvien.ma_so_sinh_vien', 'like', '%'.$keyword.'%')
                    ->orWhere(function ($sub) use ($keyword) {
                        if (!str_contains($keyword, ' ')) {
                            $sub->where('sinhvien.ho_ten', 'like', '% '.$keyword)
                                ->orWhere('sinhvien.ho_ten', '=', $keyword);
                        } else {
                            $sub->where('sinhvien.ho_ten', 'like', '%'.$keyword.'%');
                        }
                    });
                if ($loai === 'THUC_TAP') {
                    $q->orWhere('congty.ten_cong_ty', 'like', '%'.$keyword.'%');
                } else {
                    $q->orWhere('detai.ten_de_tai', 'like', '%'.$keyword.'%');
                }
            });
        }

        // Lọc theo lớp học
        if (! empty($filters['className'])) {
            $studentsQuery->where('lop.ten_lop', '=', trim($filters['className']));
        }

        // Lọc theo giảng viên hướng dẫn
        if (! empty($filters['mentor'])) {
            $studentsQuery->where('giangvien.ho_ten', '=', trim($filters['mentor']));
        }

        // Lọc theo trạng thái chấm điểm
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            if ($loai === 'THUC_TAP') {
                if ($filters['status'] === 'draft') {
                    $studentsQuery->whereNull('diemthuctap.diem_so');
                } else {
                    $studentsQuery->whereNotNull('diemthuctap.diem_so');
                }
            } else {
                if ($filters['status'] === 'draft') {
                    $studentsQuery->where(function ($q) {
                        $q->whereNull('diemtongketdatn.diem_bao_cao_chung')
                            ->orWhereRaw('COALESCE(thd.total_lecturers, 0) = 0')
                            ->orWhereRaw('COALESCE(graded.graded_count, 0) < thd.total_lecturers');
                    });
                } else {
                    $studentsQuery->whereNotNull('diemtongketdatn.diem_bao_cao_chung')
                        ->whereRaw('COALESCE(thd.total_lecturers, 0) > 0')
                        ->whereRaw('COALESCE(graded.graded_count, 0) >= thd.total_lecturers');
                }
            }
        }

        $studentsQuery->orderBy('sinhvien.sinh_vien_id', 'desc');

        $paginator = $studentsQuery->paginate($perPage, ['*'], 'page', $page);
        $total = $paginator->total();
        $rows = collect($paginator->items());

        // Chuẩn hóa dữ liệu đầu ra cho frontend
        $rows = $rows->map(function ($row) use ($loai) {
            $row->id = $row->id ? (string) $row->id : 'TEMP_'.$row->sinh_vien_id;
            $row->finalScore = $row->finalScore !== null ? floatval($row->finalScore) : null;
            $row->status = $row->status ?? 'draft';

            if ($loai === 'DO_AN') {
                $row->defenseScore = $row->defenseScore !== null ? round(floatval($row->defenseScore), 2) : null;
                $row->demoScore = $row->demoScore !== null ? round(floatval($row->demoScore), 2) : null;
                $row->qaScore = $row->qaScore !== null ? round(floatval($row->qaScore), 2) : null;
                $row->reportScore = $row->reportScore !== null ? round(floatval($row->reportScore), 2) : null;
            }

            return $row;
        });

        return [
            'rows' => $rows->all(),
            'total' => $total,
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'currentPage' => $paginator->currentPage(),
            'onFirstPage' => $paginator->onFirstPage(),
            'hasMorePages' => $paginator->hasMorePages(),
        ];
    }

    /**
     * Lấy thông tin chi tiết điểm của 1 sinh viên
     */
    public function getScoreDetail($id, $mode = 'internship')
    {
        $loai = $mode === 'project' ? 'DO_AN' : 'THUC_TAP';

        if (strpos($id, 'TEMP_') === 0) {
            $sinhVienId = (int) substr($id, 5);

            // Tìm đợt tốt nghiệp gần nhất tương ứng với loại mà sinh viên có đăng ký
            if ($loai === 'THUC_TAP') {
                $reg = DB::table('dangkythuctap')
                    ->where('sinh_vien_id', $sinhVienId)
                    ->orderBy('dang_ky_id', 'desc')
                    ->first();
                $dotId = $reg ? $reg->dot_id : null;
            } else {
                $reg = DB::table('thanhviennhom')
                    ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                    ->where('thanhviennhom.sinh_vien_id', $sinhVienId)
                    ->orderBy('thanhviennhom.thanh_vien_id', 'desc')
                    ->first();
                $dotId = $reg ? $reg->dot_id : null;
            }
        } else {
            if ($loai === 'THUC_TAP') {
                $score = DB::table('diemthuctap')->where('diem_id', $id)->first();
                if (! $score) {
                    return null;
                }
                $sinhVienId = $score->sinh_vien_id;
                $dotId = $score->dot_id;
            } else {
                $score = DB::table('diemtongketdatn')->where('tong_ket_id', $id)->first();
                if (! $score) {
                    return null;
                }
                $sinhVienId = $score->sinh_vien_id;
                $group = DB::table('thanhviennhom')
                    ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                    ->where('thanhviennhom.sinh_vien_id', $sinhVienId)
                    ->first();
                $dotId = $group ? $group->dot_id : null;
            }
        }

        if (! $dotId) {
            return null;
        }

        if ($loai === 'THUC_TAP') {
            $detail = DB::table('sinhvien')
                ->where('sinhvien.sinh_vien_id', $sinhVienId)
                ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
                ->leftJoin('dangkythuctap', function ($join) use ($dotId) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'dangkythuctap.sinh_vien_id')
                        ->where('dangkythuctap.dot_id', '=', $dotId);
                })
                ->leftJoin('congty', 'dangkythuctap.cong_ty_id', '=', 'congty.cong_ty_id')
                ->leftJoin('phanconghdtt', function ($join) use ($dotId) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'phanconghdtt.sinh_vien_id')
                        ->where('phanconghdtt.dot_id', '=', $dotId);
                })
                ->leftJoin('giangvien', 'phanconghdtt.giang_vien_id', '=', 'giangvien.giang_vien_id')
                ->leftJoin('diemthuctap', function ($join) use ($dotId) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'diemthuctap.sinh_vien_id')
                        ->where('diemthuctap.dot_id', '=', $dotId);
                })
                ->leftJoin('dot', 'dangkythuctap.dot_id', '=', 'dot.dot_id')
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'congty.ten_cong_ty as companyName',
                    'giangvien.ho_ten as mentor',
                    'diemthuctap.diem_id as id',
                    'diemthuctap.diem_so as finalScore',
                    'dot.ten_dot as period',
                    DB::raw("CASE WHEN diemthuctap.diem_so IS NOT NULL THEN 'finalized' ELSE 'draft' END as status"),
                ])
                ->first();
        } else {
            $detail = DB::table('sinhvien')
                ->where('sinhvien.sinh_vien_id', $sinhVienId)
                ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
                ->leftJoin('thanhviennhom', 'sinhvien.sinh_vien_id', '=', 'thanhviennhom.sinh_vien_id')
                ->leftJoin('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->leftJoin('detai', 'nhomsvda.de_tai_id', '=', 'detai.de_tai_id')
                ->leftJoin('giangvien', 'detai.giang_vien_id', '=', 'giangvien.giang_vien_id')
                ->leftJoin('diemtongketdatn', function ($join) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'diemtongketdatn.sinh_vien_id')
                        ->on('nhomsvda.nhom_id', '=', 'diemtongketdatn.nhom_id');
                })
                ->leftJoin('dot', 'nhomsvda.dot_id', '=', 'dot.dot_id')
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'detai.ten_de_tai as topicName',
                    'giangvien.ho_ten as mentor',
                    'diemtongketdatn.tong_ket_id as id',
                    DB::raw('(SELECT SUM(diem_thuyet_trinh) FROM diemhoidongbaove WHERE diemhoidongbaove.sinh_vien_id = sinhvien.sinh_vien_id AND diemhoidongbaove.nhom_id = nhomsvda.nhom_id) / COALESCE(NULLIF((SELECT COUNT(*) FROM thanhvienhoidong WHERE thanhvienhoidong.hoi_dong_id = nhomsvda.hoi_dong_id), 0), 1) as defenseScore'),
                    DB::raw('(SELECT SUM(diem_demo) FROM diemhoidongbaove WHERE diemhoidongbaove.sinh_vien_id = sinhvien.sinh_vien_id AND diemhoidongbaove.nhom_id = nhomsvda.nhom_id) / COALESCE(NULLIF((SELECT COUNT(*) FROM thanhvienhoidong WHERE thanhvienhoidong.hoi_dong_id = nhomsvda.hoi_dong_id), 0), 1) as demoScore'),
                    DB::raw('(SELECT SUM(diem_van_dap) FROM diemhoidongbaove WHERE diemhoidongbaove.sinh_vien_id = sinhvien.sinh_vien_id AND diemhoidongbaove.nhom_id = nhomsvda.nhom_id) / COALESCE(NULLIF((SELECT COUNT(*) FROM thanhvienhoidong WHERE thanhvienhoidong.hoi_dong_id = nhomsvda.hoi_dong_id), 0), 1) as qaScore'),
                    'diemtongketdatn.diem_bao_cao_chung as reportScore',
                    'diemtongketdatn.diem_tong_ket as finalScore',
                    'dot.ten_dot as period',
                    // Cùng logic "đã chấm" với getScoresList(): đủ điểm báo cáo + đủ toàn bộ hội đồng chấm đủ 3 mục.
                    DB::raw("CASE WHEN diemtongketdatn.diem_bao_cao_chung IS NOT NULL
                        AND (SELECT COUNT(*) FROM thanhvienhoidong WHERE thanhvienhoidong.hoi_dong_id = nhomsvda.hoi_dong_id) > 0
                        AND (SELECT COUNT(*) FROM diemhoidongbaove WHERE diemhoidongbaove.sinh_vien_id = sinhvien.sinh_vien_id AND diemhoidongbaove.nhom_id = nhomsvda.nhom_id AND diem_thuyet_trinh IS NOT NULL AND diem_demo IS NOT NULL AND diem_van_dap IS NOT NULL)
                            >= (SELECT COUNT(*) FROM thanhvienhoidong WHERE thanhvienhoidong.hoi_dong_id = nhomsvda.hoi_dong_id)
                        THEN 'finalized' ELSE 'draft' END as status"),
                ])
                ->first();
        }

        if ($detail) {
            $detail->id = $detail->id ? (string) $detail->id : 'TEMP_'.$detail->sinh_vien_id;
            $detail->finalScore = $detail->finalScore !== null ? floatval($detail->finalScore) : null;
            $detail->status = $detail->status ?? 'draft';

            if ($loai === 'DO_AN') {
                $detail->defenseScore = $detail->defenseScore !== null ? round(floatval($detail->defenseScore), 2) : null;
                $detail->demoScore = $detail->demoScore !== null ? round(floatval($detail->demoScore), 2) : null;
                $detail->qaScore = $detail->qaScore !== null ? round(floatval($detail->qaScore), 2) : null;
                $detail->reportScore = $detail->reportScore !== null ? round(floatval($detail->reportScore), 2) : null;
            }
        }

        return $detail;
    }

    /**
     * Chia điểm bảo vệ riêng (thuyết trình hội đồng) tối đa 10 thành các cấu phần:
     * - Thuyết trình (max 3)
     * - Demo/Sản phẩm (max 5)
     * - Hỏi đáp (max 2)
     */
    private function splitDefenseScore($totalScore)
    {
        $totalScore = floatval($totalScore);
        $defense = round($totalScore * 0.3, 2);
        $demo = round($totalScore * 0.5, 2);
        $qa = round($totalScore - $defense - $demo, 2);

        // Capping to make sure they do not exceed maximums
        if ($defense > 3) {
            $diff = $defense - 3;
            $defense = 3;
            $demo += $diff;
        }
        if ($demo > 5) {
            $diff = $demo - 5;
            $demo = 5;
            $qa += $diff;
        }
        if ($qa > 2) {
            $qa = 2;
        }
        if ($qa < 0) {
            $qa = 0;
        }

        return [
            'defenseScore' => $defense,
            'demoScore' => $demo,
            'qaScore' => $qa,
        ];
    }

    /**
     * Tự động tính toán điểm trung bình bảo vệ, báo cáo và điểm tổng kết
     * sau đó cập nhật/insert vào bảng diemtongketdatn.
     */
    public function recalculateScores($sinhVienId, $nhomId)
    {
        // 1. Lấy điểm Báo cáo trung bình
        $dbc = DB::table('diembaocao')->where('sinh_vien_id', $sinhVienId)->where('nhom_id', $nhomId)->first();
        $diemBaoCaoTrungBinh = $dbc ? $dbc->diem_trung_binh : null;

        // Nếu chưa có điểm báo cáo thật và cũng chưa giám khảo nào thực sự chấm điểm bảo vệ,
        // không tạo/động vào bản ghi diemtongketdatn - tránh set trang_thai cho sinh viên chưa được chấm.
        $hasRealDefenseScore = DB::table('diemhoidongbaove')
            ->where('sinh_vien_id', $sinhVienId)
            ->where('nhom_id', $nhomId)
            ->where(function ($w) {
                $w->whereNotNull('diem_thuyet_trinh')
                    ->orWhereNotNull('diem_demo')
                    ->orWhereNotNull('diem_van_dap');
            })
            ->exists();

        if ($diemBaoCaoTrungBinh === null && ! $hasRealDefenseScore) {
            return;
        }

        // 2. Lấy điểm Bảo vệ trung bình - đúng người phải chấm là GVHD + GVPB + giảng viên chấm
        // (giang_vien_cham_id trong lichbaove của riêng nhóm này), KHÔNG PHẢI toàn bộ
        // thanhvienhoidong của hội đồng. Ai trong nhóm này chưa chấm thì tính là 0, chia đều
        // cho đúng số người thuộc 3 vai trò trên.
        $nhomsvda = DB::table('nhomsvda')->where('nhom_id', $nhomId)->first();
        $diemBaoVeTrungBinh = 0.00;

        $lich = DB::table('lichbaove')->where('nhom_id', $nhomId)->first();
        $deTaiForGrading = $nhomsvda && $nhomsvda->de_tai_id
            ? DB::table('detai')->where('de_tai_id', $nhomsvda->de_tai_id)->first()
            : null;
        $gvhdIdForGrading = $deTaiForGrading ? $deTaiForGrading->giang_vien_id : null;
        $reviewerIdForGrading = $lich ? $lich->giang_vien_pb_id : null;
        $examinerIdsForGrading = [];
        if ($lich && $lich->giang_vien_cham_id) {
            $examinerIdsForGrading = array_map('strval', json_decode($lich->giang_vien_cham_id, true) ?: []);
        }

        if (! empty($examinerIdsForGrading)) {
            // Nhóm đã được gán cụ thể giảng viên chấm (kể cả sau khi luân chuyển) - dùng đúng
            // danh sách GVHD + GVPB + giảng viên chấm của riêng nhóm này làm mẫu số.
            $expectedGraders = collect($examinerIdsForGrading)
                ->push($gvhdIdForGrading ? (string) $gvhdIdForGrading : null)
                ->push($reviewerIdForGrading ? (string) $reviewerIdForGrading : null)
                ->filter()
                ->unique()
                ->values();
            $totalLecturers = $expectedGraders->count();

            $scores = DB::table('diemhoidongbaove')
                ->where('sinh_vien_id', $sinhVienId)
                ->where('nhom_id', $nhomId)
                ->whereIn('giang_vien_id', $expectedGraders)
                ->pluck('diem_bao_ve', 'giang_vien_id');

            $sumScores = 0.0;
            foreach ($expectedGraders as $gvId) {
                $scoreVal = isset($scores[$gvId]) ? floatval($scores[$gvId]) : 0.0;
                $sumScores += $scoreVal;
            }

            $diemBaoVeTrungBinh = $totalLecturers > 0 ? round($sumScores / $totalLecturers, 2) : 0.00;
        } elseif ($nhomsvda && $nhomsvda->hoi_dong_id) {
            // Nhóm CHƯA từng được gán cụ thể giảng viên chấm (tình trạng hiện tại của toàn bộ
            // dữ liệu cũ) - giữ nguyên hành vi cũ: đếm theo toàn bộ thành viên hội đồng.
            $councilLecturers = DB::table('thanhvienhoidong')
                ->where('hoi_dong_id', $nhomsvda->hoi_dong_id)
                ->pluck('giang_vien_id');

            if ($councilLecturers->isNotEmpty()) {
                $totalLecturers = $councilLecturers->count();

                $scores = DB::table('diemhoidongbaove')
                    ->where('sinh_vien_id', $sinhVienId)
                    ->where('nhom_id', $nhomId)
                    ->whereIn('giang_vien_id', $councilLecturers)
                    ->pluck('diem_bao_ve', 'giang_vien_id');

                $sumScores = 0.0;
                foreach ($councilLecturers as $gvId) {
                    $scoreVal = isset($scores[$gvId]) ? floatval($scores[$gvId]) : 0.0;
                    $sumScores += $scoreVal;
                }

                $diemBaoVeTrungBinh = round($sumScores / $totalLecturers, 2);
            } else {
                $avgDefense = DB::table('diemhoidongbaove')
                    ->where('sinh_vien_id', $sinhVienId)
                    ->where('nhom_id', $nhomId)
                    ->avg('diem_bao_ve');
                $diemBaoVeTrungBinh = $avgDefense !== null ? round(floatval($avgDefense), 2) : 0.00;
            }
        } else {
            $avgDefense = DB::table('diemhoidongbaove')
                ->where('sinh_vien_id', $sinhVienId)
                ->where('nhom_id', $nhomId)
                ->avg('diem_bao_ve');
            $diemBaoVeTrungBinh = $avgDefense !== null ? round(floatval($avgDefense), 2) : 0.00;
        }

        // 3. Tính điểm tổng kết = 80% Bảo vệ + 20% Báo cáo
        $finalScore = round(($diemBaoVeTrungBinh * 0.8) + (floatval($diemBaoCaoTrungBinh ?? 0) * 0.2), 2);
        $statusVal = $finalScore >= 5 ? 'DAT' : 'KHONG_DAT';

        // 4. Cập nhật bảng diemtongketdatn
        DB::table('diemtongketdatn')->updateOrInsert(
            ['sinh_vien_id' => $sinhVienId, 'nhom_id' => $nhomId],
            [
                'nhom_id' => $nhomId,
                'diem_bao_cao_chung' => $diemBaoCaoTrungBinh,
                'diem_bao_ve_rieng' => $diemBaoVeTrungBinh,
                'diem_tong_ket' => $finalScore,
                'trang_thai' => $statusVal,
            ]
        );
    }
}
