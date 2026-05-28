<?php

namespace App\Services;

use App\Models\SinhVien;
use App\Models\GiangVien;

class NguoiDungService
{
    public function locSinhVien(array $filters, $perPage = 20)
    {
        $query = SinhVien::query();

        // 1. Chỉ bốc các trường lọc liên quan và loại bỏ hoàn toàn các phần tử rỗng "", null
        $searchCriteria = array_filter(array_intersect_key($filters, array_flip(['ho_ten', 'ma_so_sinh_vien', 'lop'])));

        // 2. Nếu Admin có truyền params lọc lên nhưng mảng sau khi dọn dẹp lại trống rỗng (Admin gõ dấu cách hoặc xóa trống input)
        if (count($searchCriteria) === 0 && (array_key_exists('ho_ten', $filters) || array_key_exists('ma_so_sinh_vien', $filters) || array_key_exists('lop', $filters))) {
            return $query->whereRaw('1 = 0')->paginate($perPage);
        }

        // 3. Thực hiện gắn câu lệnh tìm kiếm gần đúng (LIKE) cho TẤT CẢ các trường để tránh lệch pha ký tự
        $query->when(!empty($searchCriteria['ho_ten']), function ($q) use ($searchCriteria) {
            return $q->where('ho_ten', 'LIKE', '%' . trim($searchCriteria['ho_ten']) . '%');
        });

        $query->when(!empty($searchCriteria['ma_so_sinh_vien']), function ($q) use ($searchCriteria) {
            return $q->where('ma_so_sinh_vien', 'LIKE', '%' . trim($searchCriteria['ma_so_sinh_vien']) . '%');
        });

        $query->when(!empty($searchCriteria['lop']), function ($q) use ($searchCriteria) {
            return $q->where('lop', 'LIKE', '%' . trim($searchCriteria['lop']) . '%');
        });
        return $query->paginate($perPage);
    }

    public function locGiangVien(array $filters, $perPage = 20)
    {
        $query = GiangVien::query();

        // 1. Chỉ bốc các trường lọc liên quan và loại bỏ hoàn toàn các phần tử rỗng "", null
        $searchCriteria = array_filter(array_intersect_key($filters, array_flip(['ho_ten', 'chuyen_mon', 'vai_tro'])));

        // 2. Nếu Admin có truyền params lọc lên nhưng mảng sau khi dọn dẹp lại trống rỗng
        if (count($searchCriteria) === 0 && (array_key_exists('ho_ten', $filters) || array_key_exists('chuyen_mon', $filters) || array_key_exists('vai_tro', $filters))) {
            return $query->whereRaw('1 = 0')->paginate($perPage);
        }

        // 3. Thực hiện gắn câu lệnh tìm kiếm gần đúng (LIKE) cho họ tên và chuyên môn
        $query->when(!empty($searchCriteria['ho_ten']), function ($q) use ($searchCriteria) {
            return $q->where('ho_ten', 'LIKE', '%' . trim($searchCriteria['ho_ten']) . '%');
        });

        $query->when(!empty($searchCriteria['chuyen_mon']), function ($q) use ($searchCriteria) {
            return $q->where('chuyen_mon', 'LIKE', '%' . trim($searchCriteria['chuyen_mon']) . '%');
        });

        // Riêng Vai trò (ADMIN/GIANG_VIEN) là dạng chọn từ thanh thả xuống (Dropdown) nên giữ nguyên so sánh bằng tuyệt đối
        $query->when(!empty($searchCriteria['vai_tro']), function ($q) use ($searchCriteria) {
            return $q->where('vai_tro', $searchCriteria['vai_tro']);
        });
        return $query->paginate($perPage);
    }

    public function themSinhVien(array $data)
    {
        return SinhVien::create($data);
    }

    public function themGiangVien(array $data)
    {
        return GiangVien::create($data);
    }

    public function capNhatSinhVien($id, array $data)
    {
        $sinhVien = SinhVien::where('sinh_vien_id', $id)->first();
        if (!$sinhVien) {
            return null;
        }

        $sinhVien->update($data);
        return $sinhVien->fresh();
    }

    public function capNhatGiangVien($giang_vien_id, array $data)
    {
        $giangVien = GiangVien::where('giang_vien_id', $giang_vien_id)->first();
        if (!$giangVien) {
            return null;
        }

        $giangVien->update($data);
        return $giangVien->fresh();
    }

    public function doiTrangThaiSinhVien($id, $trangThaiMoi)
    {
        $sinhVien = SinhVien::where('sinh_vien_id', $id)->first();
        if (!$sinhVien) {
            return null;
        }

        $sinhVien->dang_hoat_dong = $trangThaiMoi; // Nhận trực tiếp 0 hoặc 1 từ Request gác cổng
        $sinhVien->save();

        return $sinhVien;
    }

    public function doiTrangThaiGiangVien($id, $trangThaiMoi)
    {
        $giangVien = GiangVien::where('giang_vien_id', $id)->first();
        if (!$giangVien) {
            return null;
        }

        $giangVien->dang_hoat_dong = $trangThaiMoi; // Nhận trực tiếp 0 hoặc 1 từ Request gác cổng
        $giangVien->save();

        return $giangVien;
    }
}