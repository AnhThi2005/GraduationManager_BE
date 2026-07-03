<?php

namespace App\Http\Controllers\SinhVien;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CongTy;
use App\Models\DangKyThucTap;
use App\Models\SinhVien;
use App\Models\Dot;
use Illuminate\Support\Facades\DB;
use App\Services\RealtimeService;

class ThucTapController extends Controller
{
    /**
     * Lấy danh sách doanh nghiệp đối tác dành cho sinh viên
     */
    public function layDanhSachCongTy(Request $request)
    {
        $companies = CongTy::where('trang_thai', 'HOAT_DONG')->get();

        $rows = $companies->map(function ($company) {
            $fields = DB::table('congtylinhvuc')
                ->where('cong_ty_id', $company->cong_ty_id)
                ->pluck('ten_linh_vuc')
                ->all();

            $registeredCount = DangKyThucTap::where('cong_ty_id', $company->cong_ty_id)
                ->where('trang_thai', 'DA_DUYET')
                ->count();

            $maxSlots = 15;
            $remainingSlots = max(0, $maxSlots - $registeredCount);

            return [
                'code' => (string)$company->cong_ty_id,
                'name' => $company->ten_cong_ty,
                'field' => empty($fields) ? 'Phần mềm' : implode(', ', $fields),
                'address' => $company->dia_chi ?? 'TP.HCM',
                'mentor' => $company->nguoi_lien_he ?? 'Chưa cập nhật',
                'phone' => $company->so_dien_thoai_lh ?? 'Chưa cập nhật',
                'email' => $company->email_lien_he ?? 'Chưa cập nhật',
                'slots' => $remainingSlots,
                'duration' => '8 tuần',
                'status' => $remainingSlots > 0 ? 'Còn slot' : 'Hết slot',
                'highlights' => ['Có mentor hỗ trợ', 'Môi trường tốt', 'Hỗ trợ đóng dấu']
            ];
        });

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $rows
            ]
        ], 200);
    }

    /**
     * Sinh viên tự khai báo nơi thực tập
     */
    public function khaiBaoThucTap(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đăng nhập.'
            ], 401);
        }

        $rules = [
            'companyName' => 'required|string|max:255',
            'field' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'mentor' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'duration' => 'nullable|string|max:255',
            'confirmPaper' => 'nullable|boolean',
            'internshipAddress' => 'nullable|string|max:255'
        ];

        if ($request->filled('email')) {
            $rules['email'] = 'string|email|max:255';
        }

        $request->validate($rules);

        $companyName = $request->input('companyName');

        // Tìm hoặc tạo mới công ty ở trạng thái CHO_DUYET (chờ duyệt)
        $company = CongTy::where('ten_cong_ty', $companyName)->first();

        if (!$company) {
            $company = CongTy::create([
                'ten_cong_ty' => $companyName,
                'dia_chi' => $request->input('address') ?? '',
                'nguoi_lien_he' => $request->input('mentor') ?? '',
                'email_lien_he' => $request->input('email') ?? '',
                'so_dien_thoai_lh' => $request->input('phone') ?? '',
                'trang_thai' => 'CHO_DUYET'
            ]);

            // Thêm lĩnh vực hoạt động
            if ($request->filled('field')) {
                DB::table('congtylinhvuc')->insert([
                    'cong_ty_id' => $company->cong_ty_id,
                    'ten_linh_vuc' => $request->input('field')
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
                ->whereHas('lops', function($q) use ($lopId) {
                    $q->where('lop.lop_id', $lopId);
                })->orderBy('dot_id', 'desc')->first();

            // Fallback sang đợt TTTN bất kỳ đang mở hoặc đợt mới nhất
            if (!$activePeriod) {
                $activePeriod = Dot::where('loai_dot', 'TTTN')->where('trang_thai', 'DANG_MO')->first()
                    ?? Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
            }
        }

        if (!$activePeriod) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đợt thực tập tốt nghiệp nào đang mở để khai báo!'
            ], 400);
        }

        // Chặn khai báo sai đợt: lớp của sinh viên phải được gắn vào đợt này,
        // hoặc sinh viên được thêm thủ công vào đợt (ví dụ rớt đợt trước)
        if (!$activePeriod->hasStudent($sinhVien->sinh_vien_id)) {
            return response()->json([
                'success' => false,
                'message' => "Bạn không thuộc đợt \"{$activePeriod->ten_dot}\" nên không thể khai báo thực tập cho đợt này. Vui lòng liên hệ quản trị viên nếu đây là nhầm lẫn."
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
                'message' => 'Nơi thực tập của bạn trong đợt này đã được duyệt hoặc đang chờ cấp giấy giới thiệu. Không thể tự ý khai báo lại!'
            ], 400);
        }

        // Xóa đăng ký cũ trong đợt này nếu có
        DangKyThucTap::where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->delete();

        // Chỉ lưu địa chỉ thực tập nếu sinh viên yêu cầu cấp giấy giới thiệu
        $confirmPaper = (bool)$request->input('confirmPaper');
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
            'thoi_gian_thuc_tap' => $request->input('duration') ?? '8 tuần',
            'dia_chi_thuc_tap' => $internshipAddress,
            'trang_thai' => $trangThaiReg
        ]);

        // Broadcast thông báo realtime cho admin
        RealtimeService::broadcast('notification', [
            'title' => 'Đăng ký thực tập mới',
            'message' => 'Sinh viên ' . $sinhVien->ho_ten . ' vừa khai báo tự thực tập tại ' . $company->ten_cong_ty,
            'type' => 'internship_declared',
            'payload' => [
                'id' => $reg->dang_ky_id,
                'studentName' => $sinhVien->ho_ten,
                'companyName' => $company->ten_cong_ty
            ]
        ]);

        return response()->json([
            'code' => 201,
            'message' => 'Khai báo thực tập thành công!',
            'results' => [
                'object' => [
                    'id' => (string)$reg->dang_ky_id,
                    'companyName' => $company->ten_cong_ty,
                    'status' => 'pending',
                    'confirmPaper' => $confirmPaper
                ]
            ]
        ], 201);
    }

    /**
     * Xem hồ sơ khai báo thực tập của sinh viên đăng nhập
     */
    public function xemYeuCauCuaToi(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đăng nhập.'
            ], 401);
        }

        $periodId = $request->query('periodId') ?? $request->input('periodId');
        if ($periodId) {
            $activePeriod = Dot::find($periodId);
        } else {
            $lopId = $sinhVien->lop_id;
            $activePeriod = Dot::where('loai_dot', 'TTTN')
                ->whereHas('lops', function($q) use ($lopId) {
                    $q->where('lop.lop_id', $lopId);
                })->orderBy('dot_id', 'desc')->first();
        }

        if (!$activePeriod) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => null
                ]
            ]);
        }

        $reg = DangKyThucTap::with('congTy')
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->first();

        if (!$reg) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => null
                ]
            ]);
        }

        $status = 'pending';
        if ($reg->trang_thai === 'DA_DUYET') {
            $status = 'approved';
        } else if ($reg->trang_thai === 'TU_CHOI') {
            $status = 'rejected';
        } else if ($reg->trang_thai === 'CHO_CAP_GIAY') {
            $status = 'cho_cap_giay';
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => [
                    'id' => (string)$reg->dang_ky_id,
                    'companyName' => $reg->congTy ? $reg->congTy->ten_cong_ty : '',
                    'status' => $status,
                    'confirmPaper' => !empty($reg->dia_chi_thuc_tap),
                    'internshipAddress' => $reg->dia_chi_thuc_tap,
                    'mentor' => $reg->nguoi_huong_dan,
                    'phone' => $reg->sdt_huong_dan,
                    'duration' => $reg->thoi_gian_thuc_tap
                ]
            ]
        ]);
    }
}
