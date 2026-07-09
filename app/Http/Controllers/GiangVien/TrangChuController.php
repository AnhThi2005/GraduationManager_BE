<?php

namespace App\Http\Controllers\GiangVien;

use App\Http\Controllers\Controller;
use App\Models\DeTai;
use App\Models\Dot;
use App\Models\HoiDong;
use App\Models\Nhom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrangChuController extends Controller
{
    /**
     * GET /private/v1/teacher/dashboard
     */
    public function getDashboardData(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $dotId = $request->input('periodId');
        if (empty($dotId)) {
            $latestPeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        // 1. Total topics
        $topicsCount = DeTai::where('giang_vien_id', $teacherId)->count();

        // 2. TTTN students guided (chỉ tính phân công đã được admin công bố, chưa bị xóa mềm)
        $tttnCount = DB::table('phanconghdtt')
            ->where('giang_vien_id', $teacherId)
            ->where('dot_id', $dotId)
            ->where('da_cong_bo', true)
            ->whereNull('deleted_at')
            ->count();

        // 3. Reviewed groups (ĐATN)
        $myCouncilIds = DB::table('thanhvienhoidong')
            ->where('giang_vien_id', $teacherId)
            ->pluck('hoi_dong_id');

        $reviewedGroupsCount = Nhom::whereIn('hoi_dong_id', $myCouncilIds)
            ->where('dot_id', $dotId)
            ->count();

        // 4. Councils count
        $councilsCount = HoiDong::where('dot_id', $dotId)
            ->whereHas('giangViens', function ($q) use ($teacherId) {
                $q->where('giangvien.giang_vien_id', $teacherId);
            })
            ->count();

        // 5. Topics list under charge
        $topics = DeTai::where('giang_vien_id', $teacherId)
            ->where('dot_id', $dotId)
            ->get()
            ->map(function ($t) {
                $occupied = DB::table('thanhviennhom')
                    ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                    ->where('nhomsvda.de_tai_id', $t->de_tai_id)
                    ->count();

                $statusVal = 'Chờ duyệt';
                if ($t->trang_thai === 'DA_DUYET') {
                    $statusVal = 'Đã duyệt';
                } elseif ($t->trang_thai === 'TU_CHOI') {
                    $statusVal = 'Từ chối';
                }

                return [
                    'code' => 'DA'.str_pad($t->de_tai_id, 3, '0', STR_PAD_LEFT),
                    'name' => $t->ten_de_tai,
                    'slot' => $occupied.'/'.($t->so_luong_sv_toi_da ?? 4),
                    'status' => $statusVal,
                    'students' => $occupied,
                    'note' => $t->ly_do_tu_choi ?? ($statusVal === 'Chờ duyệt' ? 'Chờ phê duyệt' : ''),
                ];
            })
            ->all();

        return response()->json([
            'success' => true,
            'stats' => [
                'topics' => $topicsCount,
                'tttn' => $tttnCount,
                'datn' => $reviewedGroupsCount,
                'councils' => $councilsCount,
            ],
            'topics' => $topics,
        ]);
    }

    /**
     * GET /private/v1/teacher/profile
     */
    public function getProfile(Request $request)
    {
        $teacher = $request->user();

        return response()->json([
            'success' => true,
            'teacher' => [
                'id' => $teacher->giang_vien_id,
                'name' => $teacher->ho_ten,
                'email' => $teacher->email,
                'phone' => $teacher->so_dien_thoai ?? '—',
                'degree' => $teacher->hoc_vi ?? 'ThS',
                'specialty' => $teacher->chuyen_mon ?? 'Khoa Công nghệ thông tin',
            ],
        ]);
    }
}
