<?php

namespace App\Http\Controllers\GiangVien;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Dot;
use App\Models\Nhom;

class NhomController extends Controller
{
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
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

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
                'congty.email_lien_he'
            ])
            ->get()
            ->map(function ($row) use ($dotId) {
                $latestReport = DB::table('baocaotiendo')
                    ->where('sinh_vien_id', $row->sinh_vien_id)
                    ->where('dot_id', $dotId)
                    ->where('loai_bao_cao', 'THUC_TAP')
                    ->orderBy('tuan_so', 'desc')
                    ->first();

                $statusVal = 'Chưa nộp';
                $reportText = '—';
                $dateText = '—';
                $comment = '';
                if ($latestReport) {
                    $statusVal = $latestReport->trang_thai === 'DA_NOP' ? 'Đã nộp' : 'Trễ hạn';
                    $reportText = 'Tuần ' . $latestReport->tuan_so;
                    $dateText = date('d/m/Y', strtotime($latestReport->thoi_gian_nop));

                    $commentRecord = DB::table('nhanxetbaocao')
                        ->where('bao_cao_id', $latestReport->bao_cao_id)
                        ->first();
                    $comment = $commentRecord ? $commentRecord->noi_dung : '';
                }

                $reports = DB::table('baocaotiendo')
                    ->where('sinh_vien_id', $row->sinh_vien_id)
                    ->where('dot_id', $dotId)
                    ->where('loai_bao_cao', 'THUC_TAP')
                    ->orderBy('tuan_so', 'asc')
                    ->get()
                    ->map(function ($rep) {
                        $commentRecord = DB::table('nhanxetbaocao')
                            ->where('bao_cao_id', $rep->bao_cao_id)
                            ->first();

                        return [
                            'bao_cao_id' => $rep->bao_cao_id,
                            'tuan_so' => $rep->tuan_so,
                            'noi_dung' => $rep->noi_dung ?? '',
                            'duong_dan_file' => $rep->duong_dan_file ?? '',
                            'trang_thai' => $rep->trang_thai === 'DA_NOP' ? 'Đã nộp' : 'Trễ hạn',
                            'thoi_gian_nop' => date('d/m/Y H:i', strtotime($rep->thoi_gian_nop)),
                            'comment' => $commentRecord ? $commentRecord->noi_dung : ''
                        ];
                    })
                    ->all();

                $hasCompany = !empty($row->ten_cong_ty);

                return [
                    'id' => (string)$row->ma_so_sinh_vien,
                    'studentCode' => (string)$row->ma_so_sinh_vien,
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
                    'comment' => $comment
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
            ->map(function ($g) use ($dotId) {
                $memberIds = $g->members->pluck('sinh_vien_id');
                $latestReport = DB::table('baocaotiendo')
                    ->whereIn('sinh_vien_id', $memberIds)
                    ->where('dot_id', $dotId)
                    ->where('loai_bao_cao', 'DO_AN')
                    ->orderBy('tuan_so', 'desc')
                    ->first();

                $statusVal = 'Chưa nộp';
                $latestText = '—';
                $dateText = '—';
                $comment = '';
                if ($latestReport) {
                    $statusVal = $latestReport->trang_thai === 'DA_NOP' ? 'Đã nộp' : 'Trễ hạn';
                    $latestText = 'Bản thảo Chương ' . $latestReport->tuan_so;
                    $dateText = date('d/m/Y', strtotime($latestReport->thoi_gian_nop));

                    $commentRecord = DB::table('nhanxetbaocao')
                        ->where('bao_cao_id', $latestReport->bao_cao_id)
                        ->first();
                    $comment = $commentRecord ? $commentRecord->noi_dung : '';
                }

                $reports = DB::table('baocaotiendo')
                    ->whereIn('sinh_vien_id', $memberIds)
                    ->where('dot_id', $dotId)
                    ->where('loai_bao_cao', 'DO_AN')
                    ->orderBy('tuan_so', 'asc')
                    ->get()
                    ->map(function ($rep) {
                        $commentRecord = DB::table('nhanxetbaocao')
                            ->where('bao_cao_id', $rep->bao_cao_id)
                            ->first();

                        return [
                            'bao_cao_id' => $rep->bao_cao_id,
                            'tuan_so' => $rep->tuan_so,
                            'noi_dung' => $rep->noi_dung ?? '',
                            'duong_dan_file' => $rep->duong_dan_file ?? '',
                            'trang_thai' => $rep->trang_thai === 'DA_NOP' ? 'Đã nộp' : 'Trễ hạn',
                            'thoi_gian_nop' => date('d/m/Y H:i', strtotime($rep->thoi_gian_nop)),
                            'comment' => $commentRecord ? $commentRecord->noi_dung : ''
                        ];
                    })
                    ->all();

                $membersList = $g->members->map(function ($m) {
                    return [
                        'id' => (string)$m->ma_so_sinh_vien,
                        'studentCode' => (string)$m->ma_so_sinh_vien,
                        'name' => $m->ho_ten,
                        'class' => $m->lop ? $m->lop->ten_lop : '—',
                        'is_leader' => (bool)$m->pivot->la_truong_nhom
                    ];
                })->all();

                $topicDetails = $g->deTai ? [
                    'name' => $g->deTai->ten_de_tai,
                    'huong_de_tai' => $g->deTai->huong_de_tai === 'MANG_MAY_TINH' ? 'Mạng máy tính' : ($g->deTai->huong_de_tai === 'PHAN_MEM' ? 'Phát triển phần mềm' : ($g->deTai->huong_de_tai ?? '—')),
                    'limit' => $g->deTai->so_luong_sv_toi_da ?? 0
                ] : null;

                return [
                    'id' => (string)$g->nhom_id,
                    'group' => 'G' . str_pad($g->nhom_id, 2, '0', STR_PAD_LEFT),
                    'topic' => $g->deTai ? $g->deTai->ten_de_tai : 'Nhóm #' . $g->nhom_id,
                    'members' => $g->members->count(),
                    'latest' => $latestText,
                    'status' => $statusVal,
                    'date' => $dateText,
                    'github' => 'github.com/detai-' . $g->nhom_id,
                    'comment' => $comment,
                    'reports' => $reports,
                    'members_list' => $membersList,
                    'topic_details' => $topicDetails
                ];
            })
            ->all();

        return response()->json([
            'success' => true,
            'tttn' => $tttnList,
            'datn' => $datnList
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
            ->with(['members', 'deTai'])
            ->get()
            ->map(function ($g) {
                $statusText = $g->ket_qua_huong_dan !== null ? 'reviewed' : 'pending';
                $eval = $g->ket_qua_huong_dan === 'DAT' ? 'dat' : ($g->ket_qua_huong_dan === 'KHONG_DAT' ? 'khongdat' : '');

                return [
                    'id' => (string)$g->nhom_id,
                    'segment' => 'Nhóm hướng dẫn',
                    'groupName' => 'Nhóm #' . $g->nhom_id,
                    'topicName' => $g->deTai ? $g->deTai->ten_de_tai : '—',
                    'members' => $g->members->count(),
                    'repo' => 'github.com/datn-nhom' . $g->nhom_id,
                    'latestSubmission' => 'bao_cao_tien_do.pdf',
                    'updatedAt' => date('d/m/Y'),
                    'status' => $statusText,
                    'evaluation' => $eval,
                    'note' => $g->nhan_xet_phan_bien ?? ''
                ];
            })
            ->all();

        // 2. Review Groups
        $myCouncilIds = DB::table('thanhvienhoidong')
            ->where('giang_vien_id', $teacherId)
            ->pluck('hoi_dong_id');

        $reviewGroups = Nhom::whereIn('hoi_dong_id', $myCouncilIds)
            ->where('dot_id', $dotId)
            ->with(['members', 'deTai'])
            ->get()
            ->map(function ($g) {
                $statusText = $g->ket_qua_phan_bien !== null ? 'reviewed' : 'pending';
                $eval = $g->ket_qua_phan_bien === 'DAT' ? 'dat' : ($g->ket_qua_phan_bien === 'KHONG_DAT' ? 'khongdat' : '');

                return [
                    'id' => (string)$g->nhom_id,
                    'segment' => 'Nhóm phản biện',
                    'groupName' => 'Nhóm #' . $g->nhom_id,
                    'topicName' => $g->deTai ? $g->deTai->ten_de_tai : '—',
                    'members' => $g->members->count(),
                    'repo' => 'github.com/datn-nhom' . $g->nhom_id,
                    'latestSubmission' => 'bao_cao_phien_ban_chinh_thuc.pdf',
                    'updatedAt' => date('d/m/Y'),
                    'status' => $statusText,
                    'evaluation' => $eval,
                    'note' => $g->nhan_xet_phan_bien ?? ''
                ];
            })
            ->all();

        return response()->json([
            'success' => true,
            'guidanceGroups' => $guidanceGroups,
            'reviewGroups' => $reviewGroups,
            'tttnGroups' => $guidanceGroups,
            'datnGroups' => $reviewGroups
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

        $g = Nhom::with(['deTai', 'members'])->find($groupId);
        if (!$g) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy nhóm này!'], 404);
        }

        $segment = 'Nhóm phản biện';
        if ($g->deTai && $g->deTai->giang_vien_id == $teacherId) {
            $g->ket_qua_huong_dan = $eval;
            $segment = 'Nhóm hướng dẫn';
        } else {
            $g->ket_qua_phan_bien = $eval;
        }

        $g->save();

        $groupObj = [
            'id' => (string)$g->nhom_id,
            'segment' => $segment,
            'groupName' => 'Nhóm #' . $g->nhom_id,
            'topicName' => $g->deTai ? $g->deTai->ten_de_tai : '—',
            'members' => $g->members->count(),
            'repo' => 'github.com/datn-nhom' . $g->nhom_id,
            'latestSubmission' => $segment === 'Nhóm hướng dẫn' ? 'bao_cao_tien_do.pdf' : 'bao_cao_phien_ban_chinh_thuc.pdf',
            'updatedAt' => date('d/m/Y'),
            'status' => 'reviewed',
            'evaluation' => $action === 'accept' ? 'dat' : 'khongdat',
            'note' => $g->nhan_xet_phan_bien ?? ''
        ];

        return response()->json([
            'success' => true,
            'group' => $groupObj
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
            $latestPeriod = \App\Models\Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        $loaiBaoCao = $loai === 'TTTN' ? 'THUC_TAP' : 'DO_AN';
        $report = null;

        if (!empty($baoCaoId)) {
            $report = \Illuminate\Support\Facades\DB::table('baocaotiendo')
                ->where('bao_cao_id', $baoCaoId)
                ->first();
        } else if ($loaiBaoCao === 'DO_AN' && preg_match('/^G(\d+)$/i', $studentCode, $matches)) {
            $nhomId = (int)$matches[1];
            $memberIds = \Illuminate\Support\Facades\DB::table('thanhviennhom')
                ->where('nhom_id', $nhomId)
                ->pluck('sinh_vien_id');

            $report = \Illuminate\Support\Facades\DB::table('baocaotiendo')
                ->whereIn('sinh_vien_id', $memberIds)
                ->where('dot_id', $dotId)
                ->where('loai_bao_cao', 'DO_AN')
                ->orderBy('tuan_so', 'desc')
                ->first();
        } else {
            $sv = \App\Models\SinhVien::where('ma_so_sinh_vien', $studentCode)->first();
            if ($sv) {
                $report = \Illuminate\Support\Facades\DB::table('baocaotiendo')
                    ->where('sinh_vien_id', $sv->sinh_vien_id)
                    ->where('dot_id', $dotId)
                    ->where('loai_bao_cao', $loaiBaoCao)
                    ->orderBy('tuan_so', 'desc')
                    ->first();
            }
        }

        if (!$report) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy báo cáo nào để nhận xét!'], 400);
        }

        \Illuminate\Support\Facades\DB::table('nhanxetbaocao')->updateOrInsert(
            [
                'bao_cao_id' => $report->bao_cao_id,
            ],
            [
                'giang_vien_id' => $teacherId,
                'noi_dung' => $noiDung,
                'danh_gia' => $danhGia,
                'loai_nhan_xet' => $loaiBaoCao
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Lưu nhận xét báo cáo thành công.'
        ]);
    }
}
