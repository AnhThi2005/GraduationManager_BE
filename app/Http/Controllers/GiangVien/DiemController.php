<?php

namespace App\Http\Controllers\GiangVien;

use App\Exceptions\GradingValidationException;
use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use App\Models\Dot;
use App\Models\HoiDong;
use App\Models\Nhom;
use App\Models\SinhVien;
use App\Services\DiemSinhVienService;
use App\Services\RealtimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiemController extends Controller
{
    use KiemTraTrangThaiDot;

    /**
     * GET /private/v1/teacher/grading
     */
    public function getGradingData(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $dotId = $request->input('periodId');
        if (empty($dotId)) {
            $latestPeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        // 1. TTTN rows
        $tttnRows = DB::table('phanconghdtt')
            ->where('phanconghdtt.giang_vien_id', $teacherId)
            ->where('phanconghdtt.dot_id', $dotId)
            ->join('sinhvien', 'phanconghdtt.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
            ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
            ->leftJoin('dangkythuctap', function ($join) use ($dotId) {
                $join->on('sinhvien.sinh_vien_id', '=', 'dangkythuctap.sinh_vien_id')
                    ->where('dangkythuctap.dot_id', '=', $dotId);
            })
            ->leftJoin('congty', 'dangkythuctap.cong_ty_id', '=', 'congty.cong_ty_id')
            ->leftJoin('diemthuctap', function ($join) use ($dotId) {
                $join->on('sinhvien.sinh_vien_id', '=', 'diemthuctap.sinh_vien_id')
                    ->where('diemthuctap.dot_id', '=', $dotId);
            })
            ->select([
                'sinhvien.ma_so_sinh_vien as id',
                'sinhvien.ho_ten as name',
                'sinhvien.ngay_sinh as dob',
                'lop.ten_lop as class',
                'congty.ten_cong_ty as company',
                'diemthuctap.diem_so as score',
            ])
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (string) $row->id,
                    'name' => $row->name,
                    'dob' => $row->dob ? date('d/m/Y', strtotime($row->dob)) : '—',
                    'class' => $row->class ?? '—',
                    'company' => $row->company ?? 'Chưa có công ty thực tập',
                    'score' => $row->score !== null ? (string) $row->score : '',
                    // Bảng diemthuctap không lưu thời điểm cập nhật, nên không có dữ liệu thật để hiện ở đây
                    'updated_at' => null,
                ];
            })
            ->all();

        // 2. Councils & Groups under this teacher
        $councils = HoiDong::where('dot_id', $dotId)
            ->whereHas('giangViens', function ($q) use ($teacherId) {
                $q->where('giangvien.giang_vien_id', $teacherId);
            })
            ->with(['giangViens', 'nhoms.members.lop', 'nhoms.deTai.giangVien'])
            ->get();

        $councilGroups = [];
        $scoreRows = [];

        // Gộp truy vấn lichbaove/diemhoidongbaove/diembaocao của TOÀN BỘ nhóm trong TẤT CẢ
        // hội đồng của giảng viên này thành 3 query duy nhất (thay vì 1 query lichbaove/nhóm +
        // 1 query diemhoidongbaove/thành viên + 1 query diembaocao/thành viên, lặp lại y hệt ở
        // vòng lặp thứ 2 chỉ để đếm — vòng lặp thứ 2 được gộp luôn vào vòng lặp chính bên dưới).
        $allNhomIds = $councils->flatMap(fn ($hd) => $hd->nhoms->pluck('nhom_id'))->unique()->values();
        $lichByNhom = collect();
        $diemHoiDongByMember = collect();
        $diemBaoCaoByMember = collect();
        if ($allNhomIds->isNotEmpty()) {
            $lichByNhom = DB::table('lichbaove')->whereIn('nhom_id', $allNhomIds)->get()->keyBy('nhom_id');
            $diemHoiDongByMember = DB::table('diemhoidongbaove')
                ->whereIn('nhom_id', $allNhomIds)
                ->where('giang_vien_id', $teacherId)
                ->get()
                ->keyBy(fn ($r) => $r->sinh_vien_id.'-'.$r->nhom_id);
            $diemBaoCaoByMember = DB::table('diembaocao')
                ->whereIn('nhom_id', $allNhomIds)
                ->get()
                ->keyBy(fn ($r) => $r->sinh_vien_id.'-'.$r->nhom_id);
        }

        foreach ($councils as $hd) {
            $myPivot = $hd->giangViens->firstWhere('giang_vien_id', $teacherId);
            $roleText = 'Ủy viên';
            if ($myPivot) {
                if ($myPivot->pivot->vai_tro === 'CHU_TICH') {
                    $roleText = 'Chủ tịch';
                } elseif ($myPivot->pivot->vai_tro === 'PHAN_BIEN') {
                    $roleText = 'GVPB';
                }
            }

            $members = $hd->giangViens->map(function ($gv) {
                return [
                    'id' => (string) $gv->giang_vien_id,
                    'name' => $gv->ho_ten,
                    'role' => $gv->pivot->vai_tro === 'CHU_TICH' ? 'Chủ tịch' : ($gv->pivot->vai_tro === 'PHAN_BIEN' ? 'Ủy viên phản biên' : 'Ủy viên'),
                ];
            })->all();

            $groups = [];
            $done = 0;
            foreach ($hd->nhoms as $g) {
                $studentsList = $g->members->map(function ($m) {
                    return [
                        'id' => (string) $m->ma_so_sinh_vien,
                        'name' => $m->ho_ten,
                        'class' => $m->lop ? $m->lop->ten_lop : '—',
                    ];
                })->all();

                $lich = $lichByNhom->get($g->nhom_id);
                $reviewerId = null;
                if ($lich) {
                    $reviewerId = $lich->giang_vien_pb_id;
                    if (!$reviewerId && $lich->ghi_chu) {
                        $decoded = json_decode($lich->ghi_chu, true);
                        $reviewerId = $decoded['reviewer_id'] ?? null;
                    }
                }

                $advisorName = '—';
                if ($g->deTai && $g->deTai->giangVien) {
                    $advisorName = $g->deTai->giangVien->ho_ten;
                }

                $groups[] = [
                    'id' => (string) $g->nhom_id,
                    'groupCode' => 'G'.str_pad($g->nhom_id, 2, '0', STR_PAD_LEFT),
                    'topic' => $g->deTai ? $g->deTai->ten_de_tai : 'Nhóm #'.$g->nhom_id,
                    'advisorId' => $g->deTai ? (string) $g->deTai->giang_vien_id : null,
                    'advisorName' => $advisorName,
                    'reviewerId' => $reviewerId ? (string) $reviewerId : null,
                    'students' => $studentsList,
                ];

                $isAdvisor = ($g->deTai && $g->deTai->giang_vien_id == $teacherId);
                $isReviewer = ($reviewerId == $teacherId);
                $hasAllScores = true;

                foreach ($g->members as $m) {
                    $memberKey = $m->sinh_vien_id.'-'.$g->nhom_id;
                    $myScore = $diemHoiDongByMember->get($memberKey);
                    $dbc = $diemBaoCaoByMember->get($memberKey);
                    $report = null;

                    if ($isAdvisor) {
                        $report = $dbc ? ($dbc->diem_gvhd !== null ? floatval($dbc->diem_gvhd) : null) : null;
                    } elseif ($isReviewer) {
                        $report = $dbc ? ($dbc->diem_gvpb !== null ? floatval($dbc->diem_gvpb) : null) : null;
                    }

                    $scoreRows[] = [
                        'id' => (string) $m->ma_so_sinh_vien,
                        'name' => $m->ho_ten,
                        'chair' => $myScore ? (string) $myScore->diem_bao_ve : '',
                        'secretary' => $myScore ? (string) $myScore->diem_bao_ve : '',
                        'member' => $myScore ? (string) $myScore->diem_bao_ve : '',
                        'advisor' => $myScore ? (string) $myScore->diem_bao_ve : '',
                        'reviewer' => $myScore ? (string) $myScore->diem_bao_ve : '',
                        'isAdvisor' => $isAdvisor,
                        'isReviewer' => $isReviewer,
                        'hasReport' => $report !== null,
                        'hasDefense' => $myScore !== null,
                    ];

                    // Suy ra trực tiếp từ $myScore/$dbc đã lấy ở trên (cùng điều kiện lọc với
                    // truy vấn ::exists() ở vòng lặp thứ 2 cũ) để tính "done" mà không cần
                    // duyệt lại toàn bộ hội đồng/nhóm/thành viên một lần nữa.
                    $defenseExists = $myScore !== null;
                    if ($isAdvisor) {
                        $reportExists = $dbc && $dbc->diem_gvhd !== null;
                    } elseif ($isReviewer) {
                        $reportExists = $dbc && $dbc->diem_gvpb !== null;
                    } else {
                        $reportExists = true;
                    }

                    if (! $defenseExists || ! $reportExists) {
                        $hasAllScores = false;
                    }
                }

                if ($hasAllScores && $g->members->count() > 0) {
                    $done++;
                }
            }

            $councilGroups[] = [
                'code' => 'HD'.str_pad($hd->hoi_dong_id, 2, '0', STR_PAD_LEFT),
                'name' => $hd->ten_hoi_dong,
                'date' => $hd->ngay_bao_ve ? date('d/m/Y', strtotime($hd->ngay_bao_ve)).' • '.($hd->gio_bao_ve ?? '08:00') : '—',
                'room' => $hd->phong_bao_ve ?? '—',
                'role' => $roleText,
                'done' => $done,
                'total' => $hd->nhoms->count(),
                'members' => $members,
                'groups' => $groups,
            ];
        }

        $lastUpdatedAt = null;
        foreach ($tttnRows as $row) {
            if (! empty($row['updated_at'])) {
                if ($lastUpdatedAt === null || strcmp($row['updated_at'], $lastUpdatedAt) > 0) {
                    $lastUpdatedAt = $row['updated_at'];
                }
            }
        }

        return response()->json([
            'success' => true,
            'teacherId' => (string) $teacherId,
            'tttnRows' => $tttnRows,
            'councilGroups' => $councilGroups,
            'scoreRows' => $scoreRows,
            'lastUpdatedAt' => $lastUpdatedAt ? date('Y-m-d H:i:s', strtotime($lastUpdatedAt)) : null,
        ]);
    }

    /**
     * GET /private/v1/teacher/scores?group=groupId
     */
    public function getScores(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $groupId = $request->input('group');
        if (empty($groupId)) {
            return response()->json(['success' => false, 'message' => 'GroupId is required.'], 400);
        }

        $group = Nhom::with('members')->find($groupId);
        if (! $group) {
            return response()->json(['success' => false, 'message' => 'Group not found.'], 404);
        }

        // Kiểm tra xem giảng viên đang đăng nhập có phải là Chủ tịch hoặc Thư ký hội đồng của nhóm này không
        $isChair = false;
        if ($group->hoi_dong_id) {
            $isChair = DB::table('thanhvienhoidong')
                ->where('hoi_dong_id', $group->hoi_dong_id)
                ->where('giang_vien_id', $teacherId)
                ->whereIn('vai_tro', ['CHU_TICH', 'THU_KY'])
                ->exists();
        }

        // Find gvhdId and gvpbId for the group
        $nhom = DB::table('nhomsvda')->where('nhom_id', $groupId)->first();
        $deTai = $nhom && $nhom->de_tai_id ? DB::table('detai')->where('de_tai_id', $nhom->de_tai_id)->first() : null;
        $gvhdId = $deTai ? $deTai->giang_vien_id : null;

        $gvpbId = null;
        $lich = DB::table('lichbaove')->where('nhom_id', $groupId)->first();
        if ($lich) {
            $gvpbId = $lich->giang_vien_pb_id;
            if (!$gvpbId && $lich->ghi_chu) {
                $decoded = json_decode($lich->ghi_chu, true);
                $gvpbId = $decoded['reviewer_id'] ?? null;
            }
        }
        if (! $gvpbId && $nhom && $nhom->hoi_dong_id) {
            $thanhVienPb = DB::table('thanhvienhoidong')
                ->where('hoi_dong_id', $nhom->hoi_dong_id)
                ->where('vai_tro', 'PHAN_BIEN')
                ->first();
            $gvpbId = $thanhVienPb ? $thanhVienPb->giang_vien_id : null;
        }

        // Chỉ GVHD, GVPB, hoặc thành viên hội đồng của nhóm mới được xem chi tiết chấm điểm của nhóm này
        $isCouncilMember = $group->hoi_dong_id ? DB::table('thanhvienhoidong')
            ->where('hoi_dong_id', $group->hoi_dong_id)
            ->where('giang_vien_id', $teacherId)
            ->exists() : false;

        if ($teacherId != $gvhdId && $teacherId != $gvpbId && ! $isCouncilMember) {
            return response()->json(['success' => false, 'message' => 'Bạn không có quyền xem điểm của nhóm này.'], 403);
        }

        // Fetch council members list
        $councilMembers = [];
        if ($group->hoi_dong_id) {
            $gvpbIdFromLich = $gvpbId;
            $examinerIdsFromLich = [];
            if ($lich) {
                if ($lich->giang_vien_cham_id) {
                    $decodedEx = json_decode($lich->giang_vien_cham_id, true);
                    if (is_array($decodedEx)) {
                        $examinerIdsFromLich = array_map('strval', $decodedEx);
                    } else {
                        $examinerIdsFromLich = [strval($lich->giang_vien_cham_id)];
                    }
                }
            }

            $councilMembers = DB::table('thanhvienhoidong')
                ->join('giangvien', 'thanhvienhoidong.giang_vien_id', '=', 'giangvien.giang_vien_id')
                ->where('thanhvienhoidong.hoi_dong_id', $group->hoi_dong_id)
                ->select('giangvien.giang_vien_id as id', 'giangvien.ho_ten as name', 'thanhvienhoidong.vai_tro as role')
                ->get()
                ->map(function ($m) use ($gvpbIdFromLich, $examinerIdsFromLich) {
                    $memberIdStr = (string)$m->id;
                    $displayRole = 'Ủy viên';

                    if ($gvpbIdFromLich && (string)$gvpbIdFromLich === $memberIdStr) {
                        $displayRole = 'Ủy viên phản biên';
                    } elseif (in_array($memberIdStr, $examinerIdsFromLich)) {
                        $displayRole = 'Ủy viên';
                    } elseif ($m->role === 'CHU_TICH') {
                        $displayRole = 'Chủ tịch';
                    } else {
                        $displayRole = $m->role === 'PHAN_BIEN' ? 'Ủy viên phản biên' : ($m->role === 'THU_KY' ? 'Thư ký' : 'Ủy viên');
                    }

                    return [
                        'id' => $memberIdStr,
                        'name' => $m->name,
                        'role' => $displayRole,
                    ];
                })
                ->all();
        }

        // GVHD và GVPB là mặc định được chấm cả điểm báo cáo và điểm bảo vệ hội đồng
        // Nếu họ chưa có trong danh sách councilMembers, tự động thêm vào
        if ($gvhdId) {
            $hasGvhd = false;
            foreach ($councilMembers as $m) {
                if ((string)$m['id'] === (string)$gvhdId) {
                    $hasGvhd = true;
                    break;
                }
            }
            if (!$hasGvhd) {
                $gv = DB::table('giangvien')->where('giang_vien_id', $gvhdId)->first();
                if ($gv) {
                    $councilMembers[] = [
                        'id' => (string)$gvhdId,
                        'name' => $gv->ho_ten,
                        'role' => 'Giảng viên hướng dẫn',
                    ];
                }
            }
        }

        if ($gvpbId) {
            $hasGvpb = false;
            foreach ($councilMembers as $m) {
                if ((string)$m['id'] === (string)$gvpbId) {
                    $hasGvpb = true;
                    break;
                }
            }
            if (!$hasGvpb) {
                $gv = DB::table('giangvien')->where('giang_vien_id', $gvpbId)->first();
                if ($gv) {
                    $councilMembers[] = [
                        'id' => (string)$gvpbId,
                        'name' => $gv->ho_ten,
                        'role' => 'Ủy viên phản biên',
                    ];
                }
            }
        }

        $rows = [];
        foreach ($group->members as $m) {
            // Load this lecturer's defense scores
            $hdbv = DB::table('diemhoidongbaove')
                ->where('sinh_vien_id', $m->sinh_vien_id)
                ->where('nhom_id', $groupId)
                ->where('giang_vien_id', $teacherId)
                ->first();

            $presentation = $hdbv ? ($hdbv->diem_thuyet_trinh !== null ? floatval($hdbv->diem_thuyet_trinh) : null) : null;
            $demo = $hdbv ? ($hdbv->diem_demo !== null ? floatval($hdbv->diem_demo) : null) : null;
            $qna = $hdbv ? ($hdbv->diem_van_dap !== null ? floatval($hdbv->diem_van_dap) : null) : null;
            $diemBaoVe = $hdbv ? ($hdbv->diem_bao_ve !== null ? floatval($hdbv->diem_bao_ve) : null) : null;

            // Load report score based on role
            $dbc = DB::table('diembaocao')->where('sinh_vien_id', $m->sinh_vien_id)->where('nhom_id', $groupId)->first();
            $report = null;

            if ($teacherId == $gvhdId) {
                $report = $dbc ? ($dbc->diem_gvhd !== null ? floatval($dbc->diem_gvhd) : null) : null;
            } elseif ($teacherId == $gvpbId) {
                $report = $dbc ? ($dbc->diem_gvpb !== null ? floatval($dbc->diem_gvpb) : null) : null;
            } else {
                $report = $dbc ? ($dbc->diem_trung_binh !== null ? floatval($dbc->diem_trung_binh) : null) : null;
            }

            // Fallback to diemtongketdatn if diembaocao is not created yet
            if ($report === null) {
                $scoreRecord = DB::table('diemtongketdatn')->where('sinh_vien_id', $m->sinh_vien_id)->where('nhom_id', $groupId)->first();
                if ($scoreRecord && $scoreRecord->diem_bao_cao_chung !== null) {
                    $report = floatval($scoreRecord->diem_bao_cao_chung);
                }
            }

            // 1. Get GVHD and GVPB names
            $gvhdName = '—';
            if ($gvhdId) {
                $gv = DB::table('giangvien')->where('giang_vien_id', $gvhdId)->first();
                $gvhdName = $gv ? $gv->ho_ten : '—';
            }
            $gvpbName = '—';
            if ($gvpbId) {
                $gv = DB::table('giangvien')->where('giang_vien_id', $gvpbId)->first();
                $gvpbName = $gv ? $gv->ho_ten : '—';
            }

            // 2. Get report scores from diembaocao
            $diemGvhd = $dbc ? ($dbc->diem_gvhd !== null ? floatval($dbc->diem_gvhd) : null) : null;
            $diemGvpb = $dbc ? ($dbc->diem_gvpb !== null ? floatval($dbc->diem_gvpb) : null) : null;

            // 3. Get defense average and final total score from diemtongketdatn
            $summary = DB::table('diemtongketdatn')->where('sinh_vien_id', $m->sinh_vien_id)->where('nhom_id', $groupId)->first();
            $diemTbBaoVe = $summary ? ($summary->diem_bao_ve_rieng !== null ? floatval($summary->diem_bao_ve_rieng) : null) : null;
            $diemTongKet = $summary ? ($summary->diem_tong_ket !== null ? floatval($summary->diem_tong_ket) : null) : null;

            // 4. Load defense scores for each lecturer in the council from diemhoidongbaove
            $lecturerScores = [];
            if ($group->hoi_dong_id) {
                $allHdbv = DB::table('diemhoidongbaove')
                    ->where('sinh_vien_id', $m->sinh_vien_id)
                    ->where('nhom_id', $group->nhom_id)
                    ->get();
                foreach ($allHdbv as $sc) {
                    $lecturerScores[(string) $sc->giang_vien_id] = $sc->diem_bao_ve !== null ? floatval($sc->diem_bao_ve) : 0;
                }
            }

            $rows[] = [
                'id' => (string) $m->ma_so_sinh_vien,
                'name' => $m->ho_ten,
                'class' => $m->lop ? $m->lop->ten_lop : '—',
                'presentation' => $presentation,
                'demo' => $demo,
                'qna' => $qna,
                'report' => $report,
                'gvhdName' => $gvhdName,
                'gvpbName' => $gvpbName,
                'diemGvhd' => $diemGvhd,
                'diemGvpb' => $diemGvpb,
                'diemTbBaoVe' => $diemTbBaoVe,
                'diemTongKet' => $diemTongKet,
                'diemBaoVe' => $diemBaoVe,
                'lecturerScores' => $lecturerScores,
                'isAdvisor' => ($teacherId == $gvhdId),
                'isReviewer' => ($teacherId == $gvpbId),
                'advisorId' => $gvhdId ? (string) $gvhdId : null,
                'reviewerId' => $gvpbId ? (string) $gvpbId : null,
                'hasReport' => ($teacherId == $gvhdId ? $diemGvhd !== null : ($teacherId == $gvpbId ? $diemGvpb !== null : false)),
                'hasDefense' => ($hdbv !== null),
                'member' => $hdbv ? (string) $hdbv->diem_bao_ve : '',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'isChair' => $isChair,
                'councilMembers' => $councilMembers,
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * POST /private/v1/teacher/scores
     */
    public function saveScores(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $groupId = $request->input('group');
        $rows = $request->input('rows', []);

        if (empty($groupId)) {
            return response()->json(['success' => false, 'message' => 'GroupId is required.'], 400);
        }

        // Find nhomsvda and related fields
        $nhom = DB::table('nhomsvda')->where('nhom_id', $groupId)->first();
        if (! $nhom) {
            return response()->json(['success' => false, 'message' => 'Nhom not found.'], 404);
        }
        $hoiDongId = $nhom->hoi_dong_id;

        if ($resp = $this->chanNeuKhongDuocSuaDiem(Dot::find($nhom->dot_id))) {
            return $resp;
        }

        $deTai = $nhom->de_tai_id ? DB::table('detai')->where('de_tai_id', $nhom->de_tai_id)->first() : null;
        $gvhdId = $deTai ? $deTai->giang_vien_id : null;

        // Resolve the specific GVPB (Reviewer) assigned to this group from the schedule
        $lich = DB::table('lichbaove')->where('nhom_id', $groupId)->first();
        $reviewerId = null;
        if ($lich) {
            $reviewerId = $lich->giang_vien_pb_id;
            if (!$reviewerId && $lich->ghi_chu) {
                $decoded = json_decode($lich->ghi_chu, true);
                $reviewerId = $decoded['reviewer_id'] ?? null;
            }
        }



        // Chỉ GVHD, GVPB được phân công, hoặc thành viên hội đồng của nhóm mới được phép chấm điểm nhóm này
        $isCouncilMember = $hoiDongId ? DB::table('thanhvienhoidong')
            ->where('hoi_dong_id', $hoiDongId)
            ->where('giang_vien_id', $teacherId)
            ->exists() : false;

        if ($teacherId != $gvhdId && $teacherId != $reviewerId && ! $isCouncilMember) {
            return response()->json(['success' => false, 'message' => 'Bạn không có quyền chấm điểm cho nhóm này.'], 403);
        }

        try {
            DB::transaction(function () use ($rows, $groupId, $gvhdId, $reviewerId, $teacherId, $isCouncilMember) {
                foreach ($rows as $row) {
                    $studentCode = $row['id'] ?? '';
                    $sv = SinhVien::where('ma_so_sinh_vien', $studentCode)->first();
                    if (! $sv) {
                        continue;
                    }

                    $presentation = (isset($row['presentation']) && $row['presentation'] !== null && $row['presentation'] !== '') ? floatval($row['presentation']) : null;
                    $demo = (isset($row['demo']) && $row['demo'] !== null && $row['demo'] !== '') ? floatval($row['demo']) : null;
                    $qna = (isset($row['qna']) && $row['qna'] !== null && $row['qna'] !== '') ? floatval($row['qna']) : null;
                    $report = (isset($row['report']) && $row['report'] !== null && $row['report'] !== '') ? floatval($row['report']) : null;

                    if (($presentation !== null && ($presentation < 0 || $presentation > 3))
                        || ($demo !== null && ($demo < 0 || $demo > 5))
                        || ($qna !== null && ($qna < 0 || $qna > 2))
                        || ($report !== null && ($report < 0 || $report > 10))) {
                        throw new GradingValidationException('Điểm thành phần không hợp lệ.', 400);
                    }

                    // A. Điểm Báo cáo (20%):
                    $existingDbc = DB::table('diembaocao')->where('sinh_vien_id', $sv->sinh_vien_id)->where('nhom_id', $groupId)->first();
                    $diemGvhd = $existingDbc ? $existingDbc->diem_gvhd : null;
                    $diemGvpb = $existingDbc ? $existingDbc->diem_gvpb : null;

                    if ($teacherId == $gvhdId) {
                        $diemGvhd = $report;
                    } elseif ($teacherId == $reviewerId) {
                        $diemGvpb = $report;
                    } else {
                        // Đối với giảng viên khác, nếu điểm báo cáo gửi lên khác với điểm trung bình hiện có tức là họ đang cố tình sửa điểm báo cáo
                        $originalAvg = $existingDbc ? ($existingDbc->diem_trung_binh !== null ? floatval($existingDbc->diem_trung_binh) : null) : null;
                        if ($report !== null && ($originalAvg === null || abs($originalAvg - $report) > 0.0001)) {
                            throw new GradingValidationException('Bạn không có quyền thay đổi điểm báo cáo (chỉ GVHD hoặc GVPB được phân công cho nhóm mới được phép).', 403);
                        }
                    }

                    $diemBaoCaoTrungBinh = null;
                    if ($diemGvhd !== null || $diemGvpb !== null) {
                        $diemBaoCaoTrungBinh = round((floatval($diemGvhd ?? 0) + floatval($diemGvpb ?? 0)) / 2, 2);
                    }

                    DB::table('diembaocao')->updateOrInsert(
                        [
                            'sinh_vien_id' => $sv->sinh_vien_id,
                            'nhom_id' => $groupId,
                        ],
                        [
                            'giang_vien_hd_id' => $gvhdId,
                            'giang_vien_pb_id' => $reviewerId,
                            'diem_gvhd' => $diemGvhd,
                            'diem_gvpb' => $diemGvpb,
                            'diem_trung_binh' => $diemBaoCaoTrungBinh,
                            'ngay_cap_nhat' => now(),
                        ]
                    );

                    // B. Điểm Bảo vệ (80%) — chỉ ghi khi người chấm thực sự là thành viên hội đồng
                    // hoặc là GVHD / GVPB được mặc định quyền,
                    // và đã thực sự nhập ít nhất 1 thành phần điểm cho sinh viên này.
                    // Tránh: (1) GVHD/GVPB không thuộc hội đồng vô tình ghi đè bằng điểm 0;
                    // (2) payload gửi cả nhóm mỗi lần Lưu khiến các sinh viên CHƯA được chấm
                    // cũng bị tạo bản ghi rác (điểm 0) chỉ vì đứng chung nhóm với người vừa được chấm.
                    $canGradeDefense = $isCouncilMember || ($teacherId == $gvhdId) || ($teacherId == $reviewerId);
                    if ($canGradeDefense && ($presentation !== null || $demo !== null || $qna !== null)) {
                        // 1. Tính điểm bảo vệ của từng giảng viên: diem_bao_ve = diem_thuyet_trinh + diem_demo + diem_van_dap
                        $diemBaoVeGv = round(($presentation ?? 0) + ($demo ?? 0) + ($qna ?? 0), 2);

                        DB::table('diemhoidongbaove')->updateOrInsert(
                            [
                                'sinh_vien_id' => $sv->sinh_vien_id,
                                'nhom_id' => $groupId,
                                'giang_vien_id' => $teacherId,
                            ],
                            [
                                'diem_thuyet_trinh' => $presentation,
                                'diem_demo' => $demo,
                                'diem_van_dap' => $qna,
                                'diem_bao_ve' => $diemBaoVeGv,
                                'ngay_cham' => now(),
                            ]
                        );
                    }

                    // Tự động tính toán lại điểm trung bình bảo vệ, báo cáo và điểm tổng kết
                    $diemSinhVienService = app(DiemSinhVienService::class);
                    $diemSinhVienService->recalculateScores($sv->sinh_vien_id, $groupId);
                }
            });
        } catch (GradingValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }

        RealtimeService::broadcast('score_updated', [
            'type' => 'score_updated',
            'groupId' => $groupId,
            'message' => 'Điểm hội đồng đồ án tốt nghiệp đã được cập nhật',
        ]);

        RealtimeService::broadcast('notification', [
            'type' => 'score_updated',
            'groupId' => $groupId,
            'message' => 'Điểm hội đồng đồ án tốt nghiệp đã được cập nhật',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lưu điểm thành công.',
        ]);
    }

    /**
     * Helper to split defense score out of 10 into 3 parts (presentation: 3, demo: 5, qna: 2)
     */
    private function splitDefenseScore($totalScore)
    {
        $totalScore = floatval($totalScore);
        $defense = round($totalScore * 0.3, 2);
        $demo = round($totalScore * 0.5, 2);
        $qa = round($totalScore - $defense - $demo, 2);

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
            'presentation' => $defense,
            'demo' => $demo,
            'qna' => $qa,
        ];
    }

    /**
     * POST /private/v1/teacher/tttn-scores
     */
    public function saveTttnScores(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;
        $dotId = $request->input('periodId');
        if (empty($dotId)) {
            $latestPeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        if ($resp = $this->chanNeuKhongDuocSuaDiem(Dot::find($dotId))) {
            return $resp;
        }

        $scores = $request->input('scores', []);

        foreach ($scores as $s) {
            $studentCode = $s['id'] ?? '';
            $scoreVal = $s['score'] !== '' && $s['score'] !== null ? round(floatval($s['score']), 1) : null;

            if ($scoreVal !== null && ($scoreVal < 0 || $scoreVal > 10)) {
                return response()->json(['success' => false, 'message' => 'Điểm thực tập phải trong khoảng từ 0 đến 10.'], 400);
            }

            $sv = SinhVien::where('ma_so_sinh_vien', $studentCode)->first();
            if (! $sv) {
                continue;
            }

            // Verify teacher is assigned to this student in this period
            $assigned = DB::table('phanconghdtt')
                ->where('giang_vien_id', $teacherId)
                ->where('sinh_vien_id', $sv->sinh_vien_id)
                ->where('dot_id', $dotId)
                ->exists();

            if (! $assigned) {
                continue;
            }

            if ($scoreVal === null) {
                DB::table('diemthuctap')
                    ->where('sinh_vien_id', $sv->sinh_vien_id)
                    ->where('dot_id', $dotId)
                    ->delete();
            } else {
                DB::table('diemthuctap')->updateOrInsert(
                    [
                        'sinh_vien_id' => $sv->sinh_vien_id,
                        'dot_id' => $dotId,
                    ],
                    [
                        'giang_vien_id' => $teacherId,
                        'diem_so' => $scoreVal,
                    ]
                );
            }
        }

        RealtimeService::broadcast('score_updated', [
            'type' => 'tttn_score_updated',
            'periodId' => $dotId,
            'message' => 'Điểm thực tập tốt nghiệp đã được cập nhật',
        ]);

        RealtimeService::broadcast('notification', [
            'type' => 'tttn_score_updated',
            'periodId' => $dotId,
            'message' => 'Điểm thực tập tốt nghiệp đã được cập nhật',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lưu điểm thực tập thành công.',
        ]);
    }
}
