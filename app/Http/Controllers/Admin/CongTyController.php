<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CongTyService;
use App\Http\Requests\Admin\QuanLySinhVienThucTap\ThemDoanhNghiepRequest;
use App\Http\Requests\Admin\QuanLySinhVienThucTap\ThemMoiXacNhanRequest;

class CongTyController extends Controller
{
    protected $congTyService;

    public function __construct(CongTyService $congTyService)
    {
        $this->congTyService = $congTyService;
    }

    // ==========================================================
    // 1. DOANH NGHIỆP ENDPOINTS
    // ==========================================================

    public function layDanhSach(Request $request)
    {
        $res = $this->congTyService->getListCompany();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total']
                ]
            ]
        ], 200);
    }

    public function xemChiTiet(Request $request, $id)
    {
        $company = $this->congTyService->getCompanyDetail($id);
        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy doanh nghiệp này!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $company
            ]
        ], 200);
    }

    public function themMoi(ThemDoanhNghiepRequest $request)
    {

        $company = $this->congTyService->createCompany($request->all());

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $company
            ]
        ], 200);
    }

    public function capNhat(Request $request, $id)
    {
        $company = $this->congTyService->updateCompany($id, $request->all());
        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy doanh nghiệp này để cập nhật!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $company
            ]
        ], 200);
    }

    public function xoa(Request $request, $id)
    {
        $success = $this->congTyService->deleteCompany($id);
        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy doanh nghiệp này để xóa!'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Xóa doanh nghiệp thành công!'
        ], 200);
    }

    // ==========================================================
    // 2. INTERNSHIP CONFIRMATIONS ENDPOINTS
    // ==========================================================

    public function layDanhSachXacNhan(Request $request)
    {
        $filters = [
            'periodId' => $request->query('periodId')
        ];

        $res = $this->congTyService->getListConfirmationRequest($filters);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total']
                ]
            ]
        ], 200);
    }

    public function xemChiTietXacNhan(Request $request, $id)
    {
        $reg = $this->congTyService->getConfirmationRequestDetail($id);
        if (!$reg) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hồ sơ khai báo này!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $reg
            ]
        ], 200);
    }

    public function themMoiXacNhan(ThemMoiXacNhanRequest $request)
    {

        $periodId = $request->query('periodId');

        $reg = $this->congTyService->createConfirmationRequest($request->all(), $periodId);
        if (!$reg) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên có MSSV này để khai báo!'
            ], 400);
        }

        \App\Services\RealtimeService::broadcast('notification', [
            'title' => 'Đăng ký thực tập mới',
            'message' => 'Sinh viên ' . ($reg['studentName'] ?? 'SV') . ' vừa khai báo thực tập tại ' . ($reg['companyName'] ?? 'công ty'),
            'type' => 'internship_declared',
            'payload' => $reg
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $reg
            ]
        ], 200);
    }

    public function capNhatXacNhan(Request $request, $id)
    {
        $reg = $this->congTyService->updateConfirmationRequest($id, $request->all());
        if (!$reg) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hồ sơ khai báo này để cập nhật!'
            ], 404);
        }

        \App\Services\RealtimeService::broadcast('notification', [
            'title' => 'Cập nhật yêu cầu thực tập',
            'message' => 'Hồ sơ thực tập của sinh viên ' . ($reg['studentName'] ?? '') . ' đã được cập nhật',
            'type' => 'internship_updated',
            'payload' => $reg
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $reg
            ]
        ], 200);
    }

    public function xoaXacNhan(Request $request, $id)
    {
        $success = $this->congTyService->deleteConfirmationRequest($id);
        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hồ sơ khai báo này để xóa!'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Xóa yêu cầu khai báo thực tập thành công!'
        ], 200);
    }

    // ==========================================================
    // 3. NO COMPANY STUDENTS ENDPOINTS
    // ==========================================================

    public function layDanhSachChuaThucTap(Request $request)
    {
        $filters = [
            'periodId' => $request->query('periodId')
        ];

        $res = $this->congTyService->getListNoCompanyStudent($filters);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total']
                ]
            ]
        ], 200);
    }

    public function xemChiTietChuaThucTap(Request $request, $id)
    {
        $sv = $this->congTyService->getNoCompanyStudentDetail($id);
        if (!$sv) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên này!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $sv
            ]
        ], 200);
    }

    public function capNhatChuaThucTap(Request $request, $id)
    {
        $status = $request->input('status'); // 'not_registered' | 'searching' | 'has_company'

        $res = $this->congTyService->updateNoCompanyStudentStatus($id, $status);
        if (!$res) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy thông tin sinh viên!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $res
            ]
        ], 200);
    }
}
