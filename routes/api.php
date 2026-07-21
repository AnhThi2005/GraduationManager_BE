<?php

use App\Http\Controllers\Admin\CongTyController;
use App\Http\Controllers\Admin\DeTaiController;
use App\Http\Controllers\Admin\DiemSinhVienController;
use App\Http\Controllers\Admin\DotController;
use App\Http\Controllers\Admin\HoiDongController;
use App\Http\Controllers\Admin\LopController;
use App\Http\Controllers\Admin\NguoiDungController;
use App\Http\Controllers\Admin\NhomController;
use App\Http\Controllers\Admin\PhanCongHdttController;
use App\Http\Controllers\Admin\TaiLenController;
use App\Http\Controllers\Admin\ThongKeController;
use App\Http\Controllers\Auth\MockAuthController;
use App\Http\Controllers\RealtimeController;
use App\Http\Controllers\SinhVien\BaoCaoController;
use App\Http\Controllers\SinhVien\DiemController;
use App\Http\Controllers\SinhVien\ThucTapController;
use App\Http\Controllers\SinhVien\TrangChuController;
use Illuminate\Support\Facades\Route;

Route::post('/dang-nhap-gia-lap', [MockAuthController::class, 'dangNhap']);
Route::post('/dang-nhap-google', [MockAuthController::class, 'dangNhapGoogle']);
Route::post('/lam-moi-token', [MockAuthController::class, 'lamMoiToken']);
Route::get('/v1/realtime/stream', [RealtimeController::class, 'stream']);

// Thống kê công khai (không cần đăng nhập) - dùng cho màn hình đăng nhập
Route::get('/private/v1/public/thong-ke-tong-quan', [ThongKeController::class, 'getPublicSummary']);

Route::get('/login', function () {
    return response()->json([
        'success' => false,
        'message' => 'Phiên làm việc đã hết hạn hoặc chưa đăng nhập. Vui lòng đăng nhập lại!',
    ], 401);
})->name('login');

