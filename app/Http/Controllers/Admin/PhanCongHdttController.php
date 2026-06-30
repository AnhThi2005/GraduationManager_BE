<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SinhVien;
use App\Models\GiangVien;
use App\Models\PhanCongHdtt;
use App\Models\Dot;
use App\Models\Nhom;
use Illuminate\Support\Facades\DB;

class PhanCongHdttController extends Controller
{
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
            $query = SinhVien::query()->with('lop');
            if ($lopIdsInPeriod->isNotEmpty()) {
                $query->whereIn('lop_id', $lopIdsInPeriod);
            } else {
                $query->whereNull('sinh_vien_id');
            }
            $students = $query->get();

            // Lấy phân công theo đợt
            $assignments = PhanCongHdtt::with('giangVien')
                ->where('dot_id', $dotId)
                ->get()
                ->keyBy('sinh_vien_id');

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
                    $topic = 'Thực tập tại ' . $internshipRegs->get($sv->sinh_vien_id)->ten_cong_ty;
                }

                return [
                    'studentId' => (string) $sv->ma_so_sinh_vien,
                    'name' => $sv->ho_ten,
                    'className' => $sv->lop ? $sv->lop->ten_lop : '—',
                    'topic' => $topic,
                    'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
                    'assignedAt' => $assign ? '16/06/2026' : null,
                    'status' => $assign ? 'assigned' : 'unassigned'
                ];
            })->all();

        } else {
            // --- LOGIC CHO ĐỢT ĐỒ ÁN TỐT NGHIỆP (ĐATN) ---
            // Lấy danh sách sinh viên có nhóm trong đợt này
            $groupStudentIds = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.dot_id', $dotId)
                ->pluck('thanhviennhom.sinh_vien_id');

            // Gộp các nguồn ID sinh viên đồ án tốt nghiệp
            $studentIds = collect()
                ->concat($groupStudentIds)
                ->unique();

            // Danh sách sinh viên có hoạt động nhóm ở các đợt khác
            $studentIdsInOtherPeriods = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.dot_id', '!=', $dotId)
                ->pluck('thanhviennhom.sinh_vien_id')
                ->unique();

            $query = SinhVien::query()->with('lop');

            if ($lopIdsInPeriod->isNotEmpty() || $studentIds->isNotEmpty()) {
                $query->where(function($q) use ($lopIdsInPeriod, $studentIds, $studentIdsInOtherPeriods) {
                    $q->whereIn('sinh_vien_id', $studentIds);
                    
                    if ($lopIdsInPeriod->isNotEmpty()) {
                        $q->orWhere(function($subQ) use ($lopIdsInPeriod, $studentIdsInOtherPeriods) {
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

            $rows = $students->map(function ($sv) use ($studentGroupMap, $groupRegistrations) {
                $groupId = null;
                $groupCode = null;
                $groupStatus = 'no_group';
                $hasIneligibleMember = false;
                $hasTopic = false;
                $topicStatus = 'no_registration';
                $topic = '—';
                $supervisor = null;

                if (isset($studentGroupMap[$sv->sinh_vien_id])) {
                    $group = $studentGroupMap[$sv->sinh_vien_id];
                    $groupId = (string) $group->nhom_id;
                    $groupCode = 'NH' . str_pad($group->nhom_id, 2, '0', STR_PAD_LEFT);

                    // Check eligibility of members
                    foreach ($group->members as $m) {
                        $eligible = ($m->pivot->dieu_kien_lam_do_an ?? 'DAT') === 'DAT';
                        if (!$eligible) {
                            $hasIneligibleMember = true;
                        }
                    }

                    // Check topic status
                    if ($group->de_tai_id) {
                        $hasTopic = true;
                        $topicStatus = 'approved';
                        $topic = $group->deTai ? $group->deTai->ten_de_tai : 'Nhóm đề tài #' . $group->nhom_id;
                        if ($group->deTai && $group->deTai->giangVien) {
                            $supervisor = $group->deTai->giangVien->ho_ten;
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
                    } elseif (!$hasTopic) {
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

                return [
                    'studentId' => (string) $sv->ma_so_sinh_vien,
                    'name' => $sv->ho_ten,
                    'className' => $sv->lop ? $sv->lop->ten_lop : '—',
                    'topic' => $topic,
                    'supervisor' => $supervisor,
                    'assignedAt' => $supervisor ? '16/06/2026' : null,
                    'status' => $supervisor ? 'assigned' : 'unassigned',
                    
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
                    'total' => count($rows)
                ]
            ]
        ], 200);
    }

    public function xemChiTiet(Request $request, $id)
    {
        // $id thường là ma_so_sinh_vien
        $sv = SinhVien::where('ma_so_sinh_vien', $id)
            ->orWhere('sinh_vien_id', $id)
            ->first();

        if (!$sv) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên!'
            ], 404);
        }

        $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
        $dotId = $activePeriod ? $activePeriod->dot_id : 1;

        // Chỉ áp dụng cho đợt Thực tập tốt nghiệp (TTTN)
        $dot = Dot::find($dotId);
        if ($dot && $dot->loai_dot !== 'TTTN') {
            return response()->json([
                'success' => false,
                'message' => 'Thao tác phân công hướng dẫn chỉ áp dụng cho đợt Thực tập tốt nghiệp (TTTN)!'
            ], 400);
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
                    'topic' => '—',
                    'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
                    'assignedAt' => $assign ? '16/06/2026' : null,
                    'status' => $assign ? 'assigned' : 'unassigned'
                ]
            ]
        ], 200);
    }

    public function capNhat(Request $request, $id)
    {
        // $id là ma_so_sinh_vien của sinh viên được phân công
        $sv = SinhVien::where('ma_so_sinh_vien', $id)
            ->orWhere('sinh_vien_id', $id)
            ->first();

        if (!$sv) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên để phân công!'
            ], 404);
        }

        $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
        $dotId = $activePeriod ? $activePeriod->dot_id : 1;

        // Chỉ áp dụng cho đợt Thực tập tốt nghiệp (TTTN)
        $dot = Dot::find($dotId);
        if ($dot && $dot->loai_dot !== 'TTTN') {
            return response()->json([
                'success' => false,
                'message' => 'Thao tác phân công hướng dẫn chỉ áp dụng cho đợt Thực tập tốt nghiệp (TTTN)!'
            ], 400);
        }

        $supervisorName = $request->input('supervisor');

        if (empty($supervisorName)) {
            // Unassign
            PhanCongHdtt::where('sinh_vien_id', $sv->sinh_vien_id)
                ->where('dot_id', $dotId)
                ->delete();
        } else {
            // Assign
            $gv = GiangVien::where('ho_ten', $supervisorName)->first();
            if (!$gv) {
                return response()->json([
                    'success' => false,
                    'message' => "Không tìm thấy giảng viên tên {$supervisorName}!"
                ], 400);
            }

            PhanCongHdtt::updateOrCreate(
                [
                    'sinh_vien_id' => $sv->sinh_vien_id,
                    'dot_id' => $dotId
                ],
                [
                    'giang_vien_id' => $gv->giang_vien_id,
                    'da_cong_bo' => 1
                ]
            );
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
                    'topic' => '—',
                    'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
                    'assignedAt' => $assign ? '16/06/2026' : null,
                    'status' => $assign ? 'assigned' : 'unassigned'
                ]
            ]
        ], 200);
    }

    public function getTeachers(Request $request)
    {
        $teachers = GiangVien::where('dang_hoat_dong', 1)->get();
        
        $assignedCounts = DB::table('phanconghdtt')
            ->select('giang_vien_id', DB::raw('count(*) as total'))
            ->groupBy('giang_vien_id')
            ->get()
            ->keyBy('giang_vien_id');

        $rows = $teachers->map(function ($gv) use ($assignedCounts) {
            $count = $assignedCounts->get($gv->giang_vien_id)->total ?? 0;
            // Cho phép tối đa 5 sinh viên/giảng viên hướng dẫn
            $status = $count >= 5 ? 'full' : 'available';

            return [
                'id' => (string) $gv->giang_vien_id,
                'name' => $gv->ho_ten,
                'degree' => $gv->hoc_vi ?? 'ThS.',
                'major' => $gv->chuyen_mon ?? 'Phần mềm',
                'status' => $status
            ];
        })->all();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $rows
            ]
        ], 200);
    }
}
