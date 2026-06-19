<?php

use Illuminate\Support\Facades\Route;
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

Route::get('/', function () {
    return view('welcome');
});

// Fallback routes for frontend requests missing the /api prefix
Route::middleware([
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'auth:sanctum',
    'quyen:ADMIN'
])->group(function () {
    Route::get('/private/v1/dashboard', [DashboardController::class, 'getDashboardData']);
    Route::get('/private/v1/periods', [PeriodController::class, 'layDanhSach']);
    Route::get('/private/v1/periods/{id}', [PeriodController::class, 'xemChiTiet']);
    Route::post('/private/v1/periods', [PeriodController::class, 'themMoi']);
    Route::patch('/private/v1/periods/{id}', [PeriodController::class, 'capNhat']);
    Route::delete('/private/v1/periods/{id}', [PeriodController::class, 'xoa']);

    Route::get('/private/v1/classes', [ClassController::class, 'layDanhSach']);
    Route::get('/private/v1/classes/{id}', [ClassController::class, 'xemChiTiet']);
    Route::post('/private/v1/classes', [ClassController::class, 'themMoi']);
    Route::patch('/private/v1/classes/{id}', [ClassController::class, 'capNhat']);
    Route::delete('/private/v1/classes/{id}', [ClassController::class, 'xoa']);

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

// Fallback upload routes (requires authentication, open to all roles)
Route::middleware([
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'auth:sanctum'
])->group(function () {
    Route::post('/v1/file-upload/upload', [UploadController::class, 'upload']);
    Route::post('/private/v1/upload', [UploadController::class, 'upload']);

    Route::get('/private/v1/topics', [TopicController::class, 'layDanhSach']);
    Route::get('/private/v1/topics/{id}', [TopicController::class, 'xemChiTiet']);
    Route::post('/private/v1/topics', [TopicController::class, 'themMoi']);
    Route::patch('/private/v1/topics/{id}', [TopicController::class, 'capNhat']);
    Route::delete('/private/v1/topics/{id}', [TopicController::class, 'xoa']);
});

Route::get('/v1/realtime/stream', [\App\Http\Controllers\RealtimeController::class, 'stream']);