Route::middleware('auth:sanctum')->group(function () {
    // Đăng xuất sẽ được xử lý bằng cách thu hồi token hiện tại
    Route::post('/dang-xuat', [MockAuthController::class, 'dangXuat']);

    // Tải tệp lên hệ thống (Dành cho mọi đối tượng đã đăng nhập)
    Route::post('/v1/file-upload/upload', [TaiLenController::class, 'upload']);
    Route::post('/private/v1/upload', [TaiLenController::class, 'upload']);

    // Quản lý đề tài (Topics) - Mở cho mọi vai trò (Admin duyệt, GVHD đề xuất, SV xem đăng ký)
    Route::get('/private/v1/topic-directions', [DeTaiController::class, 'layDanhSachHuong']);
    Route::post('/private/v1/topics/import', [\App\Http\Controllers\GiangVien\DeTaiController::class, 'import'])->middleware('quyen:ADMIN');
    Route::get('/private/v1/topics', [DeTaiController::class, 'layDanhSach']);
    Route::get('/private/v1/topics/{id}', [DeTaiController::class, 'xemChiTiet']);
    Route::post('/private/v1/topics', [DeTaiController::class, 'themMoi']);
    Route::patch('/private/v1/topics/{id}', [DeTaiController::class, 'capNhat']);
    Route::delete('/private/v1/topics/{id}', [DeTaiController::class, 'xoa']);

    // Quản lý đợt học (Periods) - Cho phép mọi vai trò xem danh sách đợt học
    Route::get('/private/v1/periods', [DotController::class, 'layDanhSach']);

    Route::middleware('quyen:SINH_VIEN')->group(function () {
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

    Route::middleware('quyen:GIANG_VIEN')->prefix('private/v1/teacher')->group(function () {
        Route::get('/profile', [App\Http\Controllers\GiangVien\TrangChuController::class, 'getProfile']);
        Route::get('/dashboard', [App\Http\Controllers\GiangVien\TrangChuController::class, 'getDashboardData']);
        Route::get('/grading', [App\Http\Controllers\GiangVien\DiemController::class, 'getGradingData']);
        Route::get('/scores', [App\Http\Controllers\GiangVien\DiemController::class, 'getScores']);
        Route::post('/scores', [App\Http\Controllers\GiangVien\DiemController::class, 'saveScores']);
        Route::post('/tttn-scores', [App\Http\Controllers\GiangVien\DiemController::class, 'saveTttnScores']);
        Route::get('/groups', [App\Http\Controllers\GiangVien\DeTaiController::class, 'getGroups']);
        Route::patch('/groups/{groupId}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'updateGroupStatus']);
        Route::get('/review-groups', [App\Http\Controllers\GiangVien\NhomController::class, 'getReviewGroups']);
        Route::patch('/review-groups/{groupId}', [App\Http\Controllers\GiangVien\NhomController::class, 'updateReviewGroupStatus']);
        Route::get('/topics', [App\Http\Controllers\GiangVien\DeTaiController::class, 'layDanhSach']);
        Route::get('/topics/{id}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'xemChiTiet']);
        Route::post('/topics', [App\Http\Controllers\GiangVien\DeTaiController::class, 'themMoi']);
        Route::patch('/topics/{id}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'capNhat']);
        Route::delete('/topics/{id}', [App\Http\Controllers\GiangVien\DeTaiController::class, 'xoa']);
        Route::post('/topics/import', [App\Http\Controllers\GiangVien\DeTaiController::class, 'import']);
        Route::get('/students', [App\Http\Controllers\GiangVien\NhomController::class, 'layDanhSachSinhVien']);
        Route::post('/report-comment', [App\Http\Controllers\GiangVien\NhomController::class, 'saveReportComment']);
        Route::get('/history', [\App\Http\Controllers\HistoryController::class, 'getTeacherHistory']);
    });

    // Nhóm route Tiếng Việt chuẩn hóa cho Giảng viên (kebab-case)
    Route::middleware('quyen:GIANG_VIEN')->prefix('giang-vien')->group(function () {
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
        Route::get('/lich-su', [\App\Http\Controllers\HistoryController::class, 'getTeacherHistory']);
    });

    Route::middleware('quyen:ADMIN')->group(function () {
        Route::prefix('admin')->group(function () {
            // 1. Chức năng quản lý người dùng (Đồng bộ với Frontend)
            Route::get('/users', [NguoiDungController::class, 'layDanhSach']);
            Route::get('/users/specializations', [NguoiDungController::class, 'layDanhSachChuyenMon']);
            Route::post('/users/import-students', [NguoiDungController::class, 'importStudents']);
            Route::get('/users/{id}', [NguoiDungController::class, 'xemChiTiet']);
            Route::post('/users', [NguoiDungController::class, 'themMoi']);
            Route::patch('/users/{id}', [NguoiDungController::class, 'capNhat']);
            Route::delete('/users/{id}', [NguoiDungController::class, 'xoaNguoiDung']);

            // 2. Chức năng quản lý công ty thực tập
        });

        // 3. Chức năng thống kê Dashboard (Đồng bộ với Frontend)
        Route::get('/private/v1/dashboard', [ThongKeController::class, 'getDashboardData']);

        // 4. Chức năng quản lý đợt tốt nghiệp (Periods)
        Route::post('/private/v1/periods/add-student', [DotController::class, 'themSinhVienVaoCacDot']);
        Route::get('/private/v1/periods/{id}', [DotController::class, 'xemChiTiet']);
        Route::post('/private/v1/periods', [DotController::class, 'themMoi']);
        Route::patch('/private/v1/periods/{id}', [DotController::class, 'capNhat']);
        Route::delete('/private/v1/periods/{id}', [DotController::class, 'xoa']);

        // 5. Chức năng quản lý lớp học (Classes)
        Route::get('/private/v1/classes', [LopController::class, 'layDanhSach']);
        Route::get('/private/v1/classes-metadata', [LopController::class, 'layMetadata']);
        Route::get('/private/v1/classes/{id}', [LopController::class, 'xemChiTiet']);
        Route::post('/private/v1/classes', [LopController::class, 'themMoi']);
        Route::patch('/private/v1/classes/{id}', [LopController::class, 'capNhat']);
        Route::delete('/private/v1/classes/{id}', [LopController::class, 'xoa']);

        // 6. Chức năng quản lý doanh nghiệp & thực tập (Companies & Internships)
        Route::get('/private/v1/companies/lookup-tax', [CongTyController::class, 'traCuuMaSoThue']);
        Route::post('/private/v1/companies/publish', [CongTyController::class, 'congBo']);
        Route::post('/private/v1/companies/import', [CongTyController::class, 'import']);
        Route::get('/private/v1/companies', [CongTyController::class, 'layDanhSach']);
        Route::get('/private/v1/companies/{id}', [CongTyController::class, 'xemChiTiet']);
        Route::post('/private/v1/companies', [CongTyController::class, 'themMoi']);
        Route::patch('/private/v1/companies/{id}', [CongTyController::class, 'capNhat']);
        Route::delete('/private/v1/companies/{id}', [CongTyController::class, 'xoa']);

        Route::get('/private/v1/internships/confirmations', [CongTyController::class, 'layDanhSachXacNhan']);

        Route::get('/private/v1/internships/declarations', [CongTyController::class, 'layDanhSachKhaiBao']);
        Route::post('/private/v1/internships/declarations', [CongTyController::class, 'themMoiXacNhan']);
        // Route tĩnh batch-approve PHẢI đứng TRƯỚC route động {id} — Laravel khớp theo thứ tự
        Route::post('/private/v1/internships/declarations/batch-approve', [CongTyController::class, 'capNhatHangLoat']);
        Route::get('/private/v1/internships/declarations/{id}', [CongTyController::class, 'xemChiTietXacNhan'])->where('id', '[0-9]+');
        Route::patch('/private/v1/internships/declarations/{id}', [CongTyController::class, 'capNhatXacNhan'])->where('id', '[0-9]+');
        Route::delete('/private/v1/internships/declarations/{id}', [CongTyController::class, 'xoaXacNhan'])->where('id', '[0-9]+');

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
        Route::post('/private/v1/councils/publish', [HoiDongController::class, 'congBoTatCa']);
        Route::get('/private/v1/councils/{id}', [HoiDongController::class, 'xemChiTiet']);
        Route::post('/private/v1/councils', [HoiDongController::class, 'themMoi']);
        Route::patch('/private/v1/councils/{id}', [HoiDongController::class, 'capNhat']);
        Route::delete('/private/v1/councils/{id}', [HoiDongController::class, 'xoa']);

        // 11. Chức năng xem lịch sử hoạt động (Activity Logs)
        Route::get('/private/v1/admin/history', [\App\Http\Controllers\HistoryController::class, 'getAdminHistory']);
    });
});
