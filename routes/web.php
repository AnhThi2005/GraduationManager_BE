<?php

use App\Http\Controllers\Admin\CongTyController;
use App\Http\Controllers\Admin\DeTaiController;
use App\Http\Controllers\Admin\DiemSinhVienController;
use App\Http\Controllers\Admin\DotController;
use App\Http\Controllers\Admin\HoiDongController;
use App\Http\Controllers\Admin\LopController;
use App\Http\Controllers\Admin\NhomController;
use App\Http\Controllers\Admin\PhanCongHdttController;
use App\Http\Controllers\Admin\TaiLenController;
use App\Http\Controllers\Admin\ThongKeController;
use App\Http\Controllers\RealtimeController;
use App\Http\Controllers\SinhVien\BaoCaoController;
use App\Http\Controllers\SinhVien\DiemController;
use App\Http\Controllers\SinhVien\ThucTapController;
use App\Http\Controllers\SinhVien\TrangChuController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Thống kê công khai (không cần đăng nhập) - dùng cho màn hình đăng nhập
// Bỏ session/CSRF của middleware "web": đây là API JSON stateless, không cần cookie phiên,
// và StartSession từng gây lỗi ghi bảng "sessions" khiến response mất header CORS (trình duyệt
// hiểu nhầm thành lỗi CORS thay vì lỗi 500 thật).
Route::get('/private/v1/public/thong-ke-tong-quan', [ThongKeController::class, 'getPublicSummary'])
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ]);

// Fallback routes for frontend requests missing the /api prefix
Route::middleware([
    'auth:sanctum',
    'quyen:ADMIN',
])->group(function () {
    Route::get('/private/v1/dashboard', [ThongKeController::class, 'getDashboardData']);
    Route::post('/private/v1/periods/add-student', [DotController::class, 'themSinhVienVaoCacDot']);
    Route::get('/private/v1/periods/{id}', [DotController::class, 'xemChiTiet']);
    Route::post('/private/v1/periods', [DotController::class, 'themMoi']);
    Route::patch('/private/v1/periods/{id}', [DotController::class, 'capNhat']);
    Route::delete('/private/v1/periods/{id}', [DotController::class, 'xoa']);

    Route::get('/private/v1/classes', [LopController::class, 'layDanhSach']);
    Route::get('/private/v1/classes-metadata', [LopController::class, 'layMetadata']);
    Route::get('/private/v1/classes/{id}', [LopController::class, 'xemChiTiet']);
    Route::post('/private/v1/classes', [LopController::class, 'themMoi']);
    Route::patch('/private/v1/classes/{id}', [LopController::class, 'capNhat']);
    Route::delete('/private/v1/classes/{id}', [LopController::class, 'xoa']);

    Route::get('/private/v1/companies/lookup-tax', [CongTyController::class, 'traCuuMaSoThue']);
    Route::get('/private/v1/companies', [CongTyController::class, 'layDanhSach']);
    Route::get('/private/v1/companies/{id}', [CongTyController::class, 'xemChiTiet']);
    Route::post('/private/v1/companies', [CongTyController::class, 'themMoi']);
    Route::patch('/private/v1/companies/{id}', [CongTyController::class, 'capNhat']);
    Route::delete('/private/v1/companies/{id}', [CongTyController::class, 'xoa']);
    Route::post('/private/v1/companies/publish', [CongTyController::class, 'congBo']);

    Route::get('/private/v1/internships/confirmations', [CongTyController::class, 'layDanhSachXacNhan']);

    Route::get('/private/v1/internships/declarations', [CongTyController::class, 'layDanhSachKhaiBao']);
    Route::get('/private/v1/internships/declarations/{id}', [CongTyController::class, 'xemChiTietXacNhan']);
    Route::post('/private/v1/internships/declarations', [CongTyController::class, 'themMoiXacNhan']);
    Route::patch('/private/v1/internships/declarations/{id}', [CongTyController::class, 'capNhatXacNhan']);
    Route::delete('/private/v1/internships/declarations/{id}', [CongTyController::class, 'xoaXacNhan']);

    Route::get('/private/v1/internships/no-company', [CongTyController::class, 'layDanhSachChuaThucTap']);
    Route::get('/private/v1/internships/no-company/{id}', [CongTyController::class, 'xemChiTietChuaThucTap']);
    Route::patch('/private/v1/internships/no-company/{id}', [CongTyController::class, 'capNhatChuaThucTap']);
    Route::delete('/private/v1/internships/no-company/{id}', [CongTyController::class, 'xoaChuaThucTap']);

    // 7. Chức năng quản lý điểm số (Student Scores)
    Route::get('/private/v1/student-scores', [DiemSinhVienController::class, 'layDanhSach']);
    Route::get('/private/v1/student-scores/{id}', [DiemSinhVienController::class, 'xemChiTiet']);

    // 8. Chức năng quản lý nhóm (Groups)
    Route::get('/private/v1/groups', [NhomController::class, 'layDanhSach']);
    Route::post('/private/v1/groups', [NhomController::class, 'themMoi']);
    Route::get('/private/v1/groups/{id}', [NhomController::class, 'xemChiTiet']);
    Route::patch('/private/v1/groups/{id}', [NhomController::class, 'capNhat']);
    Route::delete('/private/v1/groups/{id}', [NhomController::class, 'xoa']);
    Route::post('/private/v1/groups/{id}/approve', [NhomController::class, 'approveGroup']);
    Route::post('/private/v1/groups/{id}/reject', [NhomController::class, 'rejectGroup']);
    Route::post('/private/v1/groups/swap-members', [NhomController::class, 'swapMembers']);

    // 9. Chức năng phân công hướng dẫn (Assignments)
    Route::get('/private/v1/assignments', [PhanCongHdttController::class, 'layDanhSach']);
    Route::post('/private/v1/assignments/publish', [PhanCongHdttController::class, 'congBo']);
    Route::get('/private/v1/assignments/{id}', [PhanCongHdttController::class, 'xemChiTiet']);
    Route::patch('/private/v1/assignments/{id}', [PhanCongHdttController::class, 'capNhat']);
    Route::delete('/private/v1/assignments/{id}', [PhanCongHdttController::class, 'xoa']);
    Route::get('/private/v1/teachers', [PhanCongHdttController::class, 'getTeachers']);
    Route::patch('/private/v1/students/{studentId}/eligibility', [PhanCongHdttController::class, 'capNhatDieuKienLamDoAn']);

    // 10. Chức năng quản lý hội đồng (Councils)
    Route::get('/private/v1/councils', [HoiDongController::class, 'layDanhSach']);
    Route::get('/private/v1/councils/{id}', [HoiDongController::class, 'xemChiTiet']);
    Route::post('/private/v1/councils', [HoiDongController::class, 'themMoi']);
    Route::patch('/private/v1/councils/{id}', [HoiDongController::class, 'capNhat']);
    Route::delete('/private/v1/councils/{id}', [HoiDongController::class, 'xoa']);
});

