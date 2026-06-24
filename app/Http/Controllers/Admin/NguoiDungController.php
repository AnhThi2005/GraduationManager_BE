<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuanLyNguoiDung\LocSinhVienRequest;
use App\Http\Requests\Admin\QuanLyNguoiDung\LocGiangVienRequest;
use App\Http\Requests\Admin\QuanLyNguoiDung\ThemSinhVienRequest;
use App\Http\Requests\Admin\QuanLyNguoiDung\ThemGiangVienRequest;
use App\Http\Requests\Admin\QuanLyNguoiDung\SuaSinhVienRequest;
use App\Http\Requests\Admin\QuanLyNguoiDung\SuaGiangVienRequest;
use App\Http\Requests\Admin\QuanLyNguoiDung\KhoaTaiKhoanSVRequest;
use App\Http\Requests\Admin\QuanLyNguoiDung\KhoaTaiKhoanGVRequest;
use App\Services\NguoiDungService;

class NguoiDungController extends Controller
{
    protected $nguoiDungService;

    public function __construct(NguoiDungService $nguoiDungService)
    {
        $this->nguoiDungService = $nguoiDungService;
    }

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