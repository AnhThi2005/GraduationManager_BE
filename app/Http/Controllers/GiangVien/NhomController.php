<?php

namespace App\Http\Controllers\GiangVien;

use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use App\Models\Dot;
use App\Models\Nhom;
use App\Models\SinhVien;
use App\Services\RealtimeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NhomController extends Controller
{
    use KiemTraTrangThaiDot;

    /**
     * GET /private/v1/teacher/students
     */
    public function layDanhSachSinhVien(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $dotId = $request->input('periodId');
        if (empty($dotId)) {
            $latestPeriod = Dot::orderBy('dot_id', 'desc')->first();
            $activePeriod = $latestPeriod;
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        } else {
            $activePeriod = Dot::find($dotId);
        }

        if (! $activePeriod) {
            return response()->json([
                'success' => true,
                'tttn' => [],
                'datn' => [],
            ]);
        }

        $start = Carbon::parse($activePeriod->ngay_bat_dau, 'Asia/Ho_Chi_Minh');
        $end = Carbon::parse($activePeriod->ngay_ket_thuc, 'Asia/Ho_Chi_Minh');
        $now = Carbon::now();
        $totalWeeks = max(1, (int) ceil($start->diffInDays($end) / 7));
        // Hạn chung cả đợt — khớp với cách tính bên SinhVien\BaoCaoController để 2 vai trò
        // luôn thấy cùng 1 trạng thái Thiếu/Trễ cho cùng 1 tuần báo cáo.
        $batchDeadline = $activePeriod->han_nop_bao_cao
            ? Carbon::parse($activePeriod->han_nop_bao_cao)->endOfDay()
            : Carbon::parse($activePeriod->ngay_ket_thuc)->endOfDay();

        // 1. TTTN List (chỉ tính phân công đã được admin công bố, chưa bị xóa mềm)
        $tttnList = DB::table('phanconghdtt')
            ->where('phanconghdtt.giang_vien_id', $teacherId)
            ->where('phanconghdtt.dot_id', $dotId)
            ->where('phanconghdtt.da_cong_bo', true)
            ->whereNull('phanconghdtt.deleted_at')
            ->join('sinhvien', 'phanconghdtt.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
            ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
            ->leftJoin('dangkythuctap', function ($join) use ($dotId) {
                $join->on('sinhvien.sinh_vien_id', '=', 'dangkythuctap.sinh_vien_id')
                    ->where('dangkythuctap.dot_id', '=', $dotId)
                    ->where('dangkythuctap.trang_thai', '=', 'DA_DUYET');
            })
            ->leftJoin('congty', 'dangkythuctap.cong_ty_id', '=', 'congty.cong_ty_id')
            ->select([
                'sinhvien.sinh_vien_id',
                'sinhvien.ma_so_sinh_vien',
                'sinhvien.ho_ten',
                'sinhvien.email as sinh_vien_email',
                'sinhvien.so_dien_thoai as sinh_vien_sdt',
                'lop.ten_lop',
                'lop.chuyen_nganh',
                'dangkythuctap.nguoi_huong_dan',
                'dangkythuctap.sdt_huong_dan',
                'dangkythuctap.vi_tri_thuc_tap',
                'dangkythuctap.dia_chi_thuc_tap',
                'congty.ten_cong_ty',
                'congty.dia_chi as cong_ty_dia_chi',
                'congty.ma_so_thue',
                'congty.email_lien_he',
            ])
            ->get()
            ->map(function ($row) use ($dotId, $activePeriod, $end, $now, $batchDeadline) {
                $dbReports = DB::table('baocaotiendo')
                    ->where('sinh_vien_id', $row->sinh_vien_id)
                    ->where('dot_id', $dotId)
                    ->where('loai_bao_cao', 'THUC_TAP')
                    ->get()
                    ->keyBy('tuan_so');

                $reports = [];
                $w = 1;
                $latestReportWeek = 0;
                $reportText = '—';
                $statusVal = 'Chưa nộp';
                $dateText = '—';
                $comment = '';

                while (true) {
                    $reportStart = $activePeriod->ngay_bat_dau_nop_bao_cao
                        ? Carbon::parse($activePeriod->ngay_bat_dau_nop_bao_cao, 'Asia/Ho_Chi_Minh')
                        : Carbon::parse($activePeriod->ngay_bat_dau, 'Asia/Ho_Chi_Minh');
                    $startOfWeek = $reportStart->copy()->addWeeks($w - 1);
                    if ($startOfWeek->gt($end)) {
                        break;
                    }

                    $rep = $dbReports->get($w);
                    if ($startOfWeek->gt($now) && ! $rep) {
                        break;
                    }

                    $appliedDeadline = $reportStart->copy()->addWeeks($w)->endOfDay();

                    if ($rep) {
                        $commentRecord = DB::table('nhanxetbaocao')
                            ->where('bao_cao_id', $rep->bao_cao_id)
                            ->first();

                        $reports[] = [
                            'bao_cao_id' => $rep->bao_cao_id,
                            'tuan_so' => $rep->tuan_so,
                            'noi_dung' => $rep->noi_dung ?? '',
                            'duong_dan_file' => $rep->duong_dan_file ?? '',
                            'trang_thai' => 'Đã nộp',
                            'thoi_gian_nop' => date('d/m/Y H:i', strtotime($rep->thoi_gian_nop)),
                            'comment' => $commentRecord ? $commentRecord->noi_dung : '',
                            'deadline' => $appliedDeadline->copy()->setTimezone('Asia/Ho_Chi_Minh')->toIso8601String(),
                        ];

                        if ($w > $latestReportWeek) {
                            $latestReportWeek = $w;
                            $statusVal = 'Đã nộp';
                            $reportText = 'Tuần '.$w;
                            $dateText = date('d/m/Y', strtotime($rep->thoi_gian_nop));
                            $comment = $commentRecord ? $commentRecord->noi_dung : '';
                        }
                    } else {
                        // Thiếu (quá hạn chung cả đợt, không nộp được nữa) > Trễ (quá hạn riêng
                        // tuần này nhưng còn hạn chung, sinh viên vẫn nộp được) > Chưa nộp.
                        if ($now->gt($batchDeadline)) {
                            $trangThaiWeek = 'Thiếu';
                        } elseif ($now->gt($appliedDeadline)) {
                            $trangThaiWeek = 'Trễ';
                        } else {
                            $trangThaiWeek = 'Chưa nộp';
                        }
                        $reports[] = [
                            'bao_cao_id' => null,
                            'tuan_so' => $w,
                            'noi_dung' => 'Sinh viên chưa nộp báo cáo.',
                            'duong_dan_file' => '',
                            'trang_thai' => $trangThaiWeek,
                            'thoi_gian_nop' => '—',
                            'comment' => '',
                            'deadline' => $appliedDeadline->copy()->setTimezone('Asia/Ho_Chi_Minh')->toIso8601String(),
                        ];

                        if (($trangThaiWeek === 'Thiếu' || $trangThaiWeek === 'Trễ') && $w > $latestReportWeek) {
                            $latestReportWeek = $w;
                            $statusVal = $trangThaiWeek;
                            $reportText = 'Tuần '.$w;
                            $dateText = '—';
                            $comment = '';
                        }
                    }
                    $w++;
                }

                usort($reports, function ($a, $b) {
                    return $b['tuan_so'] <=> $a['tuan_so'];
                });

                $hasCompany = ! empty($row->ten_cong_ty);

                return [
                    'id' => (string) $row->ma_so_sinh_vien,
                    'studentCode' => (string) $row->ma_so_sinh_vien,
                    'name' => $row->ho_ten,
                    'class' => $row->ten_lop ?? '—',
                    'className' => $row->ten_lop ?? '—',
                    'major' => $row->chuyen_nganh ?? '—',
                    'majorName' => $row->chuyen_nganh ?? '—',
                    'email' => $row->sinh_vien_email ?? '—',
                    'phone' => $row->sinh_vien_sdt ?? '—',

                    // Company info
                    'company' => $hasCompany ? $row->ten_cong_ty : 'Chưa có',
                    'companyName' => $hasCompany ? $row->ten_cong_ty : 'Chưa có',
                    'companyAddress' => $hasCompany ? ($row->cong_ty_dia_chi ?? '—') : '—',
                    'address' => $hasCompany ? ($row->cong_ty_dia_chi ?? '—') : '—',
                    'internshipPosition' => $hasCompany ? ($row->vi_tri_thuc_tap ?? '—') : '—',
                    'field' => $hasCompany ? ($row->vi_tri_thuc_tap ?? '—') : '—',
                    'internshipLocation' => $hasCompany ? ($row->dia_chi_thuc_tap ?? ($row->cong_ty_dia_chi ?? '—')) : '—',
                    'internshipAddress' => $hasCompany ? ($row->dia_chi_thuc_tap ?? ($row->cong_ty_dia_chi ?? '—')) : '—',
                    'taxId' => $hasCompany ? ($row->ma_so_thue ?? '—') : '—',
                    'taxCode' => $hasCompany ? ($row->ma_so_thue ?? '—') : '—',
                    'mentor' => $hasCompany ? ($row->nguoi_huong_dan ?? '—') : '—',
                    'mentorName' => $hasCompany ? ($row->nguoi_huong_dan ?? '—') : '—',
                    'mentorEmail' => $hasCompany ? ($row->email_lien_he ?? '—') : '—',
                    'mentorEmailAddress' => $hasCompany ? ($row->email_lien_he ?? '—') : '—',
                    'mentorPhone' => $hasCompany ? ($row->sdt_huong_dan ?? '—') : '—',
                    'mentorPhoneNo' => $hasCompany ? ($row->sdt_huong_dan ?? '—') : '—',

                    'reports' => $reports,
                    'report' => $reportText,
                    'status' => $statusVal,
                    'date' => $dateText,
                    'comment' => $comment,
                ];
            })
            ->all();

        // 2. DATN List
        $datnList = Nhom::where('dot_id', $dotId)
            ->where('trang_thai_duyet', 'DA_DUYET')
            ->whereHas('deTai', function ($q) use ($teacherId) {
                $q->where('giang_vien_id', $teacherId);
            })
            ->with(['members.lop', 'deTai'])
            ->get()
            ->map(function ($g) use ($dotId, $activePeriod, $end, $now, $batchDeadline) {
                $memberIds = $g->members->pluck('sinh_vien_id');

                $dbReports = DB::table('baocaotiendo')
                    ->whereIn('sinh_vien_id', $memberIds)
                    ->where('dot_id', $dotId)
                    ->where('loai_bao_cao', 'DO_AN')
                    ->get()
                    ->keyBy('tuan_so');

                $reports = [];
                $w = 1;
                $latestReportWeek = 0;
                $latestText = '—';
                $statusVal = 'Chưa nộp';
                $dateText = '—';
                $comment = '';

                while (true) {
                    $reportStart = $activePeriod->ngay_bat_dau_nop_bao_cao
                        ? Carbon::parse($activePeriod->ngay_bat_dau_nop_bao_cao, 'Asia/Ho_Chi_Minh')
                        : Carbon::parse($activePeriod->ngay_bat_dau, 'Asia/Ho_Chi_Minh');
                    $startOfWeek = $reportStart->copy()->addWeeks($w - 1);
                    if ($startOfWeek->gt($end)) {
                        break;
                    }

                    $rep = $dbReports->get($w);
                    if ($startOfWeek->gt($now) && ! $rep) {
                        break;
                    }

                    $appliedDeadline = $reportStart->copy()->addWeeks($w)->endOfDay();

                    if ($rep) {
                        $commentRecord = DB::table('nhanxetbaocao')
                            ->where('bao_cao_id', $rep->bao_cao_id)
                            ->first();

                        $reports[] = [
                            'bao_cao_id' => $rep->bao_cao_id,
                            'tuan_so' => $rep->tuan_so,
                            'noi_dung' => $rep->noi_dung ?? '',
                            'duong_dan_file' => $rep->duong_dan_file ?? '',
                            'trang_thai' => 'Đã nộp',
                            'thoi_gian_nop' => date('d/m/Y H:i', strtotime($rep->thoi_gian_nop)),
                            'comment' => $commentRecord ? $commentRecord->noi_dung : '',
                            'deadline' => $appliedDeadline->copy()->setTimezone('Asia/Ho_Chi_Minh')->toIso8601String(),
                        ];

                        if ($w > $latestReportWeek) {
                            $latestReportWeek = $w;
                            $statusVal = 'Đã nộp';
                            $latestText = 'Bản thảo Chương '.$w;
                            $dateText = date('d/m/Y', strtotime($rep->thoi_gian_nop));
                            $comment = $commentRecord ? $commentRecord->noi_dung : '';
                        }
                    } else {
                        // Thiếu (quá hạn chung cả đợt, không nộp được nữa) > Trễ (quá hạn riêng
                        // tuần này nhưng còn hạn chung, nhóm vẫn nộp được) > Chưa nộp.
                        if ($now->gt($batchDeadline)) {
                            $trangThaiWeek = 'Thiếu';
                        } elseif ($now->gt($appliedDeadline)) {
                            $trangThaiWeek = 'Trễ';
                        } else {
                            $trangThaiWeek = 'Chưa nộp';
                        }
                        $reports[] = [
                            'bao_cao_id' => null,
                            'tuan_so' => $w,
                            'noi_dung' => 'Nhóm chưa nộp bản thảo.',
                            'duong_dan_file' => '',
                            'trang_thai' => $trangThaiWeek,
                            'thoi_gian_nop' => '—',
                            'comment' => '',
                            'deadline' => $appliedDeadline->copy()->setTimezone('Asia/Ho_Chi_Minh')->toIso8601String(),
                        ];

                        if (($trangThaiWeek === 'Thiếu' || $trangThaiWeek === 'Trễ') && $w > $latestReportWeek) {
                            $latestReportWeek = $w;
                            $statusVal = $trangThaiWeek;
                            $latestText = 'Bản thảo Chương '.$w;
                            $dateText = '—';
                            $comment = '';
                        }
                    }
                    $w++;
                }

                usort($reports, function ($a, $b) {
                    return $b['tuan_so'] <=> $a['tuan_so'];
                });

                $membersList = $g->members->map(function ($m) {
                    return [
                        'id' => (string) $m->ma_so_sinh_vien,
                        'studentCode' => (string) $m->ma_so_sinh_vien,
                        'name' => $m->ho_ten,
                        'class' => $m->lop ? $m->lop->ten_lop : '—',
                        'is_leader' => (bool) $m->pivot->la_truong_nhom,
                    ];
                })->all();

                $topicDetails = $g->deTai ? [
                    'name' => $g->deTai->ten_de_tai,
                    'huong_de_tai' => $g->deTai->huong_de_tai === 'MANG_MAY_TINH' ? 'Mạng máy tính' : ($g->deTai->huong_de_tai === 'PHAN_MEM' ? 'Phát triển phần mềm' : ($g->deTai->huong_de_tai ?? '—')),
                    'limit' => $g->deTai->so_luong_sv_toi_da ?? 0,
                ] : null;

                return [
                    'id' => (string) $g->nhom_id,
                    'group' => 'G'.str_pad($g->nhom_id, 2, '0', STR_PAD_LEFT),
                    'topic' => $g->deTai ? $g->deTai->ten_de_tai : 'Nhóm #'.$g->nhom_id,
                    'members' => $g->members->count(),
                    'latest' => $latestText,
                    'status' => $statusVal,
                    'date' => $dateText,
                    'github' => 'github.com/detai-'.$g->nhom_id,
                    'comment' => $comment,
                    'reports' => $reports,
                    'members_list' => $membersList,
                    'topic_details' => $topicDetails,
                ];
            })
            ->all();

        return response()->json([
            'success' => true,
            'tttn' => $tttnList,
            'datn' => $datnList,
        ]);
    }

    /**
     * GET /private/v1/teacher/review-groups
     */
    public function getReviewGroups(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $dotId = $request->input('periodId');
        if (empty($dotId)) {
            $latestPeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        // 1. Guidance Groups
        $guidanceGroups = Nhom::where('dot_id', $dotId)
            ->where('trang_thai_duyet', 'DA_DUYET')
            ->whereHas('deTai', function ($q) use ($teacherId) {
                $q->where('giang_vien_id', $teacherId);
            })
            ->with(['members.lop', 'deTai'])
            ->get()
            ->map(function ($g) {
                $statusText = $g->ket_qua_huong_dan !== null ? 'reviewed' : 'pending';
                $eval = $g->ket_qua_huong_dan === 'DAT' ? 'dat' : ($g->ket_qua_huong_dan === 'KHONG_DAT' ? 'khongdat' : '');

                $membersList = $g->members->map(function ($m) {
                    return [
                        'id' => (string) $m->ma_so_sinh_vien,
                        'name' => $m->ho_ten,
                        'class_name' => $m->lop ? $m->lop->ten_lop : '—',
                        'is_leader' => (bool) $m->pivot->la_truong_nhom,
                    ];
                })->all();

                return [
                    'id' => (string) $g->nhom_id,
                    'segment' => 'Nhóm hướng dẫn',
                    'groupName' => 'Nhóm #'.$g->nhom_id,
                    'topicName' => $g->deTai ? $g->deTai->ten_de_tai : '—',
                    'members' => $g->members->count(),
                    'members_list' => $membersList,
                    'repo' => 'github.com/datn-nhom'.$g->nhom_id,
                    'latestSubmission' => 'bao_cao_tien_do.pdf',
                    'updatedAt' => date('d/m/Y'),
                    'status' => $statusText,
                    'evaluation' => $eval,
                    'reviewerEvaluation' => $g->ket_qua_phan_bien,
                    'note' => $g->nhan_xet_phan_bien ?? '',
                ];
            })
            ->all();

        // 2. Review Groups
        $myReviewGroupIds = DB::table('lichbaove')
            ->where('giang_vien_pb_id', $teacherId)
            ->pluck('nhom_id')
            ->all();

        // Fallback kiểm tra thêm trong ghi_chu JSON (nếu có)
        $allLich = DB::table('lichbaove')->whereNotNull('ghi_chu')->get();
        foreach ($allLich as $l) {
            $decoded = json_decode($l->ghi_chu, true);
            if (isset($decoded['reviewer_id']) && (string) $decoded['reviewer_id'] === (string) $teacherId) {
                if (!in_array($l->nhom_id, $myReviewGroupIds)) {
                    $myReviewGroupIds[] = $l->nhom_id;
                }
            }
        }

        $reviewGroups = Nhom::whereIn('nhom_id', $myReviewGroupIds)
            ->where('dot_id', $dotId)
            ->where('ket_qua_huong_dan', 'DAT')
            ->whereHas('deTai', function ($q) use ($teacherId) {
                $q->where('giang_vien_id', '!=', $teacherId);
            })
            ->with(['members.lop', 'deTai.giangVien'])
            ->get()
            ->map(function ($g) {
                $statusText = $g->ket_qua_phan_bien !== null ? 'reviewed' : 'pending';
                $eval = $g->ket_qua_phan_bien === 'DAT' ? 'dat' : ($g->ket_qua_phan_bien === 'KHONG_DAT' ? 'khongdat' : '');

                $membersList = $g->members->map(function ($m) {
                    return [
                        'id' => (string) $m->ma_so_sinh_vien,
                        'name' => $m->ho_ten,
                        'class_name' => $m->lop ? $m->lop->ten_lop : '—',
                        'is_leader' => (bool) $m->pivot->la_truong_nhom,
                    ];
                })->all();

                $advisorName = ($g->deTai && $g->deTai->giangVien) ? $g->deTai->giangVien->ho_ten : '—';

                return [
                    'id' => (string) $g->nhom_id,
                    'segment' => 'Nhóm phản biện',
                    'groupName' => 'Nhóm #'.$g->nhom_id,
                    'topicName' => $g->deTai ? $g->deTai->ten_de_tai : '—',
                    'advisorName' => $advisorName,
                    'members' => $g->members->count(),
                    'members_list' => $membersList,
                    'repo' => 'github.com/datn-nhom'.$g->nhom_id,
                    'latestSubmission' => 'bao_cao_phien_ban_chinh_thuc.pdf',
                    'updatedAt' => date('d/m/Y'),
                    'status' => $statusText,
                    'evaluation' => $eval,
                    'note' => $g->nhan_xet_phan_bien ?? '',
                ];
            })
            ->all();

        return response()->json([
            'success' => true,
            'guidanceGroups' => $guidanceGroups,
            'reviewGroups' => $reviewGroups,
            'tttnGroups' => $guidanceGroups,
            'datnGroups' => $reviewGroups,
        ]);
    }

    /**
     * PATCH /private/v1/teacher/review-groups/{groupId}
     */
    public function updateReviewGroupStatus(Request $request, $groupId)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;
        $action = $request->input('action');
        $eval = ($action === 'accept') ? 'DAT' : 'KHONG_DAT';

        $g = Nhom::with(['deTai.giangVien', 'members.lop'])->find($groupId);
        if (! $g) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy nhóm này!'], 404);
        }

        if ($resp = $this->chanNeuDotDaDong(Dot::find($g->dot_id))) {
            return $resp;
        }

        $segment = 'Nhóm phản biện';
        if ($g->deTai && $g->deTai->giang_vien_id == $teacherId) {
            if ($g->ket_qua_phan_bien !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không thể đánh giá nhóm này vì giảng viên phản biện đã đánh giá trước đó!'
                ], 422);
            }
            $g->ket_qua_huong_dan = $eval;
            $segment = 'Nhóm hướng dẫn';
        } else {
            $g->ket_qua_phan_bien = $eval;
        }

        $g->save();

        $membersList = $g->members->map(function ($m) {
            return [
                'id' => (string) $m->ma_so_sinh_vien,
                'name' => $m->ho_ten,
                'class_name' => $m->lop ? $m->lop->ten_lop : '—',
                'is_leader' => (bool) $m->pivot->la_truong_nhom,
            ];
        })->all();

        $advisorName = ($g->deTai && $g->deTai->giangVien) ? $g->deTai->giangVien->ho_ten : '—';

        $groupObj = [
            'id' => (string) $g->nhom_id,
            'segment' => $segment,
            'groupName' => 'Nhóm #'.$g->nhom_id,
            'topicName' => $g->deTai ? $g->deTai->ten_de_tai : '—',
            'advisorName' => $advisorName,
            'members' => $g->members->count(),
            'members_list' => $membersList,
            'repo' => 'github.com/datn-nhom'.$g->nhom_id,
            'latestSubmission' => $segment === 'Nhóm hướng dẫn' ? 'bao_cao_tien_do.pdf' : 'bao_cao_phien_ban_chinh_thuc.pdf',
            'updatedAt' => date('d/m/Y'),
            'status' => 'reviewed',
            'evaluation' => $action === 'accept' ? 'dat' : 'khongdat',
            'reviewerEvaluation' => $g->ket_qua_phan_bien,
            'note' => $g->nhan_xet_phan_bien ?? '',
        ];

        RealtimeService::broadcast('slot_updated', [
            'type' => 'group_updated',
            'groupId' => $groupId,
            'payload' => $groupObj,
        ]);

        return response()->json([
            'success' => true,
            'group' => $groupObj,
        ]);
    }

    /**
     * POST /private/v1/teacher/report-comment
     */
    public function saveReportComment(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;
        $studentCode = $request->input('studentId');
        $dotId = $request->input('periodId');
        $noiDung = $request->input('comment');
        $danhGia = $request->input('evaluation', 'DAT');
        $loai = $request->input('type'); // TTTN or DATN
        $baoCaoId = $request->input('baoCaoId');

        if (empty($dotId)) {
            $latestPeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        if ($resp = $this->chanNeuDotDaDong(Dot::find($dotId))) {
            return $resp;
        }

        $loaiBaoCao = $loai === 'TTTN' ? 'THUC_TAP' : 'DO_AN';
        $report = null;

        if (! empty($baoCaoId)) {
            $report = DB::table('baocaotiendo')
                ->where('bao_cao_id', $baoCaoId)
                ->first();
        } elseif ($loaiBaoCao === 'DO_AN' && preg_match('/^G(\d+)$/i', $studentCode, $matches)) {
            $nhomId = (int) $matches[1];
            $memberIds = DB::table('thanhviennhom')
                ->where('nhom_id', $nhomId)
                ->pluck('sinh_vien_id');

            $report = DB::table('baocaotiendo')
                ->whereIn('sinh_vien_id', $memberIds)
                ->where('dot_id', $dotId)
                ->where('loai_bao_cao', 'DO_AN')
                ->orderBy('tuan_so', 'desc')
                ->first();
        } else {
            $sv = SinhVien::where('ma_so_sinh_vien', $studentCode)->first();
            if ($sv) {
                $report = DB::table('baocaotiendo')
                    ->where('sinh_vien_id', $sv->sinh_vien_id)
                    ->where('dot_id', $dotId)
                    ->where('loai_bao_cao', $loaiBaoCao)
                    ->orderBy('tuan_so', 'desc')
                    ->first();
            }
        }

        if (! $report) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy báo cáo nào để nhận xét!'], 400);
        }

        DB::table('nhanxetbaocao')->updateOrInsert(
            [
                'bao_cao_id' => $report->bao_cao_id,
            ],
            [
                'giang_vien_id' => $teacherId,
                'noi_dung' => $noiDung,
                'danh_gia' => $danhGia,
                'loai_nhan_xet' => $loaiBaoCao,
            ]
        );

        RealtimeService::broadcast('slot_updated', [
            'type' => 'group_updated',
            'payload' => [],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lưu nhận xét báo cáo thành công.',
        ]);
    }
}