Route::middleware([
    'auth:sanctum',
    'quyen:SINH_VIEN',
])->group(function () {
    Route::get('/private/v1/student/dashboard', [TrangChuController::class, 'layThongTinTrangChu']);
    Route::get('/private/v1/student/companies', [ThucTapController::class, 'layDanhSachCongTy']);
    Route::get('/private/v1/student/companies/lookup-tax', [ThucTapController::class, 'traCuuMaSoThue']);
    Route::post('/private/v1/student/internships/declare', [ThucTapController::class, 'khaiBaoThucTap']);
    Route::get('/private/v1/student/internships/my-request', [ThucTapController::class, 'xemYeuCauCuaToi']);
    Route::get('/private/v1/student/thesis/my-registration', [App\Http\Controllers\SinhVien\DeTaiController::class, 'xemDangKyCuaToi']);
    Route::post('/private/v1/student/thesis/register', [App\Http\Controllers\SinhVien\DeTaiController::class, 'dangKyDeTai']);
    Route::post('/private/v1/student/thesis/cancel', [App\Http\Controllers\SinhVien\DeTaiController::class, 'huyDangKy']);
    Route::post('/private/v1/student/thesis/group/leave', [App\Http\Controllers\SinhVien\DeTaiController::class, 'giaiTanNhom']);
    Route::get('/private/v1/student/thesis/invitations/outgoing', [App\Http\Controllers\SinhVien\DeTaiController::class, 'xemLoiMoiDaGui']);
    Route::post('/private/v1/student/thesis/invitations/send', [App\Http\Controllers\SinhVien\DeTaiController::class, 'guiLoiMoiNhom']);
    Route::get('/private/v1/student/thesis/invitations/incoming', [App\Http\Controllers\SinhVien\DeTaiController::class, 'xemLoiMoiNhanDuoc']);
    Route::post('/private/v1/student/thesis/invitations/{id}/accept', [App\Http\Controllers\SinhVien\DeTaiController::class, 'chapNhanLoiMoi']);
    Route::post('/private/v1/student/thesis/invitations/{id}/reject', [App\Http\Controllers\SinhVien\DeTaiController::class, 'tuChoiLoiMoi']);
    Route::post('/private/v1/student/thesis/invitations/{id}/cancel', [App\Http\Controllers\SinhVien\DeTaiController::class, 'huyLoiMoiNhom']);
    Route::get('/private/v1/student/students/search', [App\Http\Controllers\SinhVien\DeTaiController::class, 'timKiemSinhVien']);
    Route::get('/private/v1/student/reports/tttn', [BaoCaoController::class, 'layDanhSachBaoCaoTttn']);
    Route::post('/private/v1/student/reports/tttn', [BaoCaoController::class, 'nopBaoCaoTttn']);
    Route::get('/private/v1/student/reports/datn', [BaoCaoController::class, 'layDanhSachBaoCaoDatn']);
    Route::post('/private/v1/student/reports/datn', [BaoCaoController::class, 'nopBaoCaoDatn']);
    Route::get('/private/v1/student/results', [DiemController::class, 'layKetQuaHocTap']);
    Route::get('/private/v1/student/history', [\App\Http\Controllers\HistoryController::class, 'getStudentHistory']);
});

