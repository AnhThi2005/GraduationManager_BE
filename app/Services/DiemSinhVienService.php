<?php

namespace App\Services;

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
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'congty.ten_cong_ty as companyName',
                    'giangvien.ho_ten as mentor',
                    'diemthuctap.diem_id as id',
                    'diemthuctap.diem_so as finalScore',
                    DB::raw("CASE WHEN diemthuctap.diem_so IS NOT NULL THEN 'finalized' ELSE 'draft' END as status"),
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
                ->leftJoin('diemtongketdatn', 'sinhvien.sinh_vien_id', '=', 'diemtongketdatn.sinh_vien_id')
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'detai.ten_de_tai as topicName',
                    'giangvien.ho_ten as mentor',
                    'diemtongketdatn.tong_ket_id as id',
                    DB::raw('(SELECT COALESCE(AVG(diem_thuyet_trinh), 0) FROM diemhoidongbaove WHERE diemhoidongbaove.sinh_vien_id = sinhvien.sinh_vien_id AND diemhoidongbaove.diem_bao_ve > 0) as defenseScore'),
                    DB::raw('(SELECT COALESCE(AVG(diem_demo), 0) FROM diemhoidongbaove WHERE diemhoidongbaove.sinh_vien_id = sinhvien.sinh_vien_id AND diemhoidongbaove.diem_bao_ve > 0) as demoScore'),
                    DB::raw('(SELECT COALESCE(AVG(diem_van_dap), 0) FROM diemhoidongbaove WHERE diemhoidongbaove.sinh_vien_id = sinhvien.sinh_vien_id AND diemhoidongbaove.diem_bao_ve > 0) as qaScore'),
                    'diemtongketdatn.diem_bao_cao_chung as reportScore',
                    'diemtongketdatn.diem_tong_ket as finalScore',
                    DB::raw("CASE WHEN diemtongketdatn.trang_thai IS NOT NULL THEN 'finalized' ELSE 'draft' END as status"),
                ]);
        }

        // Áp dụng bộ lọc tìm kiếm theo từ khóa
        if (! empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $studentsQuery->where(function ($q) use ($keyword, $loai) {
                $q->where('sinhvien.ma_so_sinh_vien', 'like', '%'.$keyword.'%')
                    ->orWhere('sinhvien.ho_ten', 'like', '%'.$keyword.'%');
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
                    $studentsQuery->whereNull('diemtongketdatn.trang_thai');
                } else {
                    $studentsQuery->whereNotNull('diemtongketdatn.trang_thai');
                }
            }
        }

        $total = $studentsQuery->count();
        $rows = $studentsQuery->get();

        // Chuẩn hóa dữ liệu đầu ra cho frontend
        $rows = $rows->map(function ($row) use ($loai) {
            $row->id = $row->id ? (string) $row->id : 'TEMP_'.$row->sinh_vien_id;
            $row->finalScore = $row->finalScore !== null ? floatval($row->finalScore) : 0;
            $row->status = $row->status ?? 'draft';

            if ($loai === 'DO_AN') {
                $row->defenseScore = $row->defenseScore !== null ? round(floatval($row->defenseScore), 2) : 0.00;
                $row->demoScore = $row->demoScore !== null ? round(floatval($row->demoScore), 2) : 0.00;
                $row->qaScore = $row->qaScore !== null ? round(floatval($row->qaScore), 2) : 0.00;
                $row->reportScore = $row->reportScore !== null ? round(floatval($row->reportScore), 2) : 0.00;
            }

            return $row;
        });

        return [
            'rows' => $rows->all(),
            'total' => $total,
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
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'congty.ten_cong_ty as companyName',
                    'giangvien.ho_ten as mentor',
                    'diemthuctap.diem_id as id',
                    'diemthuctap.diem_so as finalScore',
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
                ->leftJoin('diemtongketdatn', 'sinhvien.sinh_vien_id', '=', 'diemtongketdatn.sinh_vien_id')
                ->select([
                    'sinhvien.sinh_vien_id',
                    'sinhvien.ma_so_sinh_vien as studentId',
                    'sinhvien.ho_ten as studentName',
                    'lop.ten_lop as className',
                    'detai.ten_de_tai as topicName',
                    'giangvien.ho_ten as mentor',
                    'diemtongketdatn.tong_ket_id as id',
                    DB::raw('(SELECT COALESCE(AVG(diem_thuyet_trinh), 0) FROM diemhoidongbaove WHERE diemhoidongbaove.sinh_vien_id = sinhvien.sinh_vien_id AND diemhoidongbaove.diem_bao_ve > 0) as defenseScore'),
                    DB::raw('(SELECT COALESCE(AVG(diem_demo), 0) FROM diemhoidongbaove WHERE diemhoidongbaove.sinh_vien_id = sinhvien.sinh_vien_id AND diemhoidongbaove.diem_bao_ve > 0) as demoScore'),
                    DB::raw('(SELECT COALESCE(AVG(diem_van_dap), 0) FROM diemhoidongbaove WHERE diemhoidongbaove.sinh_vien_id = sinhvien.sinh_vien_id AND diemhoidongbaove.diem_bao_ve > 0) as qaScore'),
                    'diemtongketdatn.diem_bao_cao_chung as reportScore',
                    'diemtongketdatn.diem_tong_ket as finalScore',
                    DB::raw("CASE WHEN diemtongketdatn.trang_thai IS NOT NULL THEN 'finalized' ELSE 'draft' END as status"),
                ])
                ->first();
        }

        if ($detail) {
            $detail->id = $detail->id ? (string) $detail->id : 'TEMP_'.$detail->sinh_vien_id;
            $detail->finalScore = $detail->finalScore !== null ? floatval($detail->finalScore) : 0;
            $detail->status = $detail->status ?? 'draft';

            if ($loai === 'DO_AN') {
                $detail->defenseScore = $detail->defenseScore !== null ? round(floatval($detail->defenseScore), 2) : 0.00;
                $detail->demoScore = $detail->demoScore !== null ? round(floatval($detail->demoScore), 2) : 0.00;
                $detail->qaScore = $detail->qaScore !== null ? round(floatval($detail->qaScore), 2) : 0.00;
                $detail->reportScore = $detail->reportScore !== null ? round(floatval($detail->reportScore), 2) : 0.00;
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
            $sinhVienId = (int) substr($id, 5);

            if (! $dotId) {
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
            if ($loai === 'THUC_TAP') {
                $scoreRecord = DB::table('diemthuctap')->where('diem_id', $id)->first();
                if (! $scoreRecord) {
                    return null;
                }
                $sinhVienId = $scoreRecord->sinh_vien_id;
                $dotId = $scoreRecord->dot_id;
            } else {
                $scoreRecord = DB::table('diemtongketdatn')->where('tong_ket_id', $id)->first();
                if (! $scoreRecord) {
                    return null;
                }
                $sinhVienId = $scoreRecord->sinh_vien_id;
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

        $scoreId = null;

        DB::beginTransaction();
        try {
            if ($loai === 'THUC_TAP') {
                $finalScore = isset($data['finalScore']) && $data['finalScore'] !== '' && $data['finalScore'] !== null ? round(floatval($data['finalScore']), 2) : null;

                if ($finalScore !== null && ($finalScore < 0 || $finalScore > 10)) {
                    throw new \Exception('Điểm thực tập phải từ 0.00 đến 10.00.');
                }

                // Cần tìm giang_vien_id để lưu vào diemthuctap
                $assignment = DB::table('phanconghdtt')
                    ->where('sinh_vien_id', $sinhVienId)
                    ->where('dot_id', $dotId)
                    ->first();
                $giangVienId = $assignment ? $assignment->giang_vien_id : 1;

                $existing = DB::table('diemthuctap')
                    ->where('sinh_vien_id', $sinhVienId)
                    ->where('dot_id', $dotId)
                    ->first();

                if ($existing) {
                    DB::table('diemthuctap')
                        ->where('diem_id', $existing->diem_id)
                        ->update([
                            'diem_so' => $finalScore,
                            'giang_vien_id' => $giangVienId,
                        ]);
                    $scoreId = $existing->diem_id;
                } else {
                    $scoreId = DB::table('diemthuctap')->insertGetId([
                        'sinh_vien_id' => $sinhVienId,
                        'dot_id' => $dotId,
                        'giang_vien_id' => $giangVienId,
                        'diem_so' => $finalScore,
                    ]);
                }
            } else {
                $existing = DB::table('diemtongketdatn')
                    ->where('sinh_vien_id', $sinhVienId)
                    ->first();

                // Find group
                $groupMember = DB::table('thanhviennhom')
                    ->where('sinh_vien_id', $sinhVienId)
                    ->first();
                $nhomId = $groupMember ? $groupMember->nhom_id : 1;

                if ($existing) {
                    // Find actual averages of components from diemhoidongbaove
                    $avgScores = DB::table('diemhoidongbaove')
                        ->where('sinh_vien_id', $sinhVienId)
                        ->where('diem_bao_ve', '>', 0)
                        ->select([
                            DB::raw('AVG(diem_thuyet_trinh) as avg_presentation'),
                            DB::raw('AVG(diem_demo) as avg_demo'),
                            DB::raw('AVG(diem_van_dap) as avg_qna'),
                        ])
                        ->first();
                    $existingDef = $avgScores ? floatval($avgScores->avg_presentation) : 0;
                    $existingDemo = $avgScores ? floatval($avgScores->avg_demo) : 0;
                    $existingQa = $avgScores ? floatval($avgScores->avg_qna) : 0;

                    $defComponent = isset($data['defenseScore']) ? floatval($data['defenseScore']) : $existingDef;
                    $demoComponent = isset($data['demoScore']) ? floatval($data['demoScore']) : $existingDemo;
                    $qaComponent = isset($data['qaScore']) ? floatval($data['qaScore']) : $existingQa;
                } else {
                    $defComponent = isset($data['defenseScore']) ? floatval($data['defenseScore']) : 0;
                    $demoComponent = isset($data['demoScore']) ? floatval($data['demoScore']) : 0;
                    $qaComponent = isset($data['qaScore']) ? floatval($data['qaScore']) : 0;
                }

                $report = isset($data['reportScore']) ? floatval($data['reportScore']) : ($existing ? floatval($existing->diem_bao_cao_chung) : 0);

                // Server-side validation of scores
                if ($defComponent < 0 || $defComponent > 3 || $demoComponent < 0 || $demoComponent > 5 || $qaComponent < 0 || $qaComponent > 2 || $report < 0 || $report > 10) {
                    throw new \Exception('Điểm số thành phần đồ án tốt nghiệp vượt quá giới hạn.');
                }

                $defense = round($defComponent + $demoComponent + $qaComponent, 2);

                // Find GVHD and GVPB IDs from the group to populate diembaocao
                $nhomsvda = DB::table('nhomsvda')->where('nhom_id', $nhomId)->first();
                $gvhdId = null;
                if ($nhomsvda && $nhomsvda->de_tai_id) {
                    $detai = DB::table('detai')->where('de_tai_id', $nhomsvda->de_tai_id)->first();
                    $gvhdId = $detai ? $detai->giang_vien_id : null;
                }
                $gvpbId = null;
                $lich = DB::table('lichbaove')->where('nhom_id', $nhomId)->first();
                if ($lich && $lich->ghi_chu) {
                    $decoded = json_decode($lich->ghi_chu, true);
                    $gvpbId = $decoded['reviewer_id'] ?? null;
                }

                // A. Save/Update Report Score (Only update diem_trung_binh, do not overwrite gvhd/gvpb)
                $existingDbc = DB::table('diembaocao')->where('sinh_vien_id', $sinhVienId)->first();
                if ($existingDbc) {
                    DB::table('diembaocao')
                        ->where('sinh_vien_id', $sinhVienId)
                        ->update([
                            'diem_trung_binh' => $report,
                            'ngay_cap_nhat' => now(),
                        ]);
                } else {
                    DB::table('diembaocao')->insert([
                        'sinh_vien_id' => $sinhVienId,
                        'nhom_id' => $nhomId,
                        'giang_vien_hd_id' => $gvhdId,
                        'giang_vien_pb_id' => $gvpbId,
                        'diem_gvhd' => null,
                        'diem_gvpb' => null,
                        'diem_trung_binh' => $report,
                        'ngay_cap_nhat' => now(),
                    ]);
                }

                // B. Save/Update Defense Scores for Council members
                if ($nhomsvda && $nhomsvda->hoi_dong_id) {
                    $councilLecturers = DB::table('thanhvienhoidong')->where('hoi_dong_id', $nhomsvda->hoi_dong_id)->pluck('giang_vien_id');
                    if ($councilLecturers->isNotEmpty()) {
                        foreach ($councilLecturers as $gvId) {
                            DB::table('diemhoidongbaove')->updateOrInsert(
                                [
                                    'sinh_vien_id' => $sinhVienId,
                                    'nhom_id' => $nhomId,
                                    'giang_vien_id' => $gvId,
                                ],
                                [
                                    'diem_thuyet_trinh' => $defComponent,
                                    'diem_demo' => $demoComponent,
                                    'diem_van_dap' => $qaComponent,
                                    'diem_bao_ve' => $defense,
                                    'ngay_cham' => now(),
                                ]
                            );
                        }
                    } else {
                        DB::table('diemhoidongbaove')->updateOrInsert(
                            [
                                'sinh_vien_id' => $sinhVienId,
                                'nhom_id' => $nhomId,
                                'giang_vien_id' => $gvhdId ?? 1,
                            ],
                            [
                                'diem_thuyet_trinh' => $defComponent,
                                'diem_demo' => $demoComponent,
                                'diem_van_dap' => $qaComponent,
                                'diem_bao_ve' => $defense,
                                'ngay_cham' => now(),
                            ]
                        );
                    }
                } else {
                    DB::table('diemhoidongbaove')->updateOrInsert(
                        [
                            'sinh_vien_id' => $sinhVienId,
                            'nhom_id' => $nhomId,
                            'giang_vien_id' => $gvhdId ?? 1,
                        ],
                        [
                            'diem_thuyet_trinh' => $defComponent,
                            'diem_demo' => $demoComponent,
                            'diem_van_dap' => $qaComponent,
                            'diem_bao_ve' => $defense,
                            'ngay_cham' => now(),
                        ]
                    );
                }

                // C. Recalculate using optimized helper method
                $this->recalculateScores($sinhVienId, $nhomId);

                $updatedSummary = DB::table('diemtongketdatn')->where('sinh_vien_id', $sinhVienId)->first();
                $scoreId = $updatedSummary ? $updatedSummary->tong_ket_id : null;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->getScoreDetail((string) $scoreId, $loai === 'DO_AN' ? 'project' : 'internship');
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
        $dbc = DB::table('diembaocao')->where('sinh_vien_id', $sinhVienId)->first();
        $diemBaoCaoTrungBinh = $dbc ? $dbc->diem_trung_binh : null;

        // 2. Lấy điểm Bảo vệ trung bình của toàn hội đồng
        $avgDefense = DB::table('diemhoidongbaove')
            ->where('sinh_vien_id', $sinhVienId)
            ->where('diem_bao_ve', '>', 0)
            ->avg('diem_bao_ve');

        $diemBaoVeTrungBinh = $avgDefense !== null ? round(floatval($avgDefense), 2) : 0.00;

        // 3. Tính điểm tổng kết = 80% Bảo vệ + 20% Báo cáo
        $finalScore = round(($diemBaoVeTrungBinh * 0.8) + (floatval($diemBaoCaoTrungBinh ?? 0) * 0.2), 2);
        $statusVal = $finalScore >= 5 ? 'DAT' : 'KHONG_DAT';

        // 4. Cập nhật bảng diemtongketdatn
        DB::table('diemtongketdatn')->updateOrInsert(
            ['sinh_vien_id' => $sinhVienId],
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
