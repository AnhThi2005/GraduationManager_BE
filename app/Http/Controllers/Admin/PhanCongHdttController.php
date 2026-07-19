<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use App\Models\Dot;
use App\Models\GiangVien;
use App\Models\LichSuHoatDong;
use App\Models\Nhom;
use App\Models\PhanCongHdtt;
use App\Models\SinhVien;
use App\Services\RealtimeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhanCongHdttController extends Controller
{
    use KiemTraTrangThaiDot;

    public function layDanhSach(Request $request)
    {
        // Lấy đợt tốt nghiệp từ tham số truyền lên, nếu không có thì lấy đợt mới nhất
        $dotId = $request->input('periodId') ?? $request->input('period_id');
        if (empty($dotId)) {
            $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : 1;
        }

        $dot = Dot::find($dotId);
        $isTttn = $dot && $dot->loai_dot === 'TTTN';

        // Lấy danh sách lớp học được gán cho đợt này
        $lopIdsInPeriod = DB::table('dot_lop')->where('dot_id', $dotId)->pluck('lop_id');

        if ($isTttn) {
            // --- LOGIC CHO ĐỢT THỰC TẬP TỐT NGHIỆP (TTTN) ---
            // Lấy danh sách sinh viên tham gia đợt TTTN:
            // 1. Sinh viên thuộc các lớp được gán cho đợt này (dot_lop)
            // 2. Sinh viên tự do được gán riêng cho đợt này (dot_sinhvien)
            // 3. Sinh viên đã được phân công trong đợt này (phanconghdtt)
            $studentIdsInDotSinhvien = DB::table('dot_sinhvien')->where('dot_id', $dotId)->pluck('sinh_vien_id');
            $studentIdsInPhanCong = DB::table('phanconghdtt')->where('dot_id', $dotId)->pluck('sinh_vien_id');

            $students = SinhVien::query()
                ->where(function ($q) use ($lopIdsInPeriod, $studentIdsInDotSinhvien, $studentIdsInPhanCong) {
                    if ($lopIdsInPeriod->isNotEmpty()) {
                        $q->whereIn('lop_id', $lopIdsInPeriod);
                    }
                    if ($studentIdsInDotSinhvien->isNotEmpty()) {
                        $q->orWhereIn('sinh_vien_id', $studentIdsInDotSinhvien);
                    }
                    if ($studentIdsInPhanCong->isNotEmpty()) {
                        $q->orWhereIn('sinh_vien_id', $studentIdsInPhanCong);
                    }
                    if ($lopIdsInPeriod->isEmpty() && $studentIdsInDotSinhvien->isEmpty() && $studentIdsInPhanCong->isEmpty()) {
                        $q->whereNull('sinh_vien_id'); // Trả về trống nếu không có điều kiện nào
                    }
                })
                ->with('lop')
                ->get();

            // Lấy phân công theo đợt
            $assignments = PhanCongHdtt::with('giangVien')
                ->where('dot_id', $dotId)
                ->get()
                ->keyBy('sinh_vien_id');

            // Sắp xếp: sinh viên vừa được phân công gần đây nhất lên đầu danh sách
            $students = $students->sortByDesc(function ($sv) use ($assignments) {
                $assign = $assignments->get($sv->sinh_vien_id);

                return $assign && $assign->ngay_phan_cong ? $assign->ngay_phan_cong->timestamp : 0;
            })->values();

            // Lấy đăng ký thực tập theo đợt
            $internshipRegs = DB::table('dangkythuctap')
                ->join('congty', 'dangkythuctap.cong_ty_id', '=', 'congty.cong_ty_id')
                ->select('dangkythuctap.sinh_vien_id', 'congty.ten_cong_ty')
                ->where('dangkythuctap.trang_thai', 'DA_DUYET')
                ->where('dangkythuctap.dot_id', $dotId)
                ->get()
                ->keyBy('sinh_vien_id');

            $rows = $students->map(function ($sv) use ($assignments, $internshipRegs) {
                $assign = $assignments->get($sv->sinh_vien_id);

                $topic = '—';
                if ($internshipRegs->has($sv->sinh_vien_id)) {
                    $topic = 'Thực tập tại '.$internshipRegs->get($sv->sinh_vien_id)->ten_cong_ty;
                }

                return [
                    'id' => (string) $sv->ma_so_sinh_vien,
                    'studentId' => (string) $sv->ma_so_sinh_vien,
                    'name' => $sv->ho_ten,
                    'className' => $sv->lop ? $sv->lop->ten_lop : '—',
                    'course' => $sv->lop ? $sv->lop->khoa_hoc : '—',
                    'topic' => $topic,
                    'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
                    'assignedAt' => $assign && $assign->ngay_phan_cong ? $assign->ngay_phan_cong->format('d/m/Y H:i') : null,
                    'published' => $assign ? (bool) $assign->da_cong_bo : false,
                    'status' => $assign ? 'assigned' : 'unassigned',
                    'dieuKienLamDoAn' => $sv->dieu_kien_lam_do_an ?? 'DAT',
                ];
            })->all();

        } else {
            // --- LOGIC CHO ĐỢT ĐỒ ÁN TỐT NGHIỆP (ĐATN) ---
            // Lấy danh sách sinh viên có nhóm trong đợt này
            $groupStudentIds = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.dot_id', $dotId)
                ->pluck('thanhviennhom.sinh_vien_id');

            // Lấy danh sách sinh viên được gán thủ công vào đợt này (dot_sinhvien)
            $studentIdsInDotSinhvien = DB::table('dot_sinhvien')
                ->where('dot_id', $dotId)
                ->pluck('sinh_vien_id');

            // Gộp các nguồn ID sinh viên đồ án tốt nghiệp
            $studentIds = collect()
                ->concat($groupStudentIds)
                ->concat($studentIdsInDotSinhvien)
                ->unique();

            // Danh sách sinh viên có hoạt động nhóm ở các đợt khác mà đợt đó chưa đóng (đang hoạt động)
            $studentIdsInOtherPeriods = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->join('dot', 'nhomsvda.dot_id', '=', 'dot.dot_id')
                ->where('nhomsvda.dot_id', '!=', $dotId)
                ->where('dot.trang_thai', '!=', 'DA_DONG')
                ->pluck('thanhviennhom.sinh_vien_id')
                ->unique();

            $query = SinhVien::query()->with('lop');

            if ($lopIdsInPeriod->isNotEmpty() || $studentIds->isNotEmpty()) {
                $query->where(function ($q) use ($lopIdsInPeriod, $studentIds, $studentIdsInOtherPeriods) {
                    $q->whereIn('sinh_vien_id', $studentIds);

                    if ($lopIdsInPeriod->isNotEmpty()) {
                        $q->orWhere(function ($subQ) use ($lopIdsInPeriod, $studentIdsInOtherPeriods) {
                            $subQ->whereIn('lop_id', $lopIdsInPeriod);
                            if ($studentIdsInOtherPeriods->isNotEmpty()) {
                                $subQ->whereNotIn('sinh_vien_id', $studentIdsInOtherPeriods);
                            }
                        });
                    }
                });
            } else {
                $query->whereNull('sinh_vien_id');
            }

            $students = $query->get();

            // Lấy danh sách nhóm trong đợt này cùng với thành viên và đề tài
            $groups = Nhom::with(['members', 'deTai.giangVien'])
                ->where('dot_id', $dotId)
                ->get();

            $studentGroupMap = [];
            foreach ($groups as $g) {
                foreach ($g->members as $m) {
                    $studentGroupMap[$m->sinh_vien_id] = $g;
                }
            }

            // Lấy danh sách đăng ký đề tài của các nhóm để check xem có bị từ chối hết hay không
            $groupRegistrations = DB::table('dangkydetai')
                ->whereIn('nhom_id', $groups->pluck('nhom_id'))
                ->get()
                ->groupBy('nhom_id');

            // Lấy danh sách ghi đè từ dot_sinhvien
            $dotStudents = DB::table('dot_sinhvien')
                ->where('dot_id', $dotId)
                ->get()
                ->keyBy('sinh_vien_id');

            $rows = $students->map(function ($sv) use ($studentGroupMap, $groupRegistrations, $dotStudents) {
                $groupId = null;
                $groupCode = null;
                $groupStatus = 'no_group';
                $hasIneligibleMember = false;
                $hasTopic = false;
                $topicStatus = 'no_registration';
                $topic = '—';
                $supervisor = null;
                $assignedAtDate = null;

                if (isset($studentGroupMap[$sv->sinh_vien_id])) {
                    $group = $studentGroupMap[$sv->sinh_vien_id];
                    $groupId = (string) $group->nhom_id;
                    $groupCode = null;

                    // Check eligibility of members
                    foreach ($group->members as $m) {
                        $eRecord = $dotStudents->get($m->sinh_vien_id);
                        $eligible = ($eRecord ? ($eRecord->dieu_kien_lam_do_an ?? 'DAT') : 'DAT') === 'DAT';
                        if (! $eligible) {
                            $hasIneligibleMember = true;
                        }
                    }

                    // Check topic status
                    if ($group->de_tai_id) {
                        $hasTopic = true;
                        $topicStatus = 'approved';
                        $topic = $group->deTai ? $group->deTai->ten_de_tai : 'Nhóm đề tài #'.$group->nhom_id;
                        if ($group->deTai && $group->deTai->giangVien) {
                            $supervisor = $group->deTai->giangVien->ho_ten;

                            // Tìm ngày đăng ký đề tài được duyệt của nhóm
                            $regs = $groupRegistrations->get($group->nhom_id) ?? collect();
                            $approvedReg = $regs->first(function ($r) use ($group) {
                                return $r->de_tai_id == $group->de_tai_id && $r->trang_thai_duyet === 'DA_DUYET';
                            });
                            if ($approvedReg && ! empty($approvedReg->ngay_dang_ky)) {
                                $assignedAtDate = Carbon::parse($approvedReg->ngay_dang_ky)->format('d/m/Y H:i');
                            }
                        }
                    } else {
                        $regs = $groupRegistrations->get($group->nhom_id) ?? collect();
                        if ($regs->isEmpty()) {
                            $topicStatus = 'no_registration';
                            $topic = '—';
                        } else {
                            $allRejected = true;
                            $hasPending = false;
                            foreach ($regs as $r) {
                                if ($r->trang_thai_duyet !== 'TU_CHOI') {
                                    $allRejected = false;
                                }
                                if ($r->trang_thai_duyet === 'CHO_DUYET' || $r->trang_thai_duyet === 'PENDING') {
                                    $hasPending = true;
                                }
                            }

                            if ($allRejected) {
                                $topicStatus = 'all_rejected';
                                $topic = 'Đăng ký đề tài bị từ chối';
                            } elseif ($hasPending) {
                                $topicStatus = 'pending_registration';
                                $topic = 'Đang chờ duyệt đăng ký';
                            } else {
                                $topicStatus = 'no_registration';
                            }
                        }
                    }

                    // Determine groupStatus
                    if ($hasIneligibleMember) {
                        $groupStatus = 'ineligible_member';
                    } elseif (! $hasTopic) {
                        if ($topicStatus === 'all_rejected') {
                            $groupStatus = 'topic_rejected';
                        } elseif ($topicStatus === 'pending_registration') {
                            $groupStatus = 'topic_pending';
                        } else {
                            $groupStatus = 'no_topic';
                        }
                    } else {
                        $groupStatus = 'valid';
                    }
                }

                $dieuKienVal = 'DAT';
                $eRecord = $dotStudents->get($sv->sinh_vien_id);
                if ($eRecord) {
                    $dieuKienVal = $eRecord->dieu_kien_lam_do_an ?? 'DAT';
                }

                return [
                    'id' => (string) $sv->ma_so_sinh_vien,
                    'studentId' => (string) $sv->ma_so_sinh_vien,
                    'name' => $sv->ho_ten,
                    'className' => $sv->lop ? $sv->lop->ten_lop : '—',
                    'course' => $sv->lop ? $sv->lop->khoa_hoc : '—',
                    'topic' => $topic,
                    'supervisor' => $supervisor,
                    'assignedAt' => $assignedAtDate,
                    'status' => $supervisor ? 'assigned' : 'unassigned',
                    'dieuKienLamDoAn' => $dieuKienVal,

                    // Group details
                    'groupId' => $groupId,
                    'groupCode' => $groupCode,
                    'groupStatus' => $groupStatus,
                    'hasIneligibleMember' => $hasIneligibleMember,
                    'hasTopic' => $hasTopic,
                    'topicStatus' => $topicStatus,
                ];
            })->all();
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $rows,
                    'total' => count($rows),
                ],
            ],
        ], 200);
    }

    public function xemChiTiet(Request $request, $id)
    {
        // $id thường là ma_so_sinh_vien
        $sv = SinhVien::where('ma_so_sinh_vien', $id)
            ->orWhere('sinh_vien_id', $id)
            ->first();

        if (! $sv) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên!',
            ], 404);
        }

        $dotId = $request->input('periodId') ?? $request->input('period_id');
        if (empty($dotId)) {
            $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : 1;
        }

        $dot = Dot::find($dotId);
        if ($dot && $dot->loai_dot === 'DATN') {
            // Lấy thông tin nhóm của sinh viên trong đợt này
            $group = Nhom::with(['members.lop', 'deTai.giangVien'])
                ->where('dot_id', $dotId)
                ->whereHas('members', function ($q) use ($sv) {
                    $q->where('sinhvien.sinh_vien_id', $sv->sinh_vien_id);
                })
                ->first();

            $groupMembers = [];
            $topicName = '—';
            $topicDesc = '—';
            $supervisor = '—';
            $groupCode = '—';

            if ($group) {
                $groupCode = $group->code ?? "#{$group->nhom_id}";
                $groupMembers = $group->members->map(function ($m) {
                    return [
                        'studentId' => $m->ma_so_sinh_vien,
                        'name' => $m->ho_ten,
                        'className' => $m->lop ? $m->lop->ten_lop : '—',
                    ];
                })->all();

                if ($group->deTai) {
                    $topicName = $group->deTai->ten_de_tai;
                    $topicDesc = $group->deTai->mo_ta ?? '—';
                    if ($group->deTai->giangVien) {
                        $supervisor = $group->deTai->giangVien->ho_ten;
                    }
                }
            }

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => [
                        'studentId' => (string) $sv->ma_so_sinh_vien,
                        'name' => $sv->ho_ten,
                        'className' => $sv->lop ? $sv->lop->ten_lop : '—',
                        'course' => $sv->lop ? $sv->lop->khoa_hoc : '—',
                        'dieuKienLamDoAn' => $sv->dieu_kien_lam_do_an ?? 'DAT',
                        'groupCode' => $groupCode,
                        'groupMembers' => $groupMembers,
                        'topic' => $topicName,
                        'topicDesc' => $topicDesc,
                        'supervisor' => $supervisor,
                    ],
                ],
            ], 200);
        }

        $assign = PhanCongHdtt::with('giangVien')
            ->where('sinh_vien_id', $sv->sinh_vien_id)
            ->where('dot_id', $dotId)
            ->first();

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => [
                    'studentId' => (string) $sv->ma_so_sinh_vien,
                    'name' => $sv->ho_ten,
                    'className' => $sv->lop ? $sv->lop->ten_lop : '—',
                    'course' => $sv->lop ? $sv->lop->khoa_hoc : '—',
                    'topic' => '—',
                    'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
                    'assignedAt' => $assign && $assign->ngay_phan_cong ? $assign->ngay_phan_cong->format('d/m/Y H:i') : null,
                    'published' => $assign ? (bool) $assign->da_cong_bo : false,
                    'status' => $assign ? 'assigned' : 'unassigned',
                ],
            ],
        ], 200);
    }

    /**
     * Số lượng sinh viên tối đa 1 giảng viên được hướng dẫn TTTN trong 1 đợt
     */
    const MAX_STUDENTS_PER_TEACHER = 20;

    public function capNhat(Request $request, $id)
    {
        // $id là ma_so_sinh_vien của sinh viên được phân công
        $sv = SinhVien::where('ma_so_sinh_vien', $id)
            ->orWhere('sinh_vien_id', $id)
            ->first();

        if (! $sv) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên để phân công!',
            ], 404);
        }

        // Tôn trọng đợt admin đang chọn trên UI, chỉ fallback sang đợt mới nhất nếu không truyền lên
        $dotId = $request->input('periodId') ?? $request->input('period_id');
        if (empty($dotId)) {
            $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : 1;
        }

        // Chỉ áp dụng cho đợt Thực tập tốt nghiệp (TTTN)
        $dot = Dot::find($dotId);
        if ($dot && $dot->loai_dot !== 'TTTN') {
            return response()->json([
                'success' => false,
                'message' => 'Thao tác phân công hướng dẫn chỉ áp dụng cho đợt Thực tập tốt nghiệp (TTTN)!',
            ], 400);
        }

        if ($resp = $this->chanNeuDotDaDong($dot)) {
            return $resp;
        }

        $supervisorName = $request->input('supervisor');

        if (empty($supervisorName)) {
            // Xóa mềm phân công (Unassign) - có thể khôi phục lại sau này nếu cần
            PhanCongHdtt::where('sinh_vien_id', $sv->sinh_vien_id)
                ->where('dot_id', $dotId)
                ->delete();
        } else {
            $gv = GiangVien::where('ho_ten', $supervisorName)->first();
            if (! $gv) {
                return response()->json([
                    'success' => false,
                    'message' => "Không tìm thấy giảng viên tên {$supervisorName}!",
                ], 400);
            }

            // Chặn phân công vượt quá số lượng SV tối đa của giảng viên trong đúng đợt này
            // (không tính chính sinh viên đang xử lý, để cho phép lưu lại/đổi GV khác của cùng SV đó)
            $currentCount = PhanCongHdtt::where('giang_vien_id', $gv->giang_vien_id)
                ->where('dot_id', $dotId)
                ->where('sinh_vien_id', '!=', $sv->sinh_vien_id)
                ->count();

            if ($currentCount >= self::MAX_STUDENTS_PER_TEACHER) {
                return response()->json([
                    'success' => false,
                    'message' => "Giảng viên {$supervisorName} đã đủ ".self::MAX_STUDENTS_PER_TEACHER.' sinh viên hướng dẫn trong đợt này, vui lòng chọn giảng viên khác!',
                ], 400);
            }

            // Tìm bản ghi kể cả đã xóa mềm để khôi phục thay vì tạo mới (tránh đụng ràng buộc
            // unique sinh_vien_id+dot_id), đồng thời reset lại trạng thái công bố cho phân công mới/đổi
            $existing = PhanCongHdtt::withTrashed()
                ->where('sinh_vien_id', $sv->sinh_vien_id)
                ->where('dot_id', $dotId)
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update([
                    'giang_vien_id' => $gv->giang_vien_id,
                    'da_cong_bo' => false,
                    'ngay_phan_cong' => now(),
                ]);
            } else {
                PhanCongHdtt::create([
                    'sinh_vien_id' => $sv->sinh_vien_id,
                    'dot_id' => $dotId,
                    'giang_vien_id' => $gv->giang_vien_id,
                    'da_cong_bo' => false,
                    'ngay_phan_cong' => now(),
                ]);
            }
        }

        $assign = PhanCongHdtt::with('giangVien')
            ->where('sinh_vien_id', $sv->sinh_vien_id)
            ->where('dot_id', $dotId)
            ->first();

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => [
                    'studentId' => (string) $sv->ma_so_sinh_vien,
                    'name' => $sv->ho_ten,
                    'className' => $sv->lop ? $sv->lop->ten_lop : '—',
                    'course' => $sv->lop ? $sv->lop->khoa_hoc : '—',
                    'topic' => '—',
                    'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
                    'assignedAt' => $assign && $assign->ngay_phan_cong ? $assign->ngay_phan_cong->format('d/m/Y H:i') : null,
                    'published' => $assign ? (bool) $assign->da_cong_bo : false,
                    'status' => $assign ? 'assigned' : 'unassigned',
                ],
            ],
        ], 200);
    }

    /**
     * Xóa mềm 1 phân công hướng dẫn (dùng cho nút xóa ở "Danh sách đã phân công")
     */
    public function xoa(Request $request, $id)
    {
        $sv = SinhVien::where('ma_so_sinh_vien', $id)
            ->orWhere('sinh_vien_id', $id)
            ->first();

        if (! $sv) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên!',
            ], 404);
        }

        $dotId = $request->input('periodId') ?? $request->input('period_id');
        if (empty($dotId)) {
            $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : 1;
        }

        $dot = Dot::find($dotId);
        if ($resp = $this->chanNeuDotDaDong($dot)) {
            return $resp;
        }

        if ($dot && $dot->loai_dot === 'DATN') {
            // Check constraints:
            // 0. Kiểm tra đề tài đồ án của sinh viên đã được duyệt hay chưa
            $hasApprovedGroup = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.dot_id', $dotId)
                ->where('thanhviennhom.sinh_vien_id', $sv->sinh_vien_id)
                ->where('nhomsvda.trang_thai_duyet', 'DA_DUYET')
                ->exists();

            if ($hasApprovedGroup) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không thể xóa sinh viên này khỏi đợt đồ án vì đăng ký đề tài đồ án của sinh viên đã được duyệt và đang thực hiện!',
                ], 400);
            }

            // 1. Sinh viên đã có điểm đồ án hoặc báo cáo chưa
            $hasGrade = DB::table('diembaocao')
                ->where('sinh_vien_id', $sv->sinh_vien_id)
                ->where('nhom_id', function ($query) use ($sv, $dotId) {
                    $query->select('nhom_id')
                        ->from('thanhviennhom')
                        ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                        ->where('nhomsvda.dot_id', $dotId)
                        ->where('thanhviennhom.sinh_vien_id', $sv->sinh_vien_id)
                        ->limit(1);
                })
                ->exists();

            if (! $hasGrade) {
                $hasGrade = DB::table('diemtongketdatn')
                    ->where('sinh_vien_id', $sv->sinh_vien_id)
                    ->where('nhom_id', function ($query) use ($sv, $dotId) {
                        $query->select('nhom_id')
                            ->from('thanhviennhom')
                            ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                            ->where('nhomsvda.dot_id', $dotId)
                            ->where('thanhviennhom.sinh_vien_id', $sv->sinh_vien_id)
                            ->limit(1);
                    })
                    ->exists();
            }

            if ($hasGrade) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không thể xóa sinh viên này khỏi đợt đồ án vì sinh viên đã có điểm số!',
                ], 400);
            }

            // 2. Sinh viên đã nộp báo cáo tiến độ đồ án chưa
            $hasReports = DB::table('baocaotiendo')
                ->where('sinh_vien_id', $sv->sinh_vien_id)
                ->where('dot_id', $dotId)
                ->exists();
            if ($hasReports) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không thể xóa sinh viên này khỏi đợt đồ án vì sinh viên đã nộp báo cáo tiến độ!',
                ], 400);
            }

            // Xử lý xóa:
            // - Xóa khỏi các nhóm đồ án của đợt này (thanhviennhom)
            $nhomIds = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.dot_id', $dotId)
                ->where('thanhviennhom.sinh_vien_id', $sv->sinh_vien_id)
                ->pluck('thanhviennhom.nhom_id')
                ->all();

            if (! empty($nhomIds)) {
                DB::table('thanhviennhom')
                    ->whereIn('nhom_id', $nhomIds)
                    ->where('sinh_vien_id', $sv->sinh_vien_id)
                    ->delete();

                // Nếu nhóm sau khi xóa không còn thành viên nào, xóa luôn nhóm
                foreach ($nhomIds as $nhomId) {
                    $memberCount = DB::table('thanhviennhom')->where('nhom_id', $nhomId)->count();
                    if ($memberCount === 0) {
                        DB::table('dangkydetai')->where('nhom_id', $nhomId)->delete();
                        DB::table('lichbaove')->where('nhom_id', $nhomId)->delete();
                        DB::table('nhomsvda')->where('nhom_id', $nhomId)->delete();
                    }
                }
            }

            // - Xóa khỏi danh sách sinh viên tự do gắn với đợt (dot_sinhvien)
            DB::table('dot_sinhvien')
                ->where('dot_id', $dotId)
                ->where('sinh_vien_id', $sv->sinh_vien_id)
                ->delete();

            // Ghi log hoạt động
            $admin = $request->user();
            LichSuHoatDong::ghiLog(
                'XOA_SINH_VIEN_DATN',
                'Admin '.($admin ? $admin->ho_ten : 'Hệ thống')." đã xóa sinh viên {$sv->ho_ten} ({$sv->ma_so_sinh_vien}) khỏi đợt ĐATN.",
                $sv->sinh_vien_id,
                $sv->ma_so_sinh_vien,
                null,
                'admin',
                $admin ? $admin->ho_ten : 'Hệ thống',
                ['period_id' => $dotId]
            );

            return response()->json([
                'success' => true,
                'message' => 'Đã xóa sinh viên khỏi đợt đồ án tốt nghiệp thành công!',
            ], 200);
        }

        $assign = PhanCongHdtt::where('sinh_vien_id', $sv->sinh_vien_id)
            ->where('dot_id', $dotId)
            ->first();

        if ($assign) {
            // 1. Kiểm tra xem giảng viên hướng dẫn đã nhập điểm thực tập chưa
            $hasGrade = DB::table('diemthuctap')
                ->where('sinh_vien_id', $sv->sinh_vien_id)
                ->where('dot_id', $dotId)
                ->whereNotNull('diem_so')
                ->exists();
            if ($hasGrade) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không thể xóa phân công hướng dẫn vì giảng viên đã nhập điểm cho sinh viên này!',
                ], 400);
            }

            // 2. Kiểm tra xem giảng viên đã có nhận xét trên báo cáo tiến độ chưa
            $hasComments = DB::table('nhanxetbaocao')
                ->join('baocaotiendo', 'nhanxetbaocao.bao_cao_id', '=', 'baocaotiendo.bao_cao_id')
                ->where('baocaotiendo.sinh_vien_id', $sv->sinh_vien_id)
                ->where('baocaotiendo.dot_id', $dotId)
                ->where('nhanxetbaocao.giang_vien_id', $assign->giang_vien_id)
                ->exists();
            if ($hasComments) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không thể xóa phân công hướng dẫn vì giảng viên đã có nhận xét trên báo cáo tiến độ của sinh viên này!',
                ], 400);
            }
        }

        $deleted = PhanCongHdtt::where('sinh_vien_id', $sv->sinh_vien_id)
            ->where('dot_id', $dotId)
            ->delete();

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Sinh viên này chưa được phân công giảng viên hướng dẫn!',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa phân công hướng dẫn!',
        ], 200);
    }

    /**
     * Công bố phân công hướng dẫn TTTN: công bố mọi phân công trong đợt hiện tại chưa từng công bố,
     * lúc này sinh viên và giảng viên mới chính thức thấy được phân công của mình.
     */
    public function congBo(Request $request)
    {
        $dotId = $request->input('periodId') ?? $request->input('period_id');
        if (empty($dotId)) {
            $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : 1;
        }

        if ($resp = $this->chanNeuDotDaDong(Dot::find($dotId))) {
            return $resp;
        }

        $toPublish = PhanCongHdtt::with(['giangVien', 'sinhVien'])
            ->where('dot_id', $dotId)
            ->where('da_cong_bo', false)
            ->get();
        $publishedCount = $toPublish->count();

        if ($publishedCount > 0) {
            PhanCongHdtt::whereIn('phan_cong_hd_id', $toPublish->pluck('phan_cong_hd_id'))
                ->update(['da_cong_bo' => true]);

            // Ghi log riêng cho từng giảng viên/sinh viên - giống pattern
            // HoiDongController::ghiLogVaThongBaoCongBoHoiDong() - để mỗi người thực sự thấy
            // thông báo của mình trong bell/"Lịch sử hoạt động" thay vì chỉ 1 broadcast chung.
            foreach ($toPublish as $pc) {
                if ($pc->giangVien) {
                    LichSuHoatDong::ghiLog(
                        'PHAN_CONG_HDTT',
                        "Giảng viên {$pc->giangVien->ho_ten} đã được phân công hướng dẫn thực tập tốt nghiệp cho sinh viên ".($pc->sinhVien ? $pc->sinhVien->ho_ten : '—').'.',
                        null,
                        null,
                        null,
                        'giang_vien',
                        $pc->giangVien->ho_ten,
                        [
                            'dot_id' => $dotId,
                            'sinh_vien_id' => $pc->sinh_vien_id,
                            'ma_so_sinh_vien' => $pc->sinhVien->ma_so_sinh_vien ?? null,
                        ]
                    );
                }
                if ($pc->sinhVien) {
                    LichSuHoatDong::ghiLog(
                        'PHAN_CONG_HDTT',
                        "Sinh viên {$pc->sinhVien->ho_ten} đã được phân công giảng viên hướng dẫn thực tập tốt nghiệp: ".($pc->giangVien ? $pc->giangVien->ho_ten : '—').'.',
                        $pc->sinh_vien_id,
                        $pc->sinhVien->ma_so_sinh_vien,
                        null,
                        'sinh_vien',
                        $pc->sinhVien->ho_ten,
                        [
                            'dot_id' => $dotId,
                            'giang_vien_id' => $pc->giang_vien_id,
                        ]
                    );
                }
            }

            RealtimeService::broadcast('notification', [
                'title' => 'Phân công hướng dẫn TTTN đã được công bố',
                'message' => "Đã công bố phân công hướng dẫn cho {$publishedCount} sinh viên.",
                'type' => 'assignment_published',
                'dotId' => (string) $dotId,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $publishedCount > 0
                ? "Đã công bố phân công hướng dẫn cho {$publishedCount} sinh viên!"
                : 'Không có phân công mới nào cần công bố.',
            'results' => [
                'publishedCount' => $publishedCount,
            ],
        ], 200);
    }

    public function getTeachers(Request $request)
    {
        $dotId = $request->input('periodId') ?? $request->input('period_id');
        if (empty($dotId)) {
            $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : 1;
        }

        $teachers = GiangVien::where('dang_hoat_dong', 1)->get();

        // Đếm số SV đang hướng dẫn TRONG ĐÚNG ĐỢT này (không tính đợt khác, không tính bản ghi đã xóa mềm)
        $assignedCounts = PhanCongHdtt::where('dot_id', $dotId)
            ->select('giang_vien_id', DB::raw('count(*) as total'))
            ->groupBy('giang_vien_id')
            ->get()
            ->keyBy('giang_vien_id');

        $rows = $teachers->map(function ($gv) use ($assignedCounts) {
            $count = $assignedCounts->get($gv->giang_vien_id)->total ?? 0;
            // Cho phép tối đa 5 sinh viên/giảng viên hướng dẫn trong 1 đợt
            $status = $count >= self::MAX_STUDENTS_PER_TEACHER ? 'full' : 'available';

            return [
                'id' => (string) $gv->giang_vien_id,
                'name' => $gv->ho_ten,
                'degree' => $gv->hoc_vi,
                'major' => $gv->chuyen_mon ?? 'Phần mềm',
                'status' => $status,
                'assignedCount' => $count,
                'maxSlots' => self::MAX_STUDENTS_PER_TEACHER,
            ];
        })->all();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $rows,
            ],
        ], 200);
    }

    public function capNhatDieuKienLamDoAn(Request $request, $studentId)
    {
        $sinhVien = SinhVien::where('ma_so_sinh_vien', $studentId)
            ->orWhere('sinh_vien_id', $studentId)
            ->first();

        if (! $sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên!',
            ], 404);
        }

        $request->validate([
            'dieuKienLamDoAn' => 'required|string|in:DAT,CHUA_DAT',
        ]);

        $dieuKien = $request->input('dieuKienLamDoAn');

        // Tìm đợt hiện tại
        $dotId = $request->input('periodId') ?? $request->input('period_id');
        if (empty($dotId)) {
            $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : null;
        }

        if (empty($dotId)) {
            return response()->json([
                'success' => false,
                'message' => 'Không xác định được đợt học hiện tại!',
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Cập nhật hoặc chèn mới trạng thái dieu_kien_lam_do_an trong bảng dot_sinhvien
            DB::table('dot_sinhvien')->updateOrInsert(
                ['dot_id' => $dotId, 'sinh_vien_id' => $sinhVien->sinh_vien_id],
                ['dieu_kien_lam_do_an' => $dieuKien]
            );

            // Ghi log hoạt động cập nhật điều kiện
            $admin = $request->user();
            \App\Models\LichSuHoatDong::ghiLog(
                'CAP_NHAT_NHOM',
                'Admin '.($admin ? $admin->ho_ten : 'Hệ thống')." đã cập nhật điều kiện làm đồ án của sinh viên {$sinhVien->ho_ten} thành ".($dieuKien === 'DAT' ? 'Đạt' : 'Không đạt')." trong đợt học #{$dotId}.",
                $sinhVien->sinh_vien_id,
                $sinhVien->ma_so_sinh_vien,
                null,
                'admin',
                $admin ? $admin->ho_ten : 'Hệ thống'
            );

            // Nếu thay đổi thành CHUA_DAT (Không đạt), tiến hành kích ra khỏi nhóm vật lý
            if ($dieuKien === 'CHUA_DAT') {
                // Tìm nhóm mà sinh viên này đang tham gia trong đợt hiện tại
                $nhom = Nhom::whereHas('members', function ($q) use ($sinhVien) {
                    $q->where('sinhvien.sinh_vien_id', $sinhVien->sinh_vien_id);
                })->where('dot_id', $dotId)->first();

                if ($nhom) {
                    // Xem sinh viên này có phải trưởng nhóm không
                    $pivot = DB::table('thanhviennhom')
                        ->where('nhom_id', $nhom->nhom_id)
                        ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
                        ->first();

                    // Xóa sinh viên khỏi bảng thanhviennhom
                    DB::table('thanhviennhom')
                        ->where('nhom_id', $nhom->nhom_id)
                        ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
                        ->delete();

                    // Tìm thành viên còn lại của nhóm
                    $remainingMember = DB::table('thanhviennhom')
                        ->where('nhom_id', $nhom->nhom_id)
                        ->first();

                    if ($remainingMember) {
                        // Nếu trưởng nhóm bị kích, bổ nhiệm thành viên còn lại làm trưởng nhóm
                        if ($pivot && $pivot->la_truong_nhom == 1) {
                            DB::table('thanhviennhom')
                                ->where('thanh_vien_id', $remainingMember->thanh_vien_id)
                                ->update(['la_truong_nhom' => 1]);
                        }
                    } else {
                        // Nếu nhóm không còn thành viên nào, xóa nhóm
                        DB::table('dangkydetai')->where('nhom_id', $nhom->nhom_id)->delete();
                        DB::table('loimoinhom')->where('nhom_id', $nhom->nhom_id)->delete();
                        $nhom->delete();
                    }

                    \App\Models\LichSuHoatDong::ghiLog(
                        'ROI_NHOM',
                        "Hệ thống đã kích sinh viên {$sinhVien->ho_ten} ra khỏi nhóm do bị đánh giá không đủ điều kiện làm đồ án.",
                        $sinhVien->sinh_vien_id,
                        $sinhVien->ma_so_sinh_vien,
                        $nhom->nhom_id,
                        'admin',
                        'Hệ thống'
                    );

                    RealtimeService::broadcast('slot_updated', [
                        'type' => 'student_left_group',
                        'nhomId' => $nhom->nhom_id,
                    ]);
                }
            }

            DB::commit();

            RealtimeService::broadcast('slot_updated', [
                'type' => 'student_eligibility_updated',
                'studentId' => $sinhVien->ma_so_sinh_vien,
                'dieuKienLamDoAn' => $dieuKien,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật điều kiện làm đồ án thành công!',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Lỗi cập nhật điều kiện: '.$e->getMessage(),
            ], 500);
        }
    }
}
