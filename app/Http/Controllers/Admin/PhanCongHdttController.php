<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SinhVien;
use App\Models\GiangVien;
use App\Models\PhanCongHdtt;
use App\Models\Dot;
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

        // Lấy danh sách lớp học được gán cho đợt này
        $lopIdsInPeriod = DB::table('dot_lop')->where('dot_id', $dotId)->pluck('lop_id');

        // Lấy danh sách sinh viên có phân công trong đợt này
        $assignedStudentIds = PhanCongHdtt::where('dot_id', $dotId)->pluck('sinh_vien_id');

        // Lấy danh sách sinh viên có nhóm trong đợt này
        $groupStudentIds = DB::table('thanhviennhom')
            ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
            ->where('nhomsvda.dot_id', $dotId)
            ->pluck('thanhviennhom.sinh_vien_id');

        // Lấy danh sách sinh viên đăng ký thực tập trong đợt này
        $internshipStudentIds = DB::table('dangkythuctap')
            ->where('dot_id', $dotId)
            ->pluck('sinh_vien_id');

        // Gộp tất cả các nguồn ID sinh viên lại
        $studentIds = collect()
            ->concat($assignedStudentIds)
            ->concat($groupStudentIds)
            ->concat($internshipStudentIds)
            ->unique();

        $query = SinhVien::query()->with('lop');

        if ($lopIdsInPeriod->isNotEmpty() || $studentIds->isNotEmpty()) {
            $query->where(function($q) use ($lopIdsInPeriod, $studentIds) {
                $q->whereIn('lop_id', $lopIdsInPeriod)
                  ->orWhereIn('sinh_vien_id', $studentIds);
            });
        } else {
            // Nếu không có bất cứ liên kết nào trong đợt này, trả về rỗng để đúng nghiệp vụ lọc đợt
            $query->whereNull('sinh_vien_id');
        }

        $students = $query->get();

        // Lấy phân công theo đợt
        $assignments = PhanCongHdtt::with('giangVien')
            ->where('dot_id', $dotId)
            ->get()
            ->keyBy('sinh_vien_id');

        // Lấy nhóm sinh viên theo đợt để điền thông tin đề tài
        $groupMembers = DB::table('thanhviennhom')
            ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
            ->leftJoin('detai', 'nhomsvda.de_tai_id', '=', 'detai.de_tai_id')
            ->select('thanhviennhom.sinh_vien_id', 'detai.ten_de_tai', 'nhomsvda.nhom_id')
            ->where('nhomsvda.dot_id', $dotId)
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

        $rows = $students->map(function ($sv) use ($assignments, $groupMembers, $internshipRegs) {
            $assign = $assignments->get($sv->sinh_vien_id);
            
            $topic = '—';
            if ($groupMembers->has($sv->sinh_vien_id)) {
                $topic = $groupMembers->get($sv->sinh_vien_id)->ten_de_tai ?? 'Nhóm đề tài #' . $groupMembers->get($sv->sinh_vien_id)->nhom_id;
            } elseif ($internshipRegs->has($sv->sinh_vien_id)) {
                $topic = 'Thực tập tại ' . $internshipRegs->get($sv->sinh_vien_id)->ten_cong_ty;
            }

            return [
                'studentId' => (string) $sv->ma_so_sinh_vien,
                'name' => $sv->ho_ten,
                'className' => $sv->lop ? $sv->lop->ten_lop : '—',
                'topic' => $topic,
                'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
                'assignedAt' => $assign ? '16/06/2026' : null, // Thường không lưu ngày phân công trong DB nên trả về ngày mặc định
                'status' => $assign ? 'assigned' : 'unassigned'
            ];
        })->all();

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
        $teachers = GiangVien::all();
        
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
