<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\NguoiDungController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PeriodController;
use App\Http\Controllers\Admin\ClassController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\TopicController;
use App\Http\Controllers\Admin\StudentScoreController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\AssignmentController;
use App\Http\Controllers\Admin\CouncilController;
use App\Http\Controllers\Auth\MockAuthController;

Route::post('/dang-nhap-gia-lap', [MockAuthController::class, 'dangNhap']);
Route::post('/lam-moi-token', [MockAuthController::class, 'lamMoiToken']);
Route::get('/v1/realtime/stream', [\App\Http\Controllers\RealtimeController::class, 'stream']);

Route::middleware('auth:sanctum')->group(function () {
    // Đăng xuất sẽ được xử lý bằng cách thu hồi token hiện tại
    Route::post('/dang-xuat', [MockAuthController::class, 'dangXuat']);

    // Tải tệp lên hệ thống (Dành cho mọi đối tượng đã đăng nhập)
    Route::post('/v1/file-upload/upload', [UploadController::class, 'upload']);
    Route::post('/private/v1/upload', [UploadController::class, 'upload']);

    // Quản lý đề tài (Topics) - Mở cho mọi vai trò (Admin duyệt, GVHD đề xuất, SV xem đăng ký)
    Route::get('/private/v1/topics', [TopicController::class, 'layDanhSach']);
    Route::get('/private/v1/topics/{id}', [TopicController::class, 'xemChiTiet']);
    Route::post('/private/v1/topics', [TopicController::class, 'themMoi']);
    Route::patch('/private/v1/topics/{id}', [TopicController::class, 'capNhat']);
    Route::delete('/private/v1/topics/{id}', [TopicController::class, 'xoa']);

    Route::middleware('quyen:SINH_VIEN')->prefix('sinh-vien')->group(function () {
        // Tuyến đường viết API cho sinh viên sau này
    });

    Route::middleware('quyen:GIANG_VIEN')->prefix('giang-vien')->group(function () {
        // Tuyến đường viết API cho giảng viên sau này
    });

    Route::middleware('quyen:ADMIN')->group(function () {
        Route::prefix('admin')->group(function () {
            // 1. Chức năng quản lý người dùng (Đồng bộ với Frontend)
            Route::get('/sinh-vien', [NguoiDungController::class, 'layDanhSach']);
            Route::get('/sinh-vien/{id}', [NguoiDungController::class, 'xemChiTiet']);
            Route::post('/sinh-vien', [NguoiDungController::class, 'themMoi']);
            Route::patch('/sinh-vien/{id}', [NguoiDungController::class, 'capNhat']);
            Route::delete('/sinh-vien/{id}', [NguoiDungController::class, 'xoaNguoiDung']);
            Route::post('/sinh-vien/{id}/reset-password', [NguoiDungController::class, 'resetPassword']);

            // Các route cũ (Legacy) đề phòng tương thích ngược
            Route::get('/sinh-vien-legacy', [NguoiDungController::class, 'layDanhSachSinhVien']);
            Route::post('/them-sinh-vien', [NguoiDungController::class, 'themSinhVien']);
            Route::put('/cap-nhat-sinh-vien/{sinh_vien_id}', [NguoiDungController::class, 'capNhatSinhVien']);
            Route::patch('/sinh-vien/khoa-tai-khoan', [NguoiDungController::class, 'khoaTaiKhoanSinhVien']);
            
            Route::get('/giang-vien', [NguoiDungController::class, 'layDanhSachGiangVien']);
            Route::post('/them-giang-vien', [NguoiDungController::class, 'themGiangVien']);
            Route::put('/cap-nhat-giang-vien/{giang_vien_id}', [NguoiDungController::class, 'capNhatGiangVien']);
            Route::patch('/giang-vien/khoa-tai-khoan', [NguoiDungController::class, 'khoaTaiKhoanGiangVien']);

            // 2. Chức năng quản lý công ty thực tập
        });

        // 3. Chức năng thống kê Dashboard (Đồng bộ với Frontend)
        Route::get('/private/v1/dashboard', [DashboardController::class, 'getDashboardData']);

        // 4. Chức năng quản lý đợt tốt nghiệp (Periods)
        Route::get('/private/v1/periods', [PeriodController::class, 'layDanhSach']);
        Route::get('/private/v1/periods/{id}', [PeriodController::class, 'xemChiTiet']);
        Route::post('/private/v1/periods', [PeriodController::class, 'themMoi']);
        Route::patch('/private/v1/periods/{id}', [PeriodController::class, 'capNhat']);
        Route::delete('/private/v1/periods/{id}', [PeriodController::class, 'xoa']);

        // 5. Chức năng quản lý lớp học (Classes)
        Route::get('/private/v1/classes', [ClassController::class, 'layDanhSach']);
        Route::get('/private/v1/classes/{id}', [ClassController::class, 'xemChiTiet']);
        Route::post('/private/v1/classes', [ClassController::class, 'themMoi']);
        Route::patch('/private/v1/classes/{id}', [ClassController::class, 'capNhat']);
        Route::delete('/private/v1/classes/{id}', [ClassController::class, 'xoa']);

        // 6. Chức năng quản lý doanh nghiệp & thực tập (Companies & Internships)
        Route::get('/private/v1/companies', [CompanyController::class, 'layDanhSach']);
        Route::get('/private/v1/companies/{id}', [CompanyController::class, 'xemChiTiet']);
        Route::post('/private/v1/companies', [CompanyController::class, 'themMoi']);
        Route::patch('/private/v1/companies/{id}', [CompanyController::class, 'capNhat']);
        Route::delete('/private/v1/companies/{id}', [CompanyController::class, 'xoa']);

        Route::get('/private/v1/internships/confirmations', [CompanyController::class, 'layDanhSachXacNhan']);
        Route::get('/private/v1/internships/confirmations/{id}', [CompanyController::class, 'xemChiTietXacNhan']);
        Route::post('/private/v1/internships/confirmations', [CompanyController::class, 'themMoiXacNhan']);
        Route::patch('/private/v1/internships/confirmations/{id}', [CompanyController::class, 'capNhatXacNhan']);
        Route::delete('/private/v1/internships/confirmations/{id}', [CompanyController::class, 'xoaXacNhan']);

        Route::get('/private/v1/internships/no-company', [CompanyController::class, 'layDanhSachChuaThucTap']);
        Route::get('/private/v1/internships/no-company/{id}', [CompanyController::class, 'xemChiTietChuaThucTap']);

        // 7. Chức năng quản lý điểm số (Student Scores)
        Route::get('/private/v1/student-scores', [StudentScoreController::class, 'layDanhSach']);
        Route::get('/private/v1/student-scores/{id}', [StudentScoreController::class, 'xemChiTiet']);
        Route::patch('/private/v1/student-scores/{id}', [StudentScoreController::class, 'capNhat']);

        // 8. Chức năng quản lý nhóm (Groups)
        Route::get('/private/v1/groups', [GroupController::class, 'layDanhSach']);
        Route::get('/private/v1/groups/{id}', [GroupController::class, 'xemChiTiet']);
        Route::patch('/private/v1/groups/{id}', [GroupController::class, 'capNhat']);
        Route::delete('/private/v1/groups/{id}', [GroupController::class, 'xoa']);
        Route::post('/private/v1/groups/{id}/approve', [GroupController::class, 'approveGroup']);
        Route::post('/private/v1/groups/{id}/reject', [GroupController::class, 'rejectGroup']);

        // 9. Chức năng phân công hướng dẫn (Assignments)
        Route::get('/private/v1/assignments', [AssignmentController::class, 'layDanhSach']);
        Route::get('/private/v1/assignments/{id}', [AssignmentController::class, 'xemChiTiet']);
        Route::patch('/private/v1/assignments/{id}', [AssignmentController::class, 'capNhat']);
        Route::get('/private/v1/teachers', [AssignmentController::class, 'getTeachers']);

        // 10. Chức năng quản lý hội đồng (Councils)
        Route::get('/private/v1/councils', [CouncilController::class, 'layDanhSach']);
        Route::get('/private/v1/councils/{id}', [CouncilController::class, 'xemChiTiet']);
        Route::post('/private/v1/councils', [CouncilController::class, 'themMoi']);
        Route::patch('/private/v1/councils/{id}', [CouncilController::class, 'capNhat']);
        Route::delete('/private/v1/councils/{id}', [CouncilController::class, 'xoa']);
    });
});