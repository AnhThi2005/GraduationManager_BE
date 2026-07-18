<?php

namespace App\Http\Controllers;

use App\Models\LichSuHoatDong;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    /**
     * Get history logs for the authenticated student
     */
    public function getStudentHistory(Request $request)
    {
        $sinhVien = $request->user();
        if (! $sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        // Find all groups this student was or is a member of (including disbanded ones if they were logged)
        // Since we want both active group and logs tagged with the student's ID directly:
        $groupIds = DB::table('thanhviennhom')
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->pluck('nhom_id')
            ->all();

        $query = LichSuHoatDong::query()
            ->where(function ($q) use ($sinhVien, $groupIds) {
                $q->where('sinh_vien_id', $sinhVien->sinh_vien_id)
                  ->orWhere('ma_so_sinh_vien', $sinhVien->ma_so_sinh_vien);
                if (!empty($groupIds)) {
                    $q->orWhereIn('nhom_id', $groupIds);
                }
            });

        if ($request->filled('dot_id')) {
            $query->where('dot_id', $request->query('dot_id'));
        } else {
            $query->whereRaw('1 = 0');
        }

        // Chuông thông báo chỉ cần vài dòng gần nhất nên truyền "limit" để không phải quét/tải
        // toàn bộ lịch sử mỗi lần — trang "Lịch sử hoạt động" đầy đủ thì không truyền limit.
        if ($request->filled('limit')) {
            $query->limit((int) $request->input('limit'));
        }

        $logs = $query->orderBy('created_at', 'desc')->get();
        $this->personalizeLogs($logs, 'sinh_vien', $sinhVien->ho_ten, $sinhVien->sinh_vien_id, $sinhVien->ma_so_sinh_vien);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $logs
            ]
        ]);
    }

    /**
     * Get history logs for Admin with optional filters
     */
    public function getAdminHistory(Request $request)
    {
        $query = LichSuHoatDong::query();

        if ($request->filled('dot_id')) {
            $query->where('dot_id', $request->query('dot_id'));
        } else {
            $query->whereRaw('1 = 0');
        }

        if ($request->filled('sinh_vien_id')) {
            $query->where('sinh_vien_id', $request->query('sinh_vien_id'));
        }

        if ($request->filled('ma_so_sinh_vien')) {
            $query->where('ma_so_sinh_vien', $request->query('ma_so_sinh_vien'));
        }

        if ($request->filled('keyword')) {
            $keyword = trim($request->query('keyword'));
            $query->where(function ($q) use ($keyword) {
                $q->where('ma_so_sinh_vien', 'like', '%' . $keyword . '%')
                  ->orWhere('user_name', 'like', '%' . $keyword . '%');
            });
        }

        if ($request->filled('nhom_id')) {
            $query->where('nhom_id', $request->query('nhom_id'));
        }

        if ($request->filled('action_type')) {
            $query->where('action_type', $request->query('action_type'));
        }

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $logs
            ]
        ]);
    }

    /**
     * Get history logs for the authenticated teacher
     */
    public function getTeacherHistory(Request $request)
    {
        $teacher = $request->user();
        if (! $teacher) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        // Get all topic IDs proposed by this lecturer
        $topicIds = DB::table('detai')
            ->where('giang_vien_id', $teacher->giang_vien_id)
            ->pluck('de_tai_id')
            ->all();

        // Get all group IDs registered for these topics
        $groupIdsFromTopics = [];
        if (!empty($topicIds)) {
            $groupIdsFromTopics = DB::table('nhomsvda')
                ->whereIn('de_tai_id', $topicIds)
                ->pluck('nhom_id')
                ->all();
        }

        $query = LichSuHoatDong::query()
            ->where(function ($q) use ($teacher, $groupIdsFromTopics) {
                // Actor is the teacher
                $q->where(function ($sub) use ($teacher) {
                    $sub->where('role', 'giang_vien')
                        ->where('user_name', $teacher->ho_ten);
                });
                
                // Or actions related to the teacher's groups
                if (!empty($groupIdsFromTopics)) {
                    $q->orWhereIn('nhom_id', $groupIdsFromTopics);
                }
            });

        if ($request->filled('dot_id')) {
            $query->where('dot_id', $request->query('dot_id'));
        } else {
            $query->whereRaw('1 = 0');
        }

        // Chuông thông báo chỉ cần vài dòng gần nhất nên truyền "limit" để không phải quét/tải
        // toàn bộ lịch sử mỗi lần — trang "Lịch sử" đầy đủ thì không truyền limit.
        if ($request->filled('limit')) {
            $query->limit((int) $request->input('limit'));
        }

        $logs = $query->orderBy('created_at', 'desc')->get();
        $this->personalizeLogs($logs, 'giang_vien', $teacher->ho_ten);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $logs
            ]
        ]);
    }

    /**
     * Viết lại description của mỗi log cho đúng với người xem hiện tại:
     * - Nếu log kể về chính người xem (họ là chủ thể/đối tượng của hành động) -> thay tên bằng "Bạn"/"bạn".
     * - Nếu không, nhưng log gắn với nhóm của chính sinh viên đang xem -> thêm "bạn" vào sau từ "nhóm"
     *   (vì HistoryController::getStudentHistory chỉ trả về log thuộc nhóm của chính sinh viên đó,
     *   nên mọi "nhóm" còn lại trong log đã lọc chắc chắn là nhóm của người xem).
     * Không áp dụng cho getAdminHistory: Admin cần thấy tên thật để tra cứu/đối chiếu.
     */
    private function personalizeLogs($logs, string $viewerRole, string $viewerName, ?int $viewerSinhVienId = null, ?string $viewerMaSoSinhVien = null): void
    {
        $viewerName = trim((string) $viewerName);
        if ($viewerName === '') {
            return;
        }

        foreach ($logs as $log) {
            $log->description = $this->personalizeDescription(
                $log->description,
                $log,
                $viewerRole,
                $viewerName,
                $viewerSinhVienId,
                $viewerMaSoSinhVien
            );
        }
    }

    private function personalizeDescription(string $description, $log, string $viewerRole, string $viewerName, ?int $viewerSinhVienId, ?string $viewerMaSoSinhVien): string
    {
        $isAboutViewer = false;

        if ($viewerRole === 'sinh_vien') {
            $isAboutViewer = ($viewerSinhVienId && (int) $log->sinh_vien_id === (int) $viewerSinhVienId)
                || ($viewerMaSoSinhVien && $log->ma_so_sinh_vien === $viewerMaSoSinhVien);
        } elseif ($viewerRole === 'giang_vien') {
            $isAboutViewer = $log->role === 'giang_vien'
                && $log->user_name !== null
                && mb_strtolower(trim($log->user_name)) === mb_strtolower($viewerName);
        }

        if ($isAboutViewer) {
            $namePattern = preg_quote($viewerName, '/');
            $nounPattern = '(?:Sinh viên|Giảng viên|Trưởng nhóm|sinh viên|giảng viên|trưởng nhóm)';

            // Đầu câu: "Sinh viên/Giảng viên/Trưởng nhóm {tên}" -> "Bạn"
            $description = preg_replace(
                '/^(Sinh viên|Giảng viên|Trưởng nhóm)\s+'.$namePattern.'(?=\s|$)/u',
                'Bạn',
                $description,
                1
            );
            // Giữa câu: cùng cụm danh từ + tên (vd "...kích sinh viên {tên} ra khỏi...") -> "bạn",
            // tránh để sót thành "sinh viên bạn" (danh từ đứng trước đại từ, sai ngữ pháp).
            $description = preg_replace(
                '/'.$nounPattern.'\s+'.$namePattern.'(?=\s|$)/u',
                'bạn',
                $description
            );
            // Tên trần trụi còn sót lại (không kèm danh từ đứng trước) -> "bạn"
            return str_replace($viewerName, 'bạn', $description);
        }

        // Không phải hành động/đối tượng của chính người xem, nhưng vẫn thuộc nhóm của họ (chỉ áp
        // dụng cho sinh viên — giảng viên có thể hướng dẫn nhiều nhóm nên "nhóm bạn" không hợp ngữ cảnh).
        if ($viewerRole === 'sinh_vien' && $log->nhom_id) {
            $description = preg_replace('/(?<!Trưởng )(?<!trưởng )\bnhóm\b(?!\s+bạn)/u', 'nhóm bạn', $description);
            $description = preg_replace('/(?<!Trưởng )(?<!trưởng )\bNhóm\b(?!\s+bạn)/u', 'Nhóm bạn', $description);
        }

        return $description;
    }
}
