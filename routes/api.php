<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\NguoiDungController;
use App\Http\Controllers\Admin\ThongKeController;
use App\Http\Controllers\Admin\DotController;
use App\Http\Controllers\Admin\LopController;
use App\Http\Controllers\Admin\TaiLenController;
use App\Http\Controllers\Admin\CongTyController;
use App\Http\Controllers\Admin\DeTaiController;
use App\Http\Controllers\Admin\DiemSinhVienController;
use App\Http\Controllers\Admin\NhomController;
use App\Http\Controllers\Admin\PhanCongHdttController;
use App\Http\Controllers\Admin\HoiDongController;
use App\Http\Controllers\Auth\MockAuthController;

Route::post('/dang-nhap-gia-lap', [MockAuthController::class, 'dangNhap']);
Route::post('/lam-moi-token', [MockAuthController::class, 'lamMoiToken']);
Route::get('/v1/realtime/stream', [\App\Http\Controllers\RealtimeController::class, 'stream']);

Route::middleware('auth:sanctum')->group(function () {
    // Đăng xuất sẽ được xử lý bằng cách thu hồi token hiện tại
    Route::post('/dang-xuat', [MockAuthController::class, 'dangXuat']);

    // Tải tệp lên hệ thống (Dành cho mọi đối tượng đã đăng nhập)
    Route::post('/v1/file-upload/upload', [TaiLenController::class, 'upload']);
    Route::post('/private/v1/upload', [TaiLenController::class, 'upload']);

    // Quản lý đề tài (Topics) - Mở cho mọi vai trò (Admin duyệt, GVHD đề xuất, SV xem đăng ký)
    Route::get('/private/v1/topics', [DeTaiController::class, 'layDanhSach']);
    Route::get('/private/v1/topics/{id}', [DeTaiController::class, 'xemChiTiet']);
    Route::post('/private/v1/topics', [DeTaiController::class, 'themMoi']);
    Route::patch('/private/v1/topics/{id}', [DeTaiController::class, 'capNhat']);
    Route::delete('/private/v1/topics/{id}', [DeTaiController::class, 'xoa']);
    
    // Quản lý đợt học (Periods) - Cho phép mọi vai trò xem danh sách đợt học
    Route::get('/private/v1/periods', [DotController::class, 'layDanhSach']);

    Route::middleware('quyen:SINH_VIEN')->group(function () {
        Route::get('/private/v1/student/dashboard', [\App\Http\Controllers\SinhVien\TrangChuController::class, 'layThongTinTrangChu']);
        Route::get('/private/v1/student/companies', [\App\Http\Controllers\SinhVien\ThucTapController::class, 'layDanhSachCongTy']);
        Route::post('/private/v1/student/internships/declare', [\App\Http\Controllers\SinhVien\ThucTapController::class, 'khaiBaoThucTap']);
        Route::get('/private/v1/student/internships/my-request', [\App\Http\Controllers\SinhVien\ThucTapController::class, 'xemYeuCauCuaToi']);
        Route::get('/private/v1/student/thesis/my-registration', [\App\Http\Controllers\SinhVien\DeTaiController::class, 'xemDangKyCuaToi']);
        Route::post('/private/v1/student/thesis/register', [\App\Http\Controllers\SinhVien\DeTaiController::class, 'dangKyDeTai']);
        Route::post('/private/v1/student/thesis/cancel', [\App\Http\Controllers\SinhVien\DeTaiController::class, 'huyDangKy']);
        Route::get('/private/v1/student/thesis/invitations/outgoing', [\App\Http\Controllers\SinhVien\DeTaiController::class, 'xemLoiMoiDaGui']);
        Route::post('/private/v1/student/thesis/invitations/send', [\App\Http\Controllers\SinhVien\DeTaiController::class, 'guiLoiMoiNhom']);
        Route::get('/private/v1/student/thesis/invitations/incoming', [\App\Http\Controllers\SinhVien\DeTaiController::class, 'xemLoiMoiNhanDuoc']);
        Route::post('/private/v1/student/thesis/invitations/{id}/accept', [\App\Http\Controllers\SinhVien\DeTaiController::class, 'chapNhanLoiMoi']);
        Route::post('/private/v1/student/thesis/invitations/{id}/reject', [\App\Http\Controllers\SinhVien\DeTaiController::class, 'tuChoiLoiMoi']);
        Route::get('/private/v1/student/reports/tttn', [\App\Http\Controllers\SinhVien\BaoCaoController::class, 'layDanhSachBaoCaoTttn']);
        Route::post('/private/v1/student/reports/tttn', [\App\Http\Controllers\SinhVien\BaoCaoController::class, 'nopBaoCaoTttn']);
        Route::get('/private/v1/student/reports/datn', [\App\Http\Controllers\SinhVien\BaoCaoController::class, 'layDanhSachBaoCaoDatn']);
        Route::post('/private/v1/student/reports/datn', [\App\Http\Controllers\SinhVien\BaoCaoController::class, 'nopBaoCaoDatn']);
        Route::get('/private/v1/student/results', [\App\Http\Controllers\SinhVien\DiemController::class, 'layKetQuaHocTap']);
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
        Route::get('/private/v1/dashboard', [ThongKeController::class, 'getDashboardData']);

        // 4. Chức năng quản lý đợt tốt nghiệp (Periods)
        Route::get('/private/v1/periods/{id}', [DotController::class, 'xemChiTiet']);
        Route::post('/private/v1/periods', [DotController::class, 'themMoi']);
        Route::patch('/private/v1/periods/{id}', [DotController::class, 'capNhat']);
        Route::delete('/private/v1/periods/{id}', [DotController::class, 'xoa']);

        // 5. Chức năng quản lý lớp học (Classes)
        Route::get('/private/v1/classes', [LopController::class, 'layDanhSach']);
        Route::get('/private/v1/classes/{id}', [LopController::class, 'xemChiTiet']);
        Route::post('/private/v1/classes', [LopController::class, 'themMoi']);
        Route::patch('/private/v1/classes/{id}', [LopController::class, 'capNhat']);
        Route::delete('/private/v1/classes/{id}', [LopController::class, 'xoa']);

        // 6. Chức năng quản lý doanh nghiệp & thực tập (Companies & Internships)
        Route::get('/private/v1/companies', [CongTyController::class, 'layDanhSach']);
        Route::get('/private/v1/companies/{id}', [CongTyController::class, 'xemChiTiet']);
        Route::post('/private/v1/companies', [CongTyController::class, 'themMoi']);
        Route::patch('/private/v1/companies/{id}', [CongTyController::class, 'capNhat']);
        Route::delete('/private/v1/companies/{id}', [CongTyController::class, 'xoa']);

        Route::get('/private/v1/internships/confirmations', [CongTyController::class, 'layDanhSachXacNhan']);
        Route::get('/private/v1/internships/confirmations/{id}', [CongTyController::class, 'xemChiTietXacNhan']);
        Route::post('/private/v1/internships/confirmations', [CongTyController::class, 'themMoiXacNhan']);
        Route::patch('/private/v1/internships/confirmations/{id}', [CongTyController::class, 'capNhatXacNhan']);
        Route::delete('/private/v1/internships/confirmations/{id}', [CongTyController::class, 'xoaXacNhan']);

        Route::get('/private/v1/internships/no-company', [CongTyController::class, 'layDanhSachChuaThucTap']);
        Route::get('/private/v1/internships/no-company/{id}', [CongTyController::class, 'xemChiTietChuaThucTap']);

        // 7. Chức năng quản lý điểm số (Student Scores)
        Route::get('/private/v1/student-scores', [DiemSinhVienController::class, 'layDanhSach']);
        Route::get('/private/v1/student-scores/{id}', [DiemSinhVienController::class, 'xemChiTiet']);
        Route::patch('/private/v1/student-scores/{id}', [DiemSinhVienController::class, 'capNhat']);

        // 8. Chức năng quản lý nhóm (Groups)
        Route::get('/private/v1/groups', [NhomController::class, 'layDanhSach']);
        Route::get('/private/v1/groups/{id}', [NhomController::class, 'xemChiTiet']);
        Route::patch('/private/v1/groups/{id}', [NhomController::class, 'capNhat']);
        Route::delete('/private/v1/groups/{id}', [NhomController::class, 'xoa']);
        Route::post('/private/v1/groups/{id}/approve', [NhomController::class, 'approveGroup']);
        Route::post('/private/v1/groups/{id}/reject', [NhomController::class, 'rejectGroup']);

        // 9. Chức năng phân công hướng dẫn (Assignments)
        Route::get('/private/v1/assignments', [PhanCongHdttController::class, 'layDanhSach']);
        Route::get('/private/v1/assignments/{id}', [PhanCongHdttController::class, 'xemChiTiet']);
        Route::patch('/private/v1/assignments/{id}', [PhanCongHdttController::class, 'capNhat']);
        Route::get('/private/v1/teachers', [PhanCongHdttController::class, 'getTeachers']);

        // 10. Chức năng quản lý hội đồng (Councils)
        Route::get('/private/v1/councils', [HoiDongController::class, 'layDanhSach']);
        Route::get('/private/v1/councils/{id}', [HoiDongController::class, 'xemChiTiet']);
        Route::post('/private/v1/councils', [HoiDongController::class, 'themMoi']);
        Route::patch('/private/v1/councils/{id}', [HoiDongController::class, 'capNhat']);
        Route::delete('/private/v1/councils/{id}', [HoiDongController::class, 'xoa']);
    });
});
