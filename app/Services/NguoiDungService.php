<?php

namespace App\Services;

use App\Models\SinhVien;
use App\Models\GiangVien;

class NguoiDungService
{
    public function locSinhVien(array $filters, $perPage = 20)
    {
        $query = SinhVien::query();

        if (!empty($filters['ho_ten'])) {
            $query->where('ho_ten', 'LIKE', '%' . $filters['ho_ten'] . '%');
        }

        if (!empty($filters['ma_so_sinh_vien'])) {
            $query->where('ma_so_sinh_vien', $filters['ma_so_sinh_vien']);
        }

        if (!empty($filters['lop'])) {
            $query->where('lop', $filters['lop']);
        }

        return $query->paginate($perPage);
    }

    public function locGiangVien(array $filters, $perPage = 20)
    {
        $query = GiangVien::query();

        if (!empty($filters['ho_ten'])) {
            $query->where('ho_ten', 'LIKE', '%' . $filters['ho_ten'] . '%');
        }

        if (!empty($filters['chuyen_mon'])) {
            $query->where('chuyen_mon', 'LIKE', '%' . $filters['chuyen_mon'] . '%');
        }

        if (!empty($filters['vai_tro'])) {
            $query->where('vai_tro', $filters['vai_tro']);
        }

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

    public function capNhatGiangVien($id, array $data)
    {
        $giangVien = GiangVien::where('giang_vien_id', $id)->first();
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