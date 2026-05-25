<?php

use Illuminate\Support\Facades\Route;
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
        // Tuyến đường viết API cho admin sau này
    });
});