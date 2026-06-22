<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SinhVien;
use App\Models\GiangVien;
use App\Models\Lop;
use App\Http\Requests\Admin\LocSinhVienRequest;
use App\Http\Requests\Admin\LocGiangVienRequest;
use App\Http\Requests\Admin\ThemSinhVienRequest;
use App\Http\Requests\Admin\ThemGiangVienRequest;
use App\Http\Requests\Admin\SuaSinhVienRequest;
use App\Http\Requests\Admin\SuaGiangVienRequest;
use App\Http\Requests\Admin\KhoaTaiKhoanSVRequest;
use App\Http\Requests\Admin\KhoaTaiKhoanGVRequest;
use App\Http\Requests\Admin\ThemNguoiDungRequest;
use App\Services\NguoiDungService;

class NguoiDungController extends Controller
{
    protected $nguoiDungService;

    public function __construct(NguoiDungService $nguoiDungService)
    {
        $this->nguoiDungService = $nguoiDungService;
    }

    // ==========================================================
    // UNIFIED APIS MATCHING FRONTEND REQUIREMENTS
    // ==========================================================

    /**
     * Lấy danh sách (Sinh viên hoặc Giảng viên) dựa theo tham số role
     */
    public function layDanhSach(Request $request)
    {
        $role = $request->input('role', 'student');
        $limit = $request->input('limit', 10);
        $keyword = $request->input('keyword');
        $className = $request->input('className');
        $status = $request->input('status');

        if ($role === 'teacher') {
            $query = GiangVien::query();

            if (!empty($keyword)) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('ho_ten', 'like', '%' . trim($keyword) . '%')
                      ->orWhere('email', 'like', '%' . trim($keyword) . '%')
                      ->orWhere('chuyen_mon', 'like', '%' . trim($keyword) . '%')
                      ->orWhere('giang_vien_id', 'like', '%' . trim($keyword) . '%');
                });
            }

            if (!empty($className)) {
                $query->where('chuyen_mon', 'like', '%' . trim($className) . '%');
            }

            if (!empty($status)) {
                $query->where('dang_hoat_dong', $status === 'active' ? 1 : 0);
            }

            $paginator = $query->paginate($limit);
            $rows = collect($paginator->items())->map(function ($gv) {
                return $this->transformTeacher($gv);
            })->all();
        } else {
            $query = SinhVien::query()->with('lop');

            if (!empty($keyword)) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('ho_ten', 'like', '%' . trim($keyword) . '%')
                      ->orWhere('email', 'like', '%' . trim($keyword) . '%')
                      ->orWhere('ma_so_sinh_vien', 'like', '%' . trim($keyword) . '%')
                      ->orWhereHas('lop', function ($lQ) use ($keyword) {
                          $lQ->where('ten_lop', 'like', '%' . trim($keyword) . '%');
                      });
                });
            }

            if (!empty($className)) {
                $query->whereHas('lop', function ($lQ) use ($className) {
                    $lQ->where('ten_lop', 'like', '%' . trim($className) . '%');
                });
            }

            if (!empty($status)) {
                $query->where('dang_hoat_dong', $status === 'active' ? 1 : 0);
            }

            $paginator = $query->paginate($limit);
            $rows = collect($paginator->items())->map(function ($sv) {
                return $this->transformStudent($sv);
            })->all();
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $rows,
                    'total' => $paginator->total()
                ]
            ],
            'pagination' => [
                'total' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
                'limit' => $paginator->perPage(),
                'first' => $paginator->onFirstPage(),
                'last' => !$paginator->hasMorePages(),
                'hasNext' => $paginator->hasMorePages(),
                'hasPrevious' => !$paginator->onFirstPage()
            ]
        ], 200);
    }

    /**
     * Xem chi tiết người dùng bằng ID (MSSV hoặc mã GV)
     */
    public function xemChiTiet(Request $request, $id)
    {
        // Tìm sinh viên trước
        $sv = SinhVien::where('ma_so_sinh_vien', $id)
            ->orWhere('sinh_vien_id', $id)
            ->first();
        if ($sv) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformStudent($sv)
                ]
            ], 200);
        }

        // Tìm giảng viên
        $gv = GiangVien::where('giang_vien_id', $id)->first();
        if ($gv) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformTeacher($gv)
                ]
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Không tìm thấy người dùng với ID này!'
        ], 404);
    }

    /**
     * Tạo mới người dùng (SV hoặc GV tùy theo role)
     */
    public function themMoi(ThemNguoiDungRequest $request)
    {
        $role = $request->input('role', 'student');

        if ($role === 'teacher') {

            $gv = GiangVien::create([
                'ho_ten' => $request->name,
                'email' => $request->email,
                'so_dien_thoai' => $request->phone,
                'gioi_tinh' => $request->gender,
                'ngay_sinh' => $request->dateOfBirth,
                'hoc_vi' => $request->academicDegree,
                'chuyen_mon' => $request->specialization,
                'vai_tro' => 'GIANG_VIEN',
                'dang_hoat_dong' => $request->status === 'inactive' ? 0 : 1
            ]);

            \App\Services\RealtimeService::broadcast('slot_updated', [
                'type' => 'user_created',
                'role' => 'teacher',
                'payload' => $this->transformTeacher($gv)
            ]);

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformTeacher($gv)
                ]
            ], 200);
        } else {

            $lopId = null;
            if ($request->className) {
                $lop = Lop::firstOrCreate(['ten_lop' => $request->className]);
                $lopId = $lop->lop_id;
            }

            $sv = SinhVien::create([
                'ma_so_sinh_vien' => $request->id,
                'ho_ten' => $request->name,
                'email' => $request->email,
                'so_dien_thoai' => $request->phone,
                'gioi_tinh' => $request->gender,
                'ngay_sinh' => $request->dateOfBirth,
                'lop_id' => $lopId,
                'dang_hoat_dong' => $request->status === 'inactive' ? 0 : 1
            ]);

            \App\Services\RealtimeService::broadcast('slot_updated', [
                'type' => 'user_created',
                'role' => 'student',
                'payload' => $this->transformStudent($sv)
            ]);

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformStudent($sv)
                ]
            ], 200);
        }
    }

    /**
     * Cập nhật thông tin người dùng
     */
    public function capNhat(Request $request, $id)
    {
        // Kiểm tra xem là Sinh viên hay Giảng viên
        $sv = SinhVien::where('ma_so_sinh_vien', $id)
            ->orWhere('sinh_vien_id', $id)
            ->first();

        if ($sv) {
            $lopId = $sv->lop_id;
            if ($request->has('className')) {
                if ($request->className) {
                    $lop = Lop::firstOrCreate(['ten_lop' => $request->className]);
                    $lopId = $lop->lop_id;
                } else {
                    $lopId = null;
                }
            }

            $updateData = [];
            if ($request->has('name')) $updateData['ho_ten'] = $request->name;
            if ($request->has('email')) $updateData['email'] = $request->email;
            if ($request->has('phone')) $updateData['so_dien_thoai'] = $request->phone;
            if ($request->has('gender')) $updateData['gioi_tinh'] = $request->gender;
            if ($request->has('dateOfBirth')) $updateData['ngay_sinh'] = $request->dateOfBirth;
            if ($request->has('status')) $updateData['dang_hoat_dong'] = $request->status === 'active' ? 1 : 0;
            $updateData['lop_id'] = $lopId;

            $sv->update($updateData);

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformStudent($sv->fresh())
                ]
            ], 200);
        }

        $gv = GiangVien::where('giang_vien_id', $id)->first();
        if ($gv) {
            $updateData = [];
            if ($request->has('name')) $updateData['ho_ten'] = $request->name;
            if ($request->has('email')) $updateData['email'] = $request->email;
            if ($request->has('phone')) $updateData['so_dien_thoai'] = $request->phone;
            if ($request->has('gender')) $updateData['gioi_tinh'] = $request->gender;
            if ($request->has('dateOfBirth')) $updateData['ngay_sinh'] = $request->dateOfBirth;
            if ($request->has('academicDegree')) $updateData['hoc_vi'] = $request->academicDegree;
            if ($request->has('specialization')) $updateData['chuyen_mon'] = $request->specialization;
            if ($request->has('className')) $updateData['chuyen_mon'] = $request->className; // fallback
            if ($request->has('status')) $updateData['dang_hoat_dong'] = $request->status === 'active' ? 1 : 0;

            $gv->update($updateData);

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformTeacher($gv->fresh())
                ]
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Không tìm thấy người dùng với ID này!'
        ], 404);
    }

    /**
     * Khóa tài khoản người dùng (Xử lý cho API DELETE phía Frontend)
     */
    public function xoaNguoiDung(Request $request, $id)
    {
        $sv = SinhVien::where('ma_so_sinh_vien', $id)
            ->orWhere('sinh_vien_id', $id)
            ->first();

        if ($sv) {
            $sv->update(['dang_hoat_dong' => 0]);
            return response()->json([
                'success' => true,
                'message' => 'Khóa tài khoản sinh viên thành công!'
            ], 200);
        }

        $gv = GiangVien::where('giang_vien_id', $id)->first();
        if ($gv) {
            $gv->update(['dang_hoat_dong' => 0]);
            return response()->json([
                'success' => true,
                'message' => 'Khóa tài khoản giảng viên thành công!'
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Không tìm thấy người dùng!'
        ], 404);
    }

    /**
     * Giả lập Reset Password
     */
    public function resetPassword(Request $request, $id)
    {
        return response()->json([
            'code' => 200,
            'results' => [
                'object' => [
                    'id' => $id,
                    'message' => 'Khôi phục mật khẩu mặc định thành công!'
                ]
            ]
        ], 200);
    }

    // Helper functions
    private function transformStudent($sv)
    {
        return [
            'id' => (string) $sv->ma_so_sinh_vien,
            'name' => $sv->ho_ten,
            'email' => $sv->email,
            'className' => $sv->lop ? $sv->lop->ten_lop : null,
            'phone' => $sv->so_dien_thoai,
            'role' => 'student',
            'status' => $sv->dang_hoat_dong == 1 ? 'active' : 'inactive',
            'gender' => $sv->gioi_tinh,
            'dateOfBirth' => $sv->ngay_sinh,
        ];
    }

    private function transformTeacher($gv)
    {
        return [
            'id' => (string) $gv->giang_vien_id,
            'name' => $gv->ho_ten,
            'email' => $gv->email,
            'className' => $gv->chuyen_mon,
            'phone' => $gv->so_dien_thoai,
            'role' => 'teacher',
            'status' => $gv->dang_hoat_dong == 1 ? 'active' : 'inactive',
            'academicDegree' => $gv->hoc_vi,
            'specialization' => $gv->chuyen_mon,
        ];
    }

    // ==========================================================
    // LEGACY METHODS (HELD FOR BACKWARD COMPATIBILITY)
    // ==========================================================

    public function layDanhSachSinhVien(LocSinhVienRequest $request)
    {
        $perPage = $request->input('per_page', 20);
        $data = $this->nguoiDungService->locSinhVien($request->validated(), $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Lấy danh sách sinh viên thành công!',
            'data'    => $data
        ], 200);
    }

    public function layDanhSachGiangVien(LocGiangVienRequest $request)
    {
        $perPage = $request->input('per_page', 20);
        $data = $this->nguoiDungService->locGiangVien($request->validated(), $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Lấy danh sách giảng viên thành công!',
            'data'    => $data
        ], 200);
    }

    public function themSinhVien(ThemSinhVienRequest $request)
    {
        $sinhVien = $this->nguoiDungService->themSinhVien($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Thêm mới sinh viên thành công!',
            'data'    => $sinhVien
        ], 201);
    }

    public function themGiangVien(ThemGiangVienRequest $request)
    {
        $giangVien = $this->nguoiDungService->themGiangVien($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Thêm mới giảng viên thành công!',
            'data'    => $giangVien
        ], 201);
    }

    public function capNhatSinhVien(SuaSinhVienRequest $request, $sinh_vien_id)
    {
        $sinhVien = $this->nguoiDungService->capNhatSinhVien($sinh_vien_id, $request->validated());

        if (!$sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên với ID này!'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật thông tin sinh viên thành công!',
            'data'    => $sinhVien
        ], 200);
    }

    public function capNhatGiangVien(SuaGiangVienRequest $request, $giang_vien_id)
    {
        $giangVien = $this->nguoiDungService->capNhatGiangVien($giang_vien_id, $request->validated());

        if (!$giangVien) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy giảng viên với ID này!'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật thông tin giảng viên thành công!',
            'data'    => $giangVien
        ], 200);
    }

    public function khoaTaiKhoanSinhVien(KhoaTaiKhoanSVRequest $request)
    {
        $id = $request->input('id');
        $trangThaiMoi = $request->input('dang_hoat_dong');

        $sinhVien = $this->nguoiDungService->doiTrangThaiSinhVien($id, $trangThaiMoi);

        if (!$sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sinh viên với ID này!'
            ], 404);
        }

        $msg = ($trangThaiMoi == 0) 
            ? 'Khóa tài khoản sinh viên thành công!' 
            : 'Mở khóa tài khoản sinh viên thành công!';

        return response()->json([
            'success' => true,
            'message' => $msg,
            'data'    => [
                'sinh_vien_id'   => $sinhVien->sinh_vien_id,
                'dang_hoat_dong' => $sinhVien->dang_hoat_dong
            ]
        ], 200);
    }

    public function khoaTaiKhoanGiangVien(KhoaTaiKhoanGVRequest $request)
    {
        $id = $request->input('id');
        $trangThaiMoi = $request->input('dang_hoat_dong');

        $giangVien = $this->nguoiDungService->doiTrangThaiGiangVien($id, $trangThaiMoi);

        if (!$giangVien) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy giảng viên với ID này!'
            ], 404);
        }

        $msg = ($trangThaiMoi == 0) 
            ? 'Khóa tài khoản giảng viên thành công!' 
            : 'Mở khóa tài khoản giảng viên thành công!';

        return response()->json([
            'success' => true,
            'message' => $msg,
            'data'    => [
                'giang_vien_id'  => $giangVien->giang_vien_id,
                'dang_hoat_dong' => $giangVien->dang_hoat_dong
            ]
        ], 200);
    }
}