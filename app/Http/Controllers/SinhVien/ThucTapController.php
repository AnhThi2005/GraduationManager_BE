<?php

namespace App\Http\Controllers\SinhVien;

use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use App\Models\CongTy;
use App\Models\DangKyThucTap;
use App\Models\Dot;
use App\Services\RealtimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ThucTapController extends Controller
{
    use KiemTraTrangThaiDot;

    /**
     * Tra cứu thông tin doanh nghiệp theo mã số thuế (proxy qua VietQR) để sinh viên
     * tự động điền tên công ty/địa chỉ khi khai báo, đỡ phải gõ tay và tránh sai lệch
     * dữ liệu so với đăng ký thuế thật. Không có API chính thức miễn phí từ Tổng cục
     * Thuế nên dùng dịch vụ tổng hợp cộng đồng VietQR (api.vietqr.io), có thể đổi
     * provider sau này mà không ảnh hưởng đến phía frontend vì chỉ đi qua endpoint này.
     */
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

    /**
     * Lấy danh sách doanh nghiệp đối tác dành cho sinh viên
     */
    public function layDanhSachCongTy(Request $request)
    {
        $companies = CongTy::where('trang_thai', 'HOAT_DONG')
            ->where('da_cong_bo', true)
            ->get();

        // Thời gian thực tập lấy từ mốc ngày bắt đầu/kết thúc thật của đợt (không hardcode
        // "8 tuần" — sai cho cả hệ Cao đẳng nghề 14 tuần lẫn Cao đẳng 12 tuần).
        $periodId = $request->input('periodId') ?? $request->query('periodId');
        $activePeriod = $periodId
            ? Dot::find($periodId)
            : Dot::where('loai_dot', 'TTTN')->where('trang_thai', 'DANG_MO')->first()
                ?? Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
        $duration = $activePeriod ? $activePeriod->moTaThoiGianThucTap() : '8 tuần';

        $rows = $companies->map(function ($company) use ($duration) {
            $fields = DB::table('congtylinhvuc')
                ->where('cong_ty_id', $company->cong_ty_id)
                ->pluck('ten_linh_vuc')
                ->all();

            return [
                'code' => (string) $company->cong_ty_id,
                'name' => $company->ten_cong_ty,
                'taxId' => $company->ma_so_thue ?? '',
                'field' => empty($fields) ? 'Phần mềm' : implode(', ', $fields),
                'address' => $company->dia_chi ?? 'TP.HCM',
                'mentor' => $company->nguoi_lien_he ?? 'Chưa cập nhật',
                'phone' => $company->so_dien_thoai_lh ?? 'Chưa cập nhật',
                'email' => $company->email_lien_he ?? 'Chưa cập nhật',
                'duration' => $duration,
            ];
        });

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $rows,
            ],
        ], 200);
    }

    /**
     * Sinh viên tự khai báo nơi thực tập
     */
    public function khaiBaoThucTap(Request $request)
    {
        $sinhVien = $request->user();
        if (! $sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đăng nhập.',
            ], 401);
        }

        $rules = [
            'companyName' => 'required|string|max:255',
            'taxId' => 'required|string|max:255',
            'field' => 'nullable|string|max:255',
            'position' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'mentor' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'duration' => 'nullable|string|max:255',
            'confirmPaper' => 'nullable|boolean',
            'internshipAddress' => 'nullable|string|max:255',
        ];

        if ($request->filled('email')) {
            $rules['email'] = 'string|email|max:255';
        }

        $request->validate($rules, [
            'taxId.required' => 'Mã số thuế công ty không được để trống.',
            'position.required' => 'Vị trí thực tập không được để trống.',
        ]);

        $companyName = $request->input('companyName');
        $taxId = trim((string) $request->input('taxId'));

        // Kiểm tra định dạng mã số thuế (10 hoặc 13 chữ số)
        if (! preg_match('/^[0-9]{10}([0-9]{3})?$/', $taxId)) {
            return response()->json([
                'success' => false,
                'message' => 'Mã số thuế phải gồm 10 hoặc 13 chữ số.',
            ], 422);
        }

        // Gọi API VietQR để xác định doanh nghiệp thật
        try {
            $response = Http::timeout(6)->get("https://api.vietqr.io/v2/business/{$taxId}");
            $body = $response->json();

            if (! $response->successful() || empty($body['data'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mã số thuế này không tồn tại trên hệ thống Đăng ký doanh nghiệp quốc gia.',
                ], 422);
            }

            // Ghi đè tên công ty và địa chỉ bằng thông tin chuẩn từ đăng ký thuế để tránh sinh viên nhập sai
            $companyName = $body['data']['name'];
            if (! empty($body['data']['address'])) {
                $request->merge(['address' => $body['data']['address']]);
            }
        } catch (\Throwable $e) {
            // Khi dịch vụ ngoài gặp sự cố kết nối, ghi log nhưng cho phép đi tiếp (fallback)
            // để tránh nghẽn luồng đăng ký của sinh viên trong trường hợp API ngoài bị lỗi
            \Illuminate\Support\Facades\Log::warning("VietQR API error during declaration: " . $e->getMessage());
        }

        // Tìm hoặc tạo mới công ty ở trạng thái CHO_DUYET (chờ duyệt), khớp theo mã số thuế
        // (giống cách admin khai báo hộ) để tránh tạo trùng công ty do lệch chính tả tên gọi
        $company = CongTy::where('ma_so_thue', $taxId)->first();

        if (! $company) {
            $company = CongTy::create([
                'ten_cong_ty' => $companyName,
                'dia_chi' => $request->input('address') ?? '',
                'ma_so_thue' => $taxId,
                'nguoi_lien_he' => $request->input('mentor') ?? '',
                'email_lien_he' => $request->input('email') ?? '',
                'so_dien_thoai_lh' => $request->input('phone') ?? '',
                'trang_thai' => 'CHO_DUYET',
            ]);

            // Thêm lĩnh vực hoạt động
            if ($request->filled('field')) {
                DB::table('congtylinhvuc')->insert([
                    'cong_ty_id' => $company->cong_ty_id,
                    'ten_linh_vuc' => $request->input('field'),
                ]);
            }
        }

        // Lấy đợt học được truyền lên từ client, hoặc tìm đợt TTTN đang hoạt động của sinh viên
        $periodId = $request->input('periodId') ?? $request->query('periodId');
        if ($periodId) {
            $activePeriod = Dot::find($periodId);
        } else {
            $lopId = $sinhVien->lop_id;
            $activePeriod = Dot::where('loai_dot', 'TTTN')
                ->where('trang_thai', '!=', 'DA_DONG')
                ->whereHas('lops', function ($q) use ($lopId) {
                    $q->where('lop.lop_id', $lopId);
                })->orderBy('dot_id', 'desc')->first();

            // Fallback sang đợt TTTN bất kỳ đang mở hoặc đợt mới nhất
            if (! $activePeriod) {
                $activePeriod = Dot::where('loai_dot', 'TTTN')->where('trang_thai', 'DANG_MO')->first()
                    ?? Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
            }
        }

        if (! $activePeriod) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đợt thực tập tốt nghiệp nào đang mở để khai báo!',
            ], 400);
        }

        if ($resp = $this->chanNeuSinhVienKhongDuocSua($activePeriod)) {
            return $resp;
        }

        // Chặn khai báo sai đợt: lớp của sinh viên phải được gắn vào đợt này,
        // hoặc sinh viên được thêm thủ công vào đợt (ví dụ rớt đợt trước)
        if (! $activePeriod->hasStudent($sinhVien->sinh_vien_id)) {
            return response()->json([
                'success' => false,
                'message' => "Bạn không thuộc đợt \"{$activePeriod->ten_dot}\" nên không thể khai báo thực tập cho đợt này. Vui lòng liên hệ quản trị viên nếu đây là nhầm lẫn.",
            ], 422);
        }

        // Kiểm tra xem đã có đăng ký được duyệt hoặc chờ cấp giấy trong đợt này chưa
        $daCoDangKyDuyet = DangKyThucTap::where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->whereIn('trang_thai', ['DA_DUYET', 'CHO_CAP_GIAY'])
            ->exists();

        if ($daCoDangKyDuyet) {
            return response()->json([
                'success' => false,
                'message' => 'Nơi thực tập của bạn trong đợt này đã được duyệt hoặc đang chờ cấp giấy giới thiệu. Không thể tự ý khai báo lại!',
            ], 400);
        }

        // Xóa đăng ký cũ trong đợt này nếu có
        DangKyThucTap::where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->delete();

        // Chỉ lưu địa chỉ thực tập nếu sinh viên yêu cầu cấp giấy giới thiệu
        $confirmPaper = (bool) $request->input('confirmPaper');
        $internshipAddress = $confirmPaper
            ? ($request->input('internshipAddress') ?: ($request->input('address') ?: 'Địa chỉ công ty'))
            : null;

        // Tất cả các khai báo mới từ sinh viên đều bắt đầu ở trạng thái chờ duyệt (CHO_DUYET)
        // để Admin kiểm tra tính phù hợp của vị trí thực tập với ngành học.
        $trangThaiReg = 'CHO_DUYET';

        // Tạo yêu cầu khai báo mới ở trạng thái chờ duyệt hoặc đã duyệt tương ứng
        $reg = DangKyThucTap::create([
            'sinh_vien_id' => $sinhVien->sinh_vien_id,
            'dot_id' => $activePeriod->dot_id,
            'cong_ty_id' => $company->cong_ty_id,
            'nguoi_huong_dan' => $request->input('mentor') ?? '',
            'sdt_huong_dan' => $request->input('phone') ?? '',
            'vi_tri_thuc_tap' => $request->input('field') ?? '',
            'vi_tri_cong_viec' => $request->input('position') ?? '',
            'thoi_gian_thuc_tap' => $request->input('duration') ?: $activePeriod->moTaThoiGianThucTap(),
            'dia_chi_thuc_tap' => $internshipAddress,
            'trang_thai' => $trangThaiReg,
            'ngay_dang_ky' => now(),
        ]);

        // Broadcast thông báo realtime cho admin
        RealtimeService::broadcast('notification', [
            'title' => 'Đăng ký thực tập mới',
            'message' => 'Sinh viên '.$sinhVien->ho_ten.' vừa khai báo tự thực tập tại '.$company->ten_cong_ty,
            'type' => 'internship_declared',
            'payload' => [
                'id' => $reg->dang_ky_id,
                'studentName' => $sinhVien->ho_ten,
                'companyName' => $company->ten_cong_ty,
            ],
        ]);

        return response()->json([
            'code' => 201,
            'message' => 'Khai báo thực tập thành công!',
            'results' => [
                'object' => [
                    'id' => (string) $reg->dang_ky_id,
                    'companyName' => $company->ten_cong_ty,
                    'status' => 'pending',
                    'confirmPaper' => $confirmPaper,
                ],
            ],
        ], 201);
    }

    /**
     * Xem hồ sơ khai báo thực tập của sinh viên đăng nhập
     */
    public function xemYeuCauCuaToi(Request $request)
    {
        $sinhVien = $request->user();
        if (! $sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đăng nhập.',
            ], 401);
        }

        $periodId = $request->query('periodId') ?? $request->input('periodId');
        if ($periodId) {
            $activePeriod = Dot::find($periodId);
        } else {
            $lopId = $sinhVien->lop_id;
            $activePeriod = Dot::where('loai_dot', 'TTTN')
                ->whereHas('lops', function ($q) use ($lopId) {
                    $q->where('lop.lop_id', $lopId);
                })
                ->where('trang_thai', '!=', 'DA_DONG')
                ->orderBy('dot_id', 'desc')
                ->first();
            if (! $activePeriod) {
                $activePeriod = Dot::where('loai_dot', 'TTTN')
                    ->whereHas('lops', function ($q) use ($lopId) {
                        $q->where('lop.lop_id', $lopId);
                    })->orderBy('dot_id', 'desc')->first();
            }
        }

        if (! $activePeriod) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => null,
                ],
            ]);
        }

        $reg = DangKyThucTap::with('congTy')
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->first();

        if (! $reg) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => null,
                ],
            ]);
        }

        $status = 'pending';
        if ($reg->trang_thai === 'DA_DUYET') {
            $status = 'approved';
        } elseif ($reg->trang_thai === 'TU_CHOI') {
            $status = 'rejected';
        } elseif ($reg->trang_thai === 'CHO_CAP_GIAY') {
            $status = 'cho_cap_giay';
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => [
                    'id' => (string) $reg->dang_ky_id,
                    'companyName' => $reg->congTy ? $reg->congTy->ten_cong_ty : '',
                    'status' => $status,
                    'confirmPaper' => ! empty($reg->dia_chi_thuc_tap),
                    'internshipAddress' => $reg->dia_chi_thuc_tap,
                    'mentor' => $reg->nguoi_huong_dan,
                    'phone' => $reg->sdt_huong_dan,
                    'position' => $reg->vi_tri_cong_viec,
                    'duration' => $reg->thoi_gian_thuc_tap,
                ],
            ],
        ]);
    }
}