Route::middleware([
    'auth:sanctum',
    'quyen:GIANG_VIEN',
])->group(function () {
    Route::get('/private/v1/teacher/profile', [App\Http\Controllers\GiangVien\TrangChuController::class, 'getProfile']);
    Route::get('/private/v1/teacher/dashboard', [App\Http\Controllers\GiangVien\TrangChuController::class, 'getDashboardData']);
    Route::get('/private/v1/teacher/grading', [App\Http\Controllers\GiangVien\DiemController::class, 'getGradingData']);
    Route::get('/private/v1/teacher/scores', [App\Http\Controllers\GiangVien\DiemController::class, 'getScores']);
    Route::post('/private/v1/teacher/scores', [App\Http\Controllers\GiangVien\DiemController::class, 'saveScores']);
    Route::post('/private/v1/teacher/tttn-scores', [App\Http\Controllers\GiangVien\DiemController::class, 'saveTttnScores']);
    Route::get('/private/v1/teacher/groups', [App\Http\Controllers\GiangVien\DeTaiController::class, 'getGroups']);
    Route::patch('/private/v1/teacher/groups/{groupId}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'updateGroupStatus']);
    Route::get('/private/v1/teacher/review-groups', [App\Http\Controllers\GiangVien\NhomController::class, 'getReviewGroups']);
    Route::patch('/private/v1/teacher/review-groups/{groupId}', [App\Http\Controllers\GiangVien\NhomController::class, 'updateReviewGroupStatus']);
    Route::get('/private/v1/teacher/topics', [App\Http\Controllers\GiangVien\DeTaiController::class, 'layDanhSach']);
    Route::get('/private/v1/teacher/topics/{id}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'xemChiTiet']);
    Route::post('/private/v1/teacher/topics', [App\Http\Controllers\GiangVien\DeTaiController::class, 'themMoi']);
    Route::patch('/private/v1/teacher/topics/{id}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'capNhat']);
    Route::delete('/private/v1/teacher/topics/{id}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'xoa']);
    Route::post('/private/v1/teacher/topics/import', [App\Http\Controllers\GiangVien\DeTaiController::class, 'import']);
    Route::get('/private/v1/teacher/students', [App\Http\Controllers\GiangVien\NhomController::class, 'layDanhSachSinhVien']);
    Route::post('/private/v1/teacher/report-comment', [App\Http\Controllers\GiangVien\NhomController::class, 'saveReportComment']);
    Route::get('/private/v1/teacher/history', [\App\Http\Controllers\HistoryController::class, 'getTeacherHistory']);

    // Nhóm route Tiếng Việt chuẩn hóa cho Giảng viên (kebab-case)
    Route::prefix('giang-vien')->group(function () {
        // 1. Dashboard & Hồ sơ
        Route::get('/tong-quan', [App\Http\Controllers\GiangVien\TrangChuController::class, 'getDashboardData']);
        Route::get('/ho-so', [App\Http\Controllers\GiangVien\TrangChuController::class, 'getProfile']);

        // 2. Quản lý Nhóm đồ án (nhóm hướng dẫn & nhóm phản biện)
        Route::get('/nhom-do-an', [App\Http\Controllers\GiangVien\DeTaiController::class, 'getGroups']);
        Route::patch('/nhom-do-an/{groupId}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'updateGroupStatus']);
        Route::get('/nhom-phan-bien', [App\Http\Controllers\GiangVien\NhomController::class, 'getReviewGroups']);
        Route::patch('/nhom-phan-bien/{groupId}', [App\Http\Controllers\GiangVien\NhomController::class, 'updateReviewGroupStatus']);

        // 3. Phân công / Hướng dẫn đề tài (Quản lý đề tài)
        Route::get('/de-tai', [App\Http\Controllers\GiangVien\DeTaiController::class, 'layDanhSach']);
        Route::get('/de-tai/{id}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'xemChiTiet']);
        Route::post('/de-tai', [App\Http\Controllers\GiangVien\DeTaiController::class, 'themMoi']);
        Route::patch('/de-tai/{id}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'capNhat']);
        Route::delete('/de-tai/{id}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'xoa']);
        Route::post('/de-tai/nhap-excel', [App\Http\Controllers\GiangVien\DeTaiController::class, 'import']);

        // 4. Chấm điểm & Đánh giá
        Route::get('/cham-diem', [App\Http\Controllers\GiangVien\DiemController::class, 'getGradingData']);
        Route::get('/diem-so', [App\Http\Controllers\GiangVien\DiemController::class, 'getScores']);
        Route::post('/diem-so', [App\Http\Controllers\GiangVien\DiemController::class, 'saveScores']);
        Route::post('/diem-thuc-tap', [App\Http\Controllers\GiangVien\DiemController::class, 'saveTttnScores']);

        // 5. Hội đồng bảo vệ (Chấm điểm hội đồng)
        Route::get('/hoi-dong', [App\Http\Controllers\GiangVien\DiemController::class, 'getGradingData']);

        // 6. Quản lý sinh viên hướng dẫn & theo dõi tiến độ
        Route::get('/sinh-vien-huong-dan', [App\Http\Controllers\GiangVien\NhomController::class, 'layDanhSachSinhVien']);
        Route::post('/nhan-xet-bao-cao', [App\Http\Controllers\GiangVien\NhomController::class, 'saveReportComment']);

        // 7. Nộp & Quản lý tài liệu (Upload tài liệu dành riêng cho giảng viên)
        Route::post('/tai-len-tai-lieu', [TaiLenController::class, 'upload']);

        // 8. Lịch hẹn / Tư vấn (Placeholder do hệ thống chưa có db/model/logic)
        Route::get('/lich-hen', function () {
            return response()->json([
                'success' => true,
                'message' => 'Tính năng quản lý lịch hẹn gặp/tư vấn đang được cập nhật.',
                'data' => [],
            ]);
        });

        // 9. Báo cáo cá nhân
        Route::get('/bao-cao-ca-nhan', [App\Http\Controllers\GiangVien\TrangChuController::class, 'getDashboardData']);
    });
});

// Fallback upload routes (requires authentication, open to all roles)
Route::middleware([
    'auth:sanctum',
])->group(function () {
    Route::post('/v1/file-upload/upload', [TaiLenController::class, 'upload']);
    Route::post('/private/v1/upload', [TaiLenController::class, 'upload']);

    Route::get('/private/v1/topic-directions', [DeTaiController::class, 'layDanhSachHuong']);
    Route::get('/private/v1/topics', [DeTaiController::class, 'layDanhSach']);
    Route::get('/private/v1/topics/{id}', [DeTaiController::class, 'xemChiTiet']);
    Route::post('/private/v1/topics', [DeTaiController::class, 'themMoi']);
    Route::patch('/private/v1/topics/{id}', [DeTaiController::class, 'capNhat']);
    Route::delete('/private/v1/topics/{id}', [DeTaiController::class, 'xoa']);

    Route::get('/private/v1/periods', [DotController::class, 'layDanhSach']);
});

Route::get('/v1/realtime/stream', [RealtimeController::class, 'stream']);
