<?php

namespace App\Services;

use App\Models\GiangVien;
use App\Models\Lop;
use App\Models\SinhVien;

class NguoiDungService
{
    public function locSinhVien(array $filters, $perPage = 20)
    {
        $query = SinhVien::query()->orderBy('sinh_vien_id', 'desc')->with('lop');

        // Lọc theo từ khóa (họ tên hoặc mã số sinh viên)
        if (! empty($filters['ho_ten'])) {
            $keyword = trim($filters['ho_ten']);
            $query->where(function ($q) use ($keyword) {
                $q->where('ho_ten', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('ma_so_sinh_vien', 'LIKE', '%'.$keyword.'%');
            });
        }

        // Lọc theo tên lớp (className)
        if (! empty($filters['ten_lop'])) {
            $tenLop = trim($filters['ten_lop']);
            $query->whereHas('lop', function ($subQuery) use ($tenLop) {
                $subQuery->where('ten_lop', '=', $tenLop);
            });
        }

        // Lọc theo trạng thái hoạt động (dang_hoat_dong)
        if (isset($filters['dang_hoat_dong'])) {
            $query->where('dang_hoat_dong', $filters['dang_hoat_dong']);
        }

        return $query->paginate($perPage);
    }

    public function locGiangVien(array $filters, $perPage = 20)
    {
        $query = GiangVien::query()->orderBy('giang_vien_id', 'desc');

        // Lọc theo từ khóa (họ tên hoặc mã giảng viên)
        if (! empty($filters['ho_ten'])) {
            $keyword = trim($filters['ho_ten']);
            $query->where(function ($q) use ($keyword) {
                $q->where('ho_ten', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('giang_vien_id', 'LIKE', '%'.$keyword.'%');
            });
        }

        // Lọc theo chuyên môn (chuyen_mon)
        if (! empty($filters['chuyen_mon'])) {
            $chuyenMon = trim($filters['chuyen_mon']);
            $query->where('chuyen_mon', 'LIKE', '%'.$chuyenMon.'%');
        }

        // Lọc theo vai trò (ADMIN/GIANG_VIEN)
        if (! empty($filters['vai_tro'])) {
            $query->where('vai_tro', $filters['vai_tro']);
        }

        // Lọc theo trạng thái hoạt động (dang_hoat_dong)
        if (isset($filters['dang_hoat_dong'])) {
            $query->where('dang_hoat_dong', $filters['dang_hoat_dong']);
        }

        return $query->paginate($perPage);
    }

    public function themSinhVien(array $data)
    {
        // Chuyển logic giải quyết lớp học từ Controller sang Service (Vòng 4)
        if (! empty($data['className'])) {
            $lop = Lop::firstOrCreate(['ten_lop' => trim($data['className'])]);
            $data['lop_id'] = $lop->lop_id;
        }
        unset($data['className']);

        $sv = SinhVien::create($data);

        // Broadcast event realtime
        RealtimeService::broadcast('slot_updated', [
            'type' => 'user_created',
            'role' => 'student',
            'payload' => [
                'id' => (string) $sv->ma_so_sinh_vien,
                'name' => $sv->ho_ten,
                'email' => $sv->email,
                'className' => $sv->lop ? $sv->lop->ten_lop : null,
                'phone' => $sv->so_dien_thoai,
                'role' => 'student',
                'status' => $sv->dang_hoat_dong == 1 ? 'active' : 'inactive',
                'gender' => $sv->gioi_tinh,
                'dateOfBirth' => $sv->ngay_sinh,
            ],
        ]);

        return $sv;
    }

    public function themGiangVien(array $data)
    {
        // Map className sang chuyen_mon nếu có truyền lên
        if (isset($data['className'])) {
            $data['chuyen_mon'] = $data['className'];
            unset($data['className']);
        }

        $gv = GiangVien::create($data);

        // Broadcast event realtime
        RealtimeService::broadcast('slot_updated', [
            'type' => 'user_created',
            'role' => strtolower($gv->vai_tro) === 'admin' ? 'admin' : 'teacher',
            'payload' => [
                'id' => (string) $gv->giang_vien_id,
                'name' => $gv->ho_ten,
                'email' => $gv->email,
                'className' => $gv->chuyen_mon,
                'phone' => $gv->so_dien_thoai,
                'role' => strtolower($gv->vai_tro) === 'admin' ? 'admin' : 'teacher',
                'status' => $gv->dang_hoat_dong == 1 ? 'active' : 'inactive',
                'academicDegree' => $gv->hoc_vi,
                'specialization' => $gv->chuyen_mon,
            ],
        ]);

        return $gv;
    }

    public function capNhatSinhVien($id, array $data)
    {
        $sinhVien = SinhVien::where('sinh_vien_id', $id)->first();
        if (! $sinhVien) {
            return null;
        }

        // Chuyển logic giải quyết lớp học từ Controller sang Service (Vòng 4)
        if (array_key_exists('className', $data)) {
            if (! empty($data['className'])) {
                $lop = Lop::firstOrCreate(['ten_lop' => trim($data['className'])]);
                $data['lop_id'] = $lop->lop_id;
            } else {
                $data['lop_id'] = null;
            }
            unset($data['className']);
        }

        if (isset($data['email']) && $data['email'] !== $sinhVien->email) {
            $data['google_id'] = null;
        }

        $sinhVien->update($data);

        return $sinhVien->fresh();
    }

    public function capNhatGiangVien($giang_vien_id, array $data)
    {
        $giangVien = GiangVien::where('giang_vien_id', $giang_vien_id)->first();
        if (! $giangVien) {
            return null;
        }

        // Map className sang chuyen_mon nếu có truyền lên
        if (array_key_exists('className', $data)) {
            $data['chuyen_mon'] = $data['className'];
            unset($data['className']);
        }

        if (isset($data['email']) && $data['email'] !== $giangVien->email) {
            $data['google_id'] = null;
        }

        $giangVien->update($data);

        return $giangVien->fresh();
    }

    public function doiTrangThaiSinhVien($id, $trangThaiMoi)
    {
        $sinhVien = SinhVien::where('sinh_vien_id', $id)->first();
        if (! $sinhVien) {
            return null;
        }

        $sinhVien->dang_hoat_dong = $trangThaiMoi; // Nhận trực tiếp 0 hoặc 1 từ Request gác cổng
        $sinhVien->save();

        return $sinhVien;
    }

    public function doiTrangThaiGiangVien($id, $trangThaiMoi)
    {
        $giangVien = GiangVien::where('giang_vien_id', $id)->first();
        if (! $giangVien) {
            return null;
        }

        $giangVien->dang_hoat_dong = $trangThaiMoi; // Nhận trực tiếp 0 hoặc 1 từ Request gác cổng
        $giangVien->save();

        return $giangVien;
    }
}
