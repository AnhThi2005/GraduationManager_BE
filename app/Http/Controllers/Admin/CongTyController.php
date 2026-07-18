<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuanLySinhVienThucTap\ThemDoanhNghiepRequest;
use App\Http\Requests\Admin\QuanLySinhVienThucTap\ThemMoiXacNhanRequest;
use App\Models\DangKyThucTap;
use App\Models\Dot;
use App\Models\LichSuHoatDong;
use App\Services\CongTyService;
use App\Services\NguoiDungService;
use App\Services\RealtimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CongTyController extends Controller
{
    use KiemTraTrangThaiDot;

    protected $congTyService;

    protected $nguoiDungService;

    public function __construct(CongTyService $congTyService, NguoiDungService $nguoiDungService)
    {
        $this->congTyService = $congTyService;
        $this->nguoiDungService = $nguoiDungService;
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
                    'total' => $res['total'],
                ],
            ],
        ], 200);
    }

    public function xemChiTiet(Request $request, $id)
    {
        $company = $this->congTyService->getCompanyDetail($id);
        if (! $company) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy doanh nghiệp này!',
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $company,
            ],
        ], 200);
    }

    public function themMoi(ThemDoanhNghiepRequest $request)
    {

        $company = $this->congTyService->createCompany($request->all());

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $company,
            ],
        ], 200);
    }

    public function capNhat(Request $request, $id)
    {
        $company = $this->congTyService->updateCompany($id, $request->all());
        if (! $company) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy doanh nghiệp này để cập nhật!',
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $company,
            ],
        ], 200);
    }

    public function xoa(Request $request, $id)
    {
        try {
            $success = $this->congTyService->deleteCompany($id);
            if (! $success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy doanh nghiệp này để xóa!',
                ], 404);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Xóa doanh nghiệp thành công!',
        ], 200);
    }

    /**
     * Công bố danh sách công ty: công bố mọi công ty đang hoạt động mà chưa từng công bố,
     * để sinh viên bắt đầu thấy được (hiển thị vĩnh viễn cho mọi đợt sau).
     */
    public function congBo(Request $request)
    {
        $publishedCount = $this->congTyService->publishCompanies();

        return response()->json([
            'success' => true,
            'message' => $publishedCount > 0
                ? "Đã công bố thêm {$publishedCount} doanh nghiệp mới!"
                : 'Không có doanh nghiệp mới nào cần công bố.',
            'results' => [
                'publishedCount' => $publishedCount,
            ],
        ], 200);
    }

    // ==========================================================
    // 2. INTERNSHIP CONFIRMATIONS ENDPOINTS
    // ==========================================================

    public function layDanhSachXacNhan(Request $request)
    {
        $filters = [
            'periodId' => $request->query('periodId'),
        ];

        $res = $this->congTyService->getListConfirmationRequest($filters);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total'],
                ],
            ],
        ], 200);
    }

    public function layDanhSachKhaiBao(Request $request)
    {
        $filters = [
            'periodId' => $request->query('periodId'),
        ];

        $res = $this->congTyService->getListDeclarations($filters);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total'],
                ],
            ],
        ], 200);
    }

    public function xemChiTietXacNhan(Request $request, $id)
    {
        $reg = $this->congTyService->getConfirmationRequestDetail($id);
        if (! $reg) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hồ sơ khai báo này!',
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $reg,
            ],
        ], 200);
    }

    public function themMoiXacNhan(ThemMoiXacNhanRequest $request)
    {

        $periodId = $request->query('periodId');

        $dot = $periodId
            ? Dot::find($periodId)
            : Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
        if ($resp = $this->chanNeuDotDaDong($dot)) {
            return $resp;
        }

        try {
            $reg = $this->congTyService->createConfirmationRequest($request->all(), $periodId);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        if (! $reg) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên có MSSV này để khai báo!',
            ], 400);
        }

        RealtimeService::broadcast('notification', [
            'title' => 'Đăng ký thực tập mới',
            'message' => 'Sinh viên '.($reg['studentName'] ?? 'SV').' vừa khai báo thực tập tại '.($reg['companyName'] ?? 'công ty'),
            'type' => 'internship_declared',
            'payload' => $reg,
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $reg,
            ],
        ], 200);
    }

    public function capNhatXacNhan(Request $request, $id)
    {
        $existing = DangKyThucTap::find($id);
        if ($resp = $this->chanNeuDotDaDong($existing?->dot)) {
            return $resp;
        }

        $reg = $this->congTyService->updateConfirmationRequest($id, $request->all());
        if (! $reg) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hồ sơ khai báo này để cập nhật!',
            ], 404);
        }

        $admin = $request->user();
        LichSuHoatDong::ghiLog(
            'DUYET_TTTN',
            'Admin '.($admin ? $admin->ho_ten : 'Hệ thống').' đã cập nhật trạng thái hồ sơ khai báo thực tập của sinh viên '.($reg['studentName'] ?? '').'.',
            $existing->sinh_vien_id,
            $reg['studentCode'] ?? null,
            null,
            'admin',
            $admin ? $admin->ho_ten : 'Hệ thống',
            ['old_status' => $existing->trang_thai, 'new_status' => $reg['status'] ?? null]
        );

        RealtimeService::broadcast('notification', [
            'title' => 'Cập nhật yêu cầu thực tập',
            'message' => 'Hồ sơ thực tập của sinh viên '.($reg['studentName'] ?? '').' đã được cập nhật',
            'type' => 'internship_updated',
            'payload' => $reg,
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $reg,
            ],
        ], 200);
    }

    public function xoaXacNhan(Request $request, $id)
    {
        $existing = DangKyThucTap::find($id);
        if (! $existing) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hồ sơ khai báo này để xóa!',
            ], 404);
        }

        if ($resp = $this->chanNeuDotDaDong($existing->dot)) {
            return $resp;
        }

        if ($existing->trang_thai === 'DA_DUYET') {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa khai báo thực tập vì đã được duyệt và đang thực hiện!',
            ], 400);
        }

        // Kiểm tra ràng buộc:
        // 1. Sinh viên đã nộp báo cáo tiến độ
        $hasReports = DB::table('baocaotiendo')
            ->where('sinh_vien_id', $existing->sinh_vien_id)
            ->where('dot_id', $existing->dot_id)
            ->exists();
        if ($hasReports) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa khai báo thực tập vì sinh viên đã nộp báo cáo tiến độ!',
            ], 400);
        }

        // 3. Sinh viên đã có điểm thực tập
        $hasGrade = DB::table('diemthuctap')
            ->where('sinh_vien_id', $existing->sinh_vien_id)
            ->where('dot_id', $existing->dot_id)
            ->whereNotNull('diem_so')
            ->exists();
        if ($hasGrade) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa khai báo thực tập vì sinh viên đã có điểm thực tập tốt nghiệp!',
            ], 400);
        }

        $success = $this->congTyService->deleteConfirmationRequest($id);
        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hồ sơ khai báo này để xóa!',
            ], 404);
        }

        $admin = $request->user();
        LichSuHoatDong::ghiLog(
            'XOA_TTTN',
            'Admin '.($admin ? $admin->ho_ten : 'Hệ thống')." đã xóa hồ sơ khai báo thực tập của sinh viên mang ID {$existing->sinh_vien_id}.",
            $existing->sinh_vien_id,
            null,
            null,
            'admin',
            $admin ? $admin->ho_ten : 'Hệ thống',
            ['internship_id' => $id]
        );

        return response()->json([
            'success' => true,
            'message' => 'Xóa yêu cầu khai báo thực tập thành công!',
        ], 200);
    }

    // ==========================================================
    // 3. NO COMPANY STUDENTS ENDPOINTS
    // ==========================================================

    public function layDanhSachChuaThucTap(Request $request)
    {
        $filters = [
            'periodId' => $request->query('periodId'),
        ];

        $res = $this->congTyService->getListNoCompanyStudent($filters);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total'],
                ],
            ],
        ], 200);
    }

    public function xemChiTietChuaThucTap(Request $request, $id)
    {
        $periodId = $request->query('periodId') ?? $request->input('periodId');
        $sv = $this->congTyService->getNoCompanyStudentDetail($id, $periodId);
        if (! $sv) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên này!',
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $sv,
            ],
        ], 200);
    }

    public function capNhatChuaThucTap(Request $request, $id)
    {
        $status = $request->input('status'); // 'not_registered' | 'searching' | 'has_company'
        $periodId = $request->query('periodId') ?? $request->input('periodId');

        if ($periodId && ($resp = $this->chanNeuDotDaDong(Dot::find($periodId)))) {
            return $resp;
        }

        $res = $this->congTyService->updateNoCompanyStudentStatus($id, $status, $periodId);
        if (! $res) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy thông tin sinh viên!',
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $res,
            ],
        ], 200);
    }

    /**
     * Xóa mềm sinh viên chưa có nơi thực tập: khóa tài khoản sinh viên
     * (danh sách này là view tính động từ SinhVien + DangKyThucTap, không phải bảng riêng
     * nên "xóa" ở đây tái sử dụng cơ chế khóa tài khoản của NguoiDungController::xoaNguoiDung)
     */
    public function xoaChuaThucTap(Request $request, $id)
    {
        $sv = $this->nguoiDungService->doiTrangThaiSinhVien($id, 0);
        if (! $sv) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên này!',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Khóa tài khoản sinh viên thành công!',
        ], 200);
    }

    public function traCuuMaSoThue(Request $request)
    {
        $taxId = trim((string) $request->query('taxId'));

        if (! preg_match('/^[0-9]{10}([0-9]{3})?$/', $taxId)) {
            return response()->json([
                'success' => false,
                'message' => 'Mã số thuế phải gồm 10 hoặc 13 chữ số.',
            ], 422);
        }

        try {
            $response = Http::timeout(6)->get("https://api.vietqr.io/v2/business/{$taxId}");
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dịch vụ tra cứu mã số thuế tạm thời không khả dụng, vui lòng nhập thủ công.',
            ], 503);
        }

        $body = $response->json();

        if (! $response->successful() || empty($body['data'])) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy doanh nghiệp với mã số thuế này.',
            ], 404);
        }

        $data = $body['data'];

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => [
                    'name' => $data['name'] ?? '',
                    'address' => $data['address'] ?? '',
                ],
            ],
        ]);
    }
}
