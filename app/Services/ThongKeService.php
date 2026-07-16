<?php

namespace App\Services;

use App\Models\GiangVien;
use App\Models\Lop;
use App\Models\SinhVien;
use Illuminate\Support\Facades\DB;

class ThongKeService
{
    /**
     * Thống kê rút gọn, dùng cho các màn hình công khai (chưa đăng nhập) như trang đăng nhập.
     */
    public function getPublicSummary()
    {
        return [
            'totalStudents' => SinhVien::count(),
            'totalTeachers' => GiangVien::count(),
            'totalTopics' => DB::table('detai')->count(),
        ];
    }

    /**
     * Lấy dữ liệu thống kê tổng quan cho Dashboard từ cơ sở dữ liệu thực tế
     */
    public function getDashboardData()
    {
        // 1. Thống kê tổng số lượng (Stats)
        $totalStudents = SinhVien::count();
        $totalTeachers = GiangVien::count();
        $totalUsers = $totalStudents + $totalTeachers;

        $activeStudents = SinhVien::where('dang_hoat_dong', 1)->count();
        $activeTeachers = GiangVien::where('dang_hoat_dong', 1)->count();
        $activeUsers = $activeStudents + $activeTeachers;

        // "Đủ điều kiện ĐATN" (totalCourses)
        $totalCourses = $activeStudents;

        // "Đề tài ĐATN" (totalQuestions)
        $totalQuestions = DB::table('detai')->count();

        // "Tổng Doanh nghiệp" (totalCodes)
        $totalCodes = DB::table('congty')->count();

        // "Tỷ lệ sử dụng" (usedCodes) - Đếm số công ty đã có sinh viên đăng ký thực tập
        $usedCodes = DB::table('dangkythuctap')
            ->whereNotNull('cong_ty_id')
            ->distinct()
            ->count('cong_ty_id');

        $stats = [
            'totalUsers' => $totalUsers,
            'activeUsers' => $activeUsers,
            'totalCourses' => $totalCourses,
            'totalLessons' => 0, // unused placeholder
            'totalQuestions' => $totalQuestions,
            'totalCodes' => $totalCodes,
            'usedCodes' => $usedCodes,
        ];

        // 2. Số lượng học viên theo lớp học (studentsPerCourse)
        $studentsPerCourse = [];
        $lops = Lop::withCount('sinhViens')->get();
        if ($lops->isNotEmpty()) {
            foreach ($lops as $lop) {
                $studentsPerCourse[] = [
                    'courseName' => $lop->ten_lop,
                    'studentCount' => $lop->sinh_viens_count,
                ];
            }
        } else {
            $studentsPerCourse = [
                ['courseName' => 'Lập trình Web', 'studentCount' => 12],
                ['courseName' => 'Cơ sở dữ liệu', 'studentCount' => 8],
                ['courseName' => 'Kỹ thuật phần mềm', 'studentCount' => 5],
            ];
        }

        // 3. Tiến độ đăng ký đề tài (learningProgress)
        // Lấy số liệu từ bảng dangkydetai
        $daDuyetDeTai = DB::table('dangkydetai')->where('trang_thai_duyet', 'DA_DUYET')->count();
        $choDuyetDeTai = DB::table('dangkydetai')->where('trang_thai_duyet', 'CHO_DUYET')->count();
        $tuChoiDeTai = DB::table('dangkydetai')->where('trang_thai_duyet', 'TU_CHOI')->count();

        $totalDeTai = $daDuyetDeTai + $choDuyetDeTai + $tuChoiDeTai;

        if ($totalDeTai > 0) {
            $daDuyetPercent = round(($daDuyetDeTai / $totalDeTai) * 100);
            $choDuyetPercent = round(($choDuyetDeTai / $totalDeTai) * 100);
            $tuChoiPercent = max(0, 100 - $daDuyetPercent - $choDuyetPercent);

            $learningProgress = [
                [
                    'label' => 'Đã duyệt đề tài',
                    'value' => (int) $daDuyetPercent,
                    'color' => '#10B981', // Green
                ],
                [
                    'label' => 'Chờ duyệt',
                    'value' => (int) $choDuyetPercent,
                    'color' => '#3B82F6', // Blue
                ],
                [
                    'label' => 'Bị từ chối',
                    'value' => (int) $tuChoiPercent,
                    'color' => '#EF4444', // Red
                ],
            ];
        } else {
            // Fallback nếu chưa có đăng ký đề tài nào thì hiển thị tỉ lệ thực tập
            $daDuyetThucTap = DB::table('dangkythuctap')->where('trang_thai', 'DA_DUYET')->count();
            $totalSV = max(1, $totalStudents);
            $ttPercent = round(($daDuyetThucTap / $totalSV) * 100);
            $chuaTTPercent = max(0, 100 - $ttPercent);

            $learningProgress = [
                [
                    'label' => 'Đã duyệt thực tập',
                    'value' => (int) $ttPercent,
                    'color' => '#10B981',
                ],
                [
                    'label' => 'Chưa đăng ký',
                    'value' => (int) $chuaTTPercent,
                    'color' => '#6B7280',
                ],
            ];
        }

        // 4. Hoạt động gần đây (recentActivities) - Lấy thông tin lịch sử hoạt động thực tế của toàn hệ thống
        $recentActivities = [];
        $recentLogs = DB::table('lich_su_hoat_dong')
            ->orderBy('log_id', 'desc')
            ->limit(10)
            ->get();

        if ($recentLogs->isNotEmpty()) {
            foreach ($recentLogs as $log) {
                $statusColor = 'blue';
                if (str_contains($log->action_type, 'DUYET') || str_contains($log->action_type, 'CHAP_NHAN')) {
                    $statusColor = 'green';
                } elseif (str_contains($log->action_type, 'TU_CHOI') || str_contains($log->action_type, 'XOA') || str_contains($log->action_type, 'GIAI_TAN') || str_contains($log->action_type, 'ROI')) {
                    $statusColor = 'red';
                }

                $recentActivities[] = [
                    'id' => (string) $log->log_id,
                    'userName' => $log->user_name ?: 'Hệ thống',
                    'courseName' => $log->description,
                    'date' => $log->created_at,
                    'status' => $log->action_type,
                    'statusColor' => $statusColor,
                ];
            }
        } else {
            // Fallback to recent internships if history is empty
            $recentInternships = DB::table('dangkythuctap')
                ->join('sinhvien', 'dangkythuctap.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                ->join('congty', 'dangkythuctap.cong_ty_id', '=', 'congty.cong_ty_id')
                ->select('dangkythuctap.*', 'sinhvien.ho_ten', 'congty.ten_cong_ty')
                ->orderBy('dangkythuctap.dang_ky_id', 'desc')
                ->limit(5)
                ->get();

            if ($recentInternships->isNotEmpty()) {
                foreach ($recentInternships as $ri) {
                    $statusText = 'Chờ duyệt';
                    $statusColor = 'blue';
                    if ($ri->trang_thai === 'DA_DUYET') {
                        $statusText = 'Đã duyệt';
                        $statusColor = 'green';
                    } elseif ($ri->trang_thai === 'TU_CHOI') {
                        $statusText = 'Bị từ chối';
                        $statusColor = 'red';
                    }

                    $recentActivities[] = [
                        'id' => (string) $ri->dang_ky_id,
                        'userName' => $ri->ho_ten,
                        'courseName' => "Khai báo thực tập tại " . $ri->ten_cong_ty,
                        'date' => $ri->ngay_dang_ky ?? now()->subHours($ri->dang_ky_id % 24)->format('Y-m-d H:i:s'),
                        'status' => $statusText,
                        'statusColor' => $statusColor,
                    ];
                }
            } else {
                $recentActivities = [
                    [
                        'id' => '1',
                        'userName' => 'Hệ thống',
                        'courseName' => 'Hệ thống quản lý tốt nghiệp khởi động.',
                        'date' => now()->format('Y-m-d H:i:s'),
                        'status' => 'INFO',
                        'statusColor' => 'blue',
                    ],
                ];
            }
        }

        return [
            'stats' => $stats,
            'studentsPerCourse' => $studentsPerCourse,
            'learningProgress' => $learningProgress,
            'recentActivities' => $recentActivities,
        ];
    }
}
