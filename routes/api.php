<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NguoiDungController;
use App\Http\Controllers\Auth\MockAuthController;

Route::post('/dang-nhap-gia-lap', [MockAuthController::class, 'dangNhap']);
Route::post('/lam-moi-token', [MockAuthController::class, 'lamMoiToken']);

Route::middleware('auth:sanctum')->group(function () {
    // Đăng xuất sẽ được xử lý bằng cách thu hồi token hiện tại
    Route::post('/dang-xuat', [MockAuthController::class, 'dangXuat']);

    Route::middleware('quyen:SINH_VIEN')->prefix('sinh-vien')->group(function () {
        // Tuyến đường viết API cho sinh viên sau này
    });

    Route::middleware('quyen:GIANG_VIEN')->prefix('giang-vien')->group(function () {
        // Tuyến đường viết API cho giảng viên sau này
    });

    Route::middleware('quyen:ADMIN')->prefix('admin')->group(function () {
        // 1. Chức năng quản lý người dùng
        // Quản lý sinh viên
        Route::get('/sinh-vien', [NguoiDungController::class, 'layDanhSachSinhVien']);
        Route::post('/sinh-vien', [NguoiDungController::class, 'themSinhVien']);
        Route::put('/sinh-vien/{id}', [NguoiDungController::class, 'capNhatSinhVien']);
        Route::patch('/sinh-vien/{id}/khoa-tai-khoan', [NguoiDungController::class, 'khoaTaiKhoanSinhVien']);

        // Quản lý giảng viên
        Route::get('/giang-vien', [NguoiDungController::class, 'layDanhSachGiangVien']);
        Route::post('/giang-vien', [NguoiDungController::class, 'themGiangVien']);
        Route::put('/giang-vien/{id}', [NguoiDungController::class, 'capNhatGiangVien']);
        Route::patch('/giang-vien/{id}/khoa-tai-khoan', [NguoiDungController::class, 'khoaTaiKhoanGiangVien']);

        // 2. Chức năng quản lý công ty thực tập
    });
});