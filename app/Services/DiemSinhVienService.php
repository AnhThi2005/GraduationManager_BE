<?php

namespace App\Services;

use App\Models\DiemSinhVien;
use Illuminate\Support\Facades\DB;

class DiemSinhVienService
{
    /**
     * Lấy danh sách điểm tốt nghiệp/thực tập của sinh viên
     */
    public function getScoresList(array $filters)
    {
        $dotId = $filters['periodId'] ?? null;
        $loai = isset($filters['mode']) && $filters['mode'] === 'project' ? 'DO_AN' : 'THUC_TAP';
        
        if (!$dotId) {
            // Lấy đợt tốt nghiệp mới nhất tương ứng với loại
            $latestPeriod = DB::table('dot')
                ->where('loai_dot', $loai === 'DO_AN' ? 'DATN' : 'TTTN')
                ->orderBy('dot_id', 'desc')
                ->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : null;
        }

        if (!$dotId) {
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
                ->leftJoin('diemsinhvien', function ($join) use ($dotId) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'diemsinhvien.sinh_vien_id')
                         ->where('diemsinhvien.dot_id', '=', $dotId)
                         ->where('diemsinhvien.loai', '=', 'THUC_TAP');
                })
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'congty.ten_cong_ty as companyName',
                    'giangvien.ho_ten as mentor',
                    'diemsinhvien.diem_id as id',
                    'diemsinhvien.diem_tong_ket as finalScore',
                    'diemsinhvien.trang_thai as status'
                ]);
        } else {
            // Điểm đồ án tốt nghiệp (DATN)
            $studentsQuery = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.dot_id', $dotId)
                ->join('sinhvien', 'thanhviennhom.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
                ->leftJoin('detai', 'nhomsvda.de_tai_id', '=', 'detai.de_tai_id')
                ->leftJoin('giangvien', 'detai.giang_vien_id', '=', 'giangvien.giang_vien_id')
                ->leftJoin('diemsinhvien', function ($join) use ($dotId) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'diemsinhvien.sinh_vien_id')
                         ->where('diemsinhvien.dot_id', '=', $dotId)
                         ->where('diemsinhvien.loai', '=', 'DO_AN');
                })
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'detai.ten_de_tai as topicName',
                    'giangvien.ho_ten as mentor',
                    'diemsinhvien.diem_id as id',
                    'diemsinhvien.diem_thuyet_trinh as defenseScore',
                    'diemsinhvien.diem_demo as demoScore',
                    'diemsinhvien.diem_van_dap as qaScore',
                    'diemsinhvien.diem_bao_cao as reportScore',
                    'diemsinhvien.diem_tong_ket as finalScore',
                    'diemsinhvien.trang_thai as status'
                ]);
        }

        // Áp dụng bộ lọc tìm kiếm theo từ khóa
        if (!empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $studentsQuery->where(function ($q) use ($keyword, $loai) {
                $q->where('sinhvien.ma_so_sinh_vien', 'like', '%' . $keyword . '%')
                  ->orWhere('sinhvien.ho_ten', 'like', '%' . $keyword . '%');
                if ($loai === 'THUC_TAP') {
                    $q->orWhere('congty.ten_cong_ty', 'like', '%' . $keyword . '%');
                } else {
                    $q->orWhere('detai.ten_de_tai', 'like', '%' . $keyword . '%');
                }
            });
        }

        // Lọc theo lớp học
        if (!empty($filters['className'])) {
            $studentsQuery->where('lop.ten_lop', '=', trim($filters['className']));
        }

        // Lọc theo trạng thái chấm điểm
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'draft') {
                $studentsQuery->where(function ($q) {
                    $q->where('diemsinhvien.trang_thai', '=', 'draft')
                      ->orWhereNull('diemsinhvien.trang_thai');
                });
            } else {
                $studentsQuery->where('diemsinhvien.trang_thai', '=', $filters['status']);
            }
        }

        $total = $studentsQuery->count();
        $rows = $studentsQuery->get();

        // Chuẩn hóa dữ liệu đầu ra cho frontend
        $rows = $rows->map(function ($row) use ($loai) {
            $row->id = $row->id ? (string)$row->id : 'TEMP_' . $row->sinh_vien_id;
            $row->finalScore = $row->finalScore !== null ? floatval($row->finalScore) : 0;
            $row->status = $row->status ?? 'draft';

            if ($loai === 'DO_AN') {
                $row->defenseScore = $row->defenseScore !== null ? floatval($row->defenseScore) : 0;
                $row->demoScore = $row->demoScore !== null ? floatval($row->demoScore) : 0;
                $row->qaScore = $row->qaScore !== null ? floatval($row->qaScore) : 0;
                $row->reportScore = $row->reportScore !== null ? floatval($row->reportScore) : 0;
            }

            return $row;
        });

        return [
            'rows' => $rows->all(),
            'total' => $total
        ];
    }

    /**
     * Lấy thông tin chi tiết điểm của 1 sinh viên
     */
    public function getScoreDetail($id, $mode = 'internship')
    {
        $loai = $mode === 'project' ? 'DO_AN' : 'THUC_TAP';
        
        if (strpos($id, 'TEMP_') === 0) {
            $sinhVienId = (int)substr($id, 5);
            
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
            $score = DiemSinhVien::find($id);
            if (!$score) {
                return null;
            }
            $sinhVienId = $score->sinh_vien_id;
            $dotId = $score->dot_id;
        }

        if (!$dotId) {
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
                ->leftJoin('diemsinhvien', function ($join) use ($dotId) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'diemsinhvien.sinh_vien_id')
                         ->where('diemsinhvien.dot_id', '=', $dotId)
                         ->where('diemsinhvien.loai', '=', 'THUC_TAP');
                })
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'congty.ten_cong_ty as companyName',
                    'giangvien.ho_ten as mentor',
                    'diemsinhvien.diem_id as id',
                    'diemsinhvien.diem_tong_ket as finalScore',
                    'diemsinhvien.trang_thai as status'
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
                ->leftJoin('diemsinhvien', function ($join) use ($dotId) {
                    $join->on('sinhvien.sinh_vien_id', '=', 'diemsinhvien.sinh_vien_id')
                         ->where('diemsinhvien.dot_id', '=', $dotId)
                         ->where('diemsinhvien.loai', '=', 'DO_AN');
                })
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'detai.ten_de_tai as topicName',
                    'giangvien.ho_ten as mentor',
                    'diemsinhvien.diem_id as id',
                    'diemsinhvien.diem_thuyet_trinh as defenseScore',
                    'diemsinhvien.diem_demo as demoScore',
                    'diemsinhvien.diem_van_dap as qaScore',
                    'diemsinhvien.diem_bao_cao as reportScore',
                    'diemsinhvien.diem_tong_ket as finalScore',
                    'diemsinhvien.trang_thai as status'
                ])
                ->first();
        }

        if ($detail) {
            $detail->id = $detail->id ? (string)$detail->id : 'TEMP_' . $detail->sinh_vien_id;
            $detail->finalScore = $detail->finalScore !== null ? floatval($detail->finalScore) : 0;
            $detail->status = $detail->status ?? 'draft';

            if ($loai === 'DO_AN') {
                $detail->defenseScore = $detail->defenseScore !== null ? floatval($detail->defenseScore) : 0;
                $detail->demoScore = $detail->demoScore !== null ? floatval($detail->demoScore) : 0;
                $detail->qaScore = $detail->qaScore !== null ? floatval($detail->qaScore) : 0;
                $detail->reportScore = $detail->reportScore !== null ? floatval($detail->reportScore) : 0;
            }
        }

        return $detail;
    }

    /**
     * Cập nhật hoặc thêm mới điểm số sinh viên
     */
    public function updateScore($id, array $data)
    {
        $dotId = $data['dot_id'] ?? null;
        $loai = isset($data['mode']) && $data['mode'] === 'project' ? 'DO_AN' : 'THUC_TAP';

        if (strpos($id, 'TEMP_') === 0) {
            $sinhVienId = (int)substr($id, 5);
            
            if (!$dotId) {
                // Tự động tìm dotId nếu không có
                if ($loai === 'THUC_TAP') {
                    $reg = DB::table('dangkythuctap')->where('sinh_vien_id', $sinhVienId)->orderBy('dang_ky_id', 'desc')->first();
                    $dotId = $reg ? $reg->dot_id : null;
                } else {
                    $reg = DB::table('thanhviennhom')
                        ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                        ->where('thanhviennhom.sinh_vien_id', $sinhVienId)
                        ->orderBy('thanhviennhom.thanh_vien_id', 'desc')
                        ->first();
                    $dotId = $reg ? $reg->dot_id : null;
                }
            }
        } else {
            $scoreRecord = DiemSinhVien::find($id);
            if (!$scoreRecord) {
                return null;
            }
            $sinhVienId = $scoreRecord->sinh_vien_id;
            $dotId = $scoreRecord->dot_id;
            $loai = $scoreRecord->loai;
        }

        if (!$dotId) {
            return null;
        }

        $updateData = [];
        if (isset($data['status'])) {
            $updateData['trang_thai'] = $data['status'];
        }

        if ($loai === 'THUC_TAP') {
            if (isset($data['finalScore'])) {
                $updateData['diem_tong_ket'] = $data['finalScore'];
            }
        } else {
            // Lấy điểm thành phần hoặc dùng giá trị cũ trong DB (mặc định 0)
            $scoreRecord = DiemSinhVien::where('sinh_vien_id', $sinhVienId)->where('dot_id', $dotId)->where('loai', 'DO_AN')->first();

            $defense = isset($data['defenseScore']) ? $data['defenseScore'] : ($scoreRecord ? $scoreRecord->diem_thuyet_trinh : 0);
            $demo = isset($data['demoScore']) ? $data['demoScore'] : ($scoreRecord ? $scoreRecord->diem_demo : 0);
            $qa = isset($data['qaScore']) ? $data['qaScore'] : ($scoreRecord ? $scoreRecord->diem_van_dap : 0);
            $report = isset($data['reportScore']) ? $data['reportScore'] : ($scoreRecord ? $scoreRecord->diem_bao_cao : 0);

            $updateData['diem_thuyet_trinh'] = $defense;
            $updateData['diem_demo'] = $demo;
            $updateData['diem_van_dap'] = $qa;
            $updateData['diem_bao_cao'] = $report;

            // Tính điểm tổng kết = (thuyết trình + demo + vấn đáp) * 0.8 + báo cáo * 0.2
            $totalComponent = $defense + $demo + $qa;
            $updateData['diem_tong_ket'] = round(($totalComponent * 0.8) + ($report * 0.2), 1);
        }

        $score = DiemSinhVien::updateOrCreate(
            [
                'sinh_vien_id' => $sinhVienId,
                'dot_id' => $dotId,
                'loai' => $loai
            ],
            $updateData
        );

        return $this->getScoreDetail((string)$score->diem_id, $loai === 'DO_AN' ? 'project' : 'internship');
    }
}
