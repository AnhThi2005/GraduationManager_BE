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
                if (! empty($groupIds)) {
                    $q->orWhereIn('nhom_id', $groupIds);
                }
            });

        $dotId = $request->query('dot_id') ?? $request->query('periodId') ?? $request->query('period_id');
        if (empty($dotId)) {
            $activePeriod = \App\Models\Dot::where('trang_thai', 'DANG_MO')->orderBy('dot_id', 'desc')->first()
                ?? \App\Models\Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : null;
        }

        if ($dotId) {
            $query->where('dot_id', $dotId);
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
                'objects' => $logs,
            ],
        ]);
    }

    /**
     * Get history logs for Admin with optional filters
     */
    public function getAdminHistory(Request $request)
    {
        $query = LichSuHoatDong::query();

        $dotId = $request->query('dot_id') ?? $request->query('periodId') ?? $request->query('period_id');
        if (empty($dotId)) {
            $activePeriod = \App\Models\Dot::where('trang_thai', 'DANG_MO')->orderBy('dot_id', 'desc')->first()
                ?? \App\Models\Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : null;
        }

        if ($dotId) {
            $query->where('dot_id', $dotId);
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
                $q->where('ma_so_sinh_vien', 'like', '%'.$keyword.'%')
                    ->orWhere('user_name', 'like', '%'.$keyword.'%');
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
        $this->personalizeLogs($logs, 'admin', '');

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $logs,
            ],
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
        if (! empty($topicIds)) {
            $groupIdsFromTopics = DB::table('nhomsvda')
                ->whereIn('de_tai_id', $topicIds)
                ->pluck('nhom_id')
                ->all();
        }

        // Get guided TTTN students
        $guidedStudentIds = [];
        if ($dotId) {
            $guidedStudentIds = DB::table('phanconghdtt')
                ->where('giang_vien_id', $teacher->giang_vien_id)
                ->where('dot_id', $dotId)
                ->whereNull('deleted_at')
                ->pluck('sinh_vien_id')
                ->all();
        }

        $query = LichSuHoatDong::query()
            ->where(function ($q) use ($teacher, $groupIdsFromTopics, $guidedStudentIds) {
                // Actor is the teacher
                $q->where(function ($sub) use ($teacher) {
                    $sub->where('role', 'giang_vien')
                        ->where('user_name', $teacher->ho_ten);
                });

                // Or actions related to the teacher's groups
                if (! empty($groupIdsFromTopics)) {
                    $q->orWhereIn('nhom_id', $groupIdsFromTopics);
                }

                // Or actions related to the teacher's TTTN students
                if (! empty($guidedStudentIds)) {
                    $q->orWhereIn('sinh_vien_id', $guidedStudentIds);
                }
            });

        $dotId = $request->query('dot_id') ?? $request->query('periodId') ?? $request->query('period_id');
        if (empty($dotId)) {
            $activePeriod = \App\Models\Dot::where('trang_thai', 'DANG_MO')->orderBy('dot_id', 'desc')->first()
                ?? \App\Models\Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : null;
        }

        if ($dotId) {
            $query->where('dot_id', $dotId);
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
                'objects' => $logs,
            ],
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
    private $groupDetailsCache = [];

    private function getGroupDisplayName($nhomId)
    {
        if (empty($nhomId)) {
            return 'nhóm';
        }
        if (isset($this->groupDetailsCache[$nhomId])) {
            return $this->groupDetailsCache[$nhomId];
        }

        $nhom = \App\Models\Nhom::with(['members', 'deTai'])->find($nhomId);
        if (! $nhom) {
            return 'nhóm';
        }

        if ($nhom->deTai) {
            $name = 'nhóm đề tài "' . $nhom->deTai->ten_de_tai . '"';
        } else {
            $leader = $nhom->members->first(function ($m) {
                return $m->pivot->la_truong_nhom;
            });
            if ($leader) {
                $name = 'nhóm của ' . $leader->ho_ten;
            } else {
                $firstMember = $nhom->members->first();
                if ($firstMember) {
                    $name = 'nhóm của ' . $firstMember->ho_ten;
                } else {
                    $name = 'nhóm';
                }
            }
        }

        $this->groupDetailsCache[$nhomId] = $name;
        return $name;
    }

    private function personalizeLogs($logs, string $viewerRole, string $viewerName, ?int $viewerSinhVienId = null, ?string $viewerMaSoSinhVien = null): void
    {
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
        // 1. Group info replacement (applies to all roles, including admin)
        $description = preg_replace_callback('/nhóm\s+#(\d+)/iu', function ($matches) {
            $nhomId = $matches[1];
            return $this->getGroupDisplayName($nhomId);
        }, $description);

        if ($log->nhom_id) {
            $displayName = $this->getGroupDisplayName($log->nhom_id);
            if (mb_strpos($description, $displayName) === false) {
                // Avoid replacing parts of words like "trưởng nhóm" or "nhóm bạn"
                $description = preg_replace('/(?<!Trưởng )(?<!trưởng )\bnhóm\b(?!\s+bạn)/iu', $displayName, $description);
            }
        }

        // 2. Personalize actor name to "Bạn" (only if viewerName is provided)
        $viewerName = trim((string) $viewerName);
        if ($viewerName !== '') {
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

                $description = preg_replace(
                    '/^(Sinh viên|Giảng viên|Trưởng nhóm)\s+'.$namePattern.'(?=\s|$)/u',
                    'Bạn',
                    $description,
                    1
                );
                $description = preg_replace(
                    '/'.$nounPattern.'\s+'.$namePattern.'(?=\s|$)/u',
                    'bạn',
                    $description
                );

                $description = str_replace($viewerName, 'bạn', $description);
            }

            if ($viewerRole === 'sinh_vien' && $log->nhom_id) {
                $description = preg_replace('/(?<!Trưởng )(?<!trưởng )\bnhóm\b(?!\s+bạn)/u', 'nhóm bạn', $description);
                $description = preg_replace('/(?<!Trưởng )(?<!trưởng )\bNhóm\b(?!\s+bạn)/u', 'Nhóm bạn', $description);
            }
        }

        return $description;
    }
}
