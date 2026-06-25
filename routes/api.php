<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\NguoiDungController;
use App\Http\Controllers\Admin\QuanLyDangKyThucTapController;
use App\Http\Controllers\Admin\DotController;
use App\Http\Controllers\Admin\CongTyController;
use App\Http\Controllers\Admin\LopController;
use App\Http\Controllers\Admin\NhomController;
use App\Http\Controllers\Admin\DeTaiController;
use App\Http\Controllers\Admin\DiemController;
use App\Http\Controllers\Admin\PhanCongController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Admin\CouncilController;
use App\Http\Controllers\Auth\MockAuthController;

// Public routes (no authentication needed)
Route::post('api/dang-nhap-gia-lap', [MockAuthController::class, 'dangNhap']);
Route::post('api/lam-moi-token', [MockAuthController::class, 'lamMoiToken']);

// Protected routes (Authorized admin group)
Route::middleware(['auth:sanctum', 'quyen:ADMIN'])->group(function () {

    // Đăng xuất
    Route::post('api/dang-xuat', [MockAuthController::class, 'dangXuat']);

    // Admin User management (Sinh viên & Giảng viên)
    Route::prefix('api/admin')->group(function () {
        Route::get('/sinh-vien', [NguoiDungController::class, 'layDanhSachSinhVien']);
        Route::get('/sinh-vien/{id}', [NguoiDungController::class, 'layChiTietNguoiDung']);
        Route::post('/sinh-vien', [NguoiDungController::class, 'themNguoiDung']);
        Route::patch('/sinh-vien/{id}', [NguoiDungController::class, 'capNhatNguoiDung']);
        Route::delete('/sinh-vien/{id}', [NguoiDungController::class, 'xoaNguoiDung']);
        Route::post('/sinh-vien/{id}/reset-password', [NguoiDungController::class, 'resetMatKhau']);
    });

    // Private v1 API group
    Route::prefix('private/v1')->group(function () {
        // Đợt thực tập / đồ án (Periods)
        Route::get('/periods', [DotController::class, 'layDanhSachDot']);
        Route::get('/periods/{id}', [DotController::class, 'layChiTietDot']);
        Route::post('/periods', [DotController::class, 'themDot']);
        Route::patch('/periods/{id}', [DotController::class, 'capNhatDot']);
        Route::delete('/periods/{id}', [DotController::class, 'xoaDot']);

        // Công ty thực tập (Companies)
        Route::get('/companies', [CongTyController::class, 'layDanhSachCongTy']);
        Route::get('/companies/{id}', [CongTyController::class, 'layChiTietCongTy']);
        Route::post('/companies', [CongTyController::class, 'themCongTy']);
        Route::patch('/companies/{id}', [CongTyController::class, 'capNhatCongTy']);
        Route::delete('/companies/{id}', [CongTyController::class, 'xoaCongTy']);

        // Lớp học (Classes)
        Route::get('/classes', [LopController::class, 'layDanhSachLop']);
        Route::get('/classes/{id}', [LopController::class, 'layChiTietLop']);
        Route::post('/classes', [LopController::class, 'themLop']);
        Route::patch('/classes/{id}', [LopController::class, 'capNhatLop']);
        Route::delete('/classes/{id}', [LopController::class, 'xoaLop']);

        // Nhóm đồ án (Groups)
        Route::get('/groups', [NhomController::class, 'layDanhSachNhom']);
        Route::get('/groups/{id}', [NhomController::class, 'layChiTietNhom']);
        Route::post('/groups', [NhomController::class, 'themNhom']);
        Route::patch('/groups/{id}', [NhomController::class, 'capNhatNhom']);
        Route::delete('/groups/{id}', [NhomController::class, 'xoaNhom']);
        Route::post('/groups/{id}/approve', [NhomController::class, 'duyetNhom']);
        Route::post('/groups/{id}/reject', [NhomController::class, 'tuChoiNhom']);

        // Đề tài (Topics)
        Route::get('/topics', [DeTaiController::class, 'layDanhSachDeTai']);
        Route::get('/topics/{id}', [DeTaiController::class, 'layChiTietDeTai']);
        Route::post('/topics', [DeTaiController::class, 'themDeTai']);
        Route::patch('/topics/{id}', [DeTaiController::class, 'capNhatDeTai']);
        Route::delete('/topics/{id}', [DeTaiController::class, 'xoaDeTai']);

        // Điểm số (Student Scores)
        Route::get('/student-scores', [DiemController::class, 'layDanhSachDiem']);
        Route::get('/student-scores/{id}', [DiemController::class, 'layChiTietDiem']);
        Route::patch('/student-scores/{id}', [DiemController::class, 'capNhatDiem']);

        // Phân công hướng dẫn (Assignments)
        Route::get('/assignments', [PhanCongController::class, 'layDanhSachPhanCong']);
        Route::get('/assignments/{id}', [PhanCongController::class, 'layChiTietPhanCong']);
        Route::post('/assignments', [PhanCongController::class, 'themPhanCong']);
        Route::patch('/assignments/{id}', [PhanCongController::class, 'capNhatPhanCong']);
        Route::delete('/assignments/{id}', [PhanCongController::class, 'xoaPhanCong']);
        Route::get('/teachers', [PhanCongController::class, 'layDanhSachGiangVien']);

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'layDuLieuDashboard']);

        // Đăng ký thực tập - Có công ty (Internships Confirmation)
        Route::get('/internships/confirmations', [QuanLyDangKyThucTapController::class, 'layDanhSachDaDangKy']);
        Route::get('/internships/confirmations/{id}', [QuanLyDangKyThucTapController::class, 'layChiTietTheoId']);
        Route::post('/internships/confirmations', [QuanLyDangKyThucTapController::class, 'capNhatDangKy']);
        Route::patch('/internships/confirmations/{id}', [QuanLyDangKyThucTapController::class, 'duyetCapGiay']);
        Route::delete('/internships/confirmations/{id}', [QuanLyDangKyThucTapController::class, 'xoaDangKy']);

        // Đăng ký thực tập - Chưa có công ty (Internships No Company)
        Route::get('/internships/no-company', [QuanLyDangKyThucTapController::class, 'layDanhSachNoCompany']);
        Route::get('/internships/no-company/{id}', [QuanLyDangKyThucTapController::class, 'layChiTietTheoId']);
        Route::post('/internships/no-company', [QuanLyDangKyThucTapController::class, 'capNhatDangKy']);
        Route::patch('/internships/no-company/{id}', [QuanLyDangKyThucTapController::class, 'capNhatDangKy']);
        Route::delete('/internships/no-company/{id}', [QuanLyDangKyThucTapController::class, 'xoaDangKy']);

        // Hội đồng bảo vệ (Councils)
        Route::get('/councils', [CouncilController::class, 'layDanhSachHoiDong']);
        Route::get('/councils/{id}', [CouncilController::class, 'layChiTietHoiDong']);
        Route::post('/councils', [CouncilController::class, 'themHoiDong']);
        Route::patch('/councils/{id}', [CouncilController::class, 'capNhatHoiDong']);
        Route::delete('/councils/{id}', [CouncilController::class, 'xoaHoiDong']);
    });

    // File Upload
    Route::post('v1/file-upload/upload', [UploadController::class, 'taiLenFile']);
});
