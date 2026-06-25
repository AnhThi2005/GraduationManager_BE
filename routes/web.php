<?php

use Illuminate\Support\Facades\Route;
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

Route::get('/', function () {
    return view('welcome');
});

// Fallback routes for frontend requests missing the /api prefix
Route::middleware([
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'auth:sanctum',
    'quyen:ADMIN'
])->group(function () {
    Route::get('/private/v1/dashboard', [ThongKeController::class, 'getDashboardData']);
    Route::get('/private/v1/periods/{id}', [DotController::class, 'xemChiTiet']);
    Route::post('/private/v1/periods', [DotController::class, 'themMoi']);
    Route::patch('/private/v1/periods/{id}', [DotController::class, 'capNhat']);
    Route::delete('/private/v1/periods/{id}', [DotController::class, 'xoa']);

    Route::get('/private/v1/classes', [LopController::class, 'layDanhSach']);
    Route::get('/private/v1/classes/{id}', [LopController::class, 'xemChiTiet']);
    Route::post('/private/v1/classes', [LopController::class, 'themMoi']);
    Route::patch('/private/v1/classes/{id}', [LopController::class, 'capNhat']);
    Route::delete('/private/v1/classes/{id}', [LopController::class, 'xoa']);

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

Route::middleware([
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'auth:sanctum',
    'quyen:SINH_VIEN'
])->group(function () {
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

// Fallback upload routes (requires authentication, open to all roles)
Route::middleware([
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'auth:sanctum'
])->group(function () {
    Route::post('/v1/file-upload/upload', [TaiLenController::class, 'upload']);
    Route::post('/private/v1/upload', [TaiLenController::class, 'upload']);

    Route::get('/private/v1/topics', [DeTaiController::class, 'layDanhSach']);
    Route::get('/private/v1/topics/{id}', [DeTaiController::class, 'xemChiTiet']);
    Route::post('/private/v1/topics', [DeTaiController::class, 'themMoi']);
    Route::patch('/private/v1/topics/{id}', [DeTaiController::class, 'capNhat']);
    Route::delete('/private/v1/topics/{id}', [DeTaiController::class, 'xoa']);

    Route::get('/private/v1/periods', [\App\Http\Controllers\Admin\DotController::class, 'layDanhSach']);
});

Route::get('/v1/realtime/stream', [\App\Http\Controllers\RealtimeController::class, 'stream']);
