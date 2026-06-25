<?php

namespace App\Services;

use App\Models\SinhVien;
use App\Models\GiangVien;
use App\Models\Lop;

class NguoiDungService
{
    /**
     * Lấy danh sách người dùng phân trang và ánh xạ theo định dạng Frontend.
     */
    public function layDanhSachNguoiDung(array $filters, $perPage = 20)
    {
        $role = $filters['role'] ?? 'student';

        if ($role === 'teacher' || $role === 'GIANG_VIEN') {
            $query = GiangVien::query();

            if (!empty($filters['keyword'])) {
                $kw = trim($filters['keyword']);
                $query->where(function ($q) use ($kw) {
                    $q->where('ho_ten', 'LIKE', '%' . $kw . '%')
                      ->orWhere('email', 'LIKE', '%' . $kw . '%')
                      ->orWhere('so_dien_thoai', 'LIKE', '%' . $kw . '%');
                });
            }

            if (isset($filters['status'])) {
                $statusVal = $filters['status'];
                if ($statusVal === 'active' || $statusVal === '1') {
                    $query->where('dang_hoat_dong', 1);
                } elseif ($statusVal === 'inactive' || $statusVal === '0') {
                    $query->where('dang_hoat_dong', 0);
                }
            }

            $paginator = $query->paginate($perPage);

            $objects = collect($paginator->items())->map(function ($item) {
                return [
                    'id' => (string) $item->giang_vien_id,
                    'name' => $item->ho_ten,
                    'email' => $item->email,
                    'role' => 'teacher',
                    'phone' => $item->so_dien_thoai,
                    'gender' => $item->gioi_tinh,
                    'dateOfBirth' => $item->ngay_sinh,
                    'status' => $item->dang_hoat_dong == 1 ? 'active' : 'inactive',
                    'academicDegree' => $item->hoc_vi,
                    'specialization' => $item->chuyen_mon,
                ];
            })->all();
        } else {
            $query = SinhVien::query()->with('lop');

            if (!empty($filters['keyword'])) {
                $kw = trim($filters['keyword']);
                $query->where(function ($q) use ($kw) {
                    $q->where('ho_ten', 'LIKE', '%' . $kw . '%')
                      ->orWhere('email', 'LIKE', '%' . $kw . '%')
                      ->orWhere('ma_so_sinh_vien', 'LIKE', '%' . $kw . '%')
                      ->orWhere('so_dien_thoai', 'LIKE', '%' . $kw . '%');
                });
            }

            if (!empty($filters['className'])) {
                $cn = trim($filters['className']);
                $query->whereHas('lop', function ($q) use ($cn) {
                    $q->where('ten_lop', 'LIKE', '%' . $cn . '%');
                });
            }

            if (isset($filters['status'])) {
                $statusVal = $filters['status'];
                if ($statusVal === 'active' || $statusVal === '1') {
                    $query->where('dang_hoat_dong', 1);
                } elseif ($statusVal === 'inactive' || $statusVal === '0') {
                    $query->where('dang_hoat_dong', 0);
                }
            }

            $paginator = $query->paginate($perPage);

            $objects = collect($paginator->items())->map(function ($item) {
                return [
                    'id' => (string) $item->sinh_vien_id,
                    'name' => $item->ho_ten,
                    'email' => $item->email,
                    'role' => 'student',
                    'phone' => $item->so_dien_thoai,
                    'gender' => $item->gioi_tinh,
                    'dateOfBirth' => $item->ngay_sinh,
                    'status' => $item->dang_hoat_dong == 1 ? 'active' : 'inactive',
                    'className' => $item->lop?->ten_lop,
                ];
            })->all();
        }

        return [
            'paginator' => $paginator,
            'objects'   => $objects,
            'total'     => $paginator->total(),
            'rows'      => $objects,
        ];
    }

    /**
     * Lấy thông tin chi tiết của một người dùng bất kỳ (Sinh viên hoặc Giảng viên).
     */
    public function layChiTietNguoiDung($id)
    {
        $sv = SinhVien::with('lop')->find($id);
        if ($sv) {
            return [
                'id' => (string) $sv->sinh_vien_id,
                'name' => $sv->ho_ten,
                'email' => $sv->email,
                'role' => 'student',
                'phone' => $sv->so_dien_thoai,
                'gender' => $sv->gioi_tinh,
                'dateOfBirth' => $sv->ngay_sinh,
                'status' => $sv->dang_hoat_dong == 1 ? 'active' : 'inactive',
                'className' => $sv->lop?->ten_lop,
            ];
        }

        $gv = GiangVien::find($id);
        if ($gv) {
            return [
                'id' => (string) $gv->giang_vien_id,
                'name' => $gv->ho_ten,
                'email' => $gv->email,
                'role' => 'teacher',
                'phone' => $gv->so_dien_thoai,
                'gender' => $gv->gioi_tinh,
                'dateOfBirth' => $gv->ngay_sinh,
                'status' => $gv->dang_hoat_dong == 1 ? 'active' : 'inactive',
                'academicDegree' => $gv->hoc_vi,
                'specialization' => $gv->chuyen_mon,
            ];
        }

        return null;
    }

    /**
     * Thêm mới người dùng bất kỳ.
     */
    public function themNguoiDung(array $data)
    {
        $role = $data['role'] ?? 'student';
        if ($role === 'teacher' || $role === 'GIANG_VIEN') {
            $gv = GiangVien::create([
                'ho_ten' => $data['name'],
                'email' => $data['email'],
                'so_dien_thoai' => $data['phone'] ?? null,
                'gioi_tinh' => $data['gender'] ?? null,
                'ngay_sinh' => $data['dateOfBirth'] ?? null,
                'hoc_vi' => $data['academicDegree'] ?? null,
                'chuyen_mon' => $data['specialization'] ?? null,
                'dang_hoat_dong' => 1,
            ]);

            return [
                'id' => (string) $gv->giang_vien_id,
                'name' => $gv->ho_ten,
                'email' => $gv->email,
                'role' => 'teacher',
                'phone' => $gv->so_dien_thoai,
                'gender' => $gv->gioi_tinh,
                'dateOfBirth' => $gv->ngay_sinh,
                'status' => 'active',
                'academicDegree' => $gv->hoc_vi,
                'specialization' => $gv->chuyen_mon,
            ];
        } else {
            $lopId = null;
            if (!empty($data['className'])) {
                $lop = Lop::firstOrCreate(['ten_lop' => trim($data['className'])]);
                $lopId = $lop->lop_id;
            }

            $sv = SinhVien::create([
                'ma_so_sinh_vien' => $data['id'],
                'ho_ten' => $data['name'],
                'email' => $data['email'],
                'so_dien_thoai' => $data['phone'] ?? null,
                'gioi_tinh' => $data['gender'] ?? null,
                'ngay_sinh' => $data['dateOfBirth'] ?? null,
                'lop_id' => $lopId,
                'dang_hoat_dong' => 1,
            ]);

            return [
                'id' => (string) $sv->sinh_vien_id,
                'name' => $sv->ho_ten,
                'email' => $sv->email,
                'role' => 'student',
                'phone' => $sv->so_dien_thoai,
                'gender' => $sv->gioi_tinh,
                'dateOfBirth' => $sv->ngay_sinh,
                'status' => 'active',
                'className' => $data['className'] ?? null,
            ];
        }
    }

    /**
     * Cập nhật người dùng bất kỳ.
     */
    public function capNhatNguoiDung($id, array $data)
    {
        $sv = SinhVien::find($id);
        if ($sv) {
            $updateData = [];
            if (isset($data['name'])) $updateData['ho_ten'] = $data['name'];
            if (isset($data['email'])) $updateData['email'] = $data['email'];
            if (isset($data['phone'])) $updateData['so_dien_thoai'] = $data['phone'];
            if (isset($data['gender'])) $updateData['gioi_tinh'] = $data['gender'];
            if (isset($data['dateOfBirth'])) $updateData['ngay_sinh'] = $data['dateOfBirth'];
            
            if (isset($data['status'])) {
                $updateData['dang_hoat_dong'] = ($data['status'] === 'active' || $data['status'] === '1') ? 1 : 0;
            }

            if (isset($data['className'])) {
                $lop = Lop::firstOrCreate(['ten_lop' => trim($data['className'])]);
                $updateData['lop_id'] = $lop->lop_id;
            }

            $sv->update($updateData);
            $sv->refresh();

            return [
                'id' => (string) $sv->sinh_vien_id,
                'name' => $sv->ho_ten,
                'email' => $sv->email,
                'role' => 'student',
                'phone' => $sv->so_dien_thoai,
                'gender' => $sv->gioi_tinh,
                'dateOfBirth' => $sv->ngay_sinh,
                'status' => $sv->dang_hoat_dong == 1 ? 'active' : 'inactive',
                'className' => $sv->lop?->ten_lop,
            ];
        }

        $gv = GiangVien::find($id);
        if ($gv) {
            $updateData = [];
            if (isset($data['name'])) $updateData['ho_ten'] = $data['name'];
            if (isset($data['email'])) $updateData['email'] = $data['email'];
            if (isset($data['phone'])) $updateData['so_dien_thoai'] = $data['phone'];
            if (isset($data['gender'])) $updateData['gioi_tinh'] = $data['gender'];
            if (isset($data['dateOfBirth'])) $updateData['ngay_sinh'] = $data['dateOfBirth'];
            
            if (isset($data['status'])) {
                $updateData['dang_hoat_dong'] = ($data['status'] === 'active' || $data['status'] === '1') ? 1 : 0;
            }

            if (isset($data['academicDegree'])) $updateData['hoc_vi'] = $data['academicDegree'];
            if (isset($data['specialization'])) $updateData['chuyen_mon'] = $data['specialization'];

            $gv->update($updateData);
            $gv->refresh();

            return [
                'id' => (string) $gv->giang_vien_id,
                'name' => $gv->ho_ten,
                'email' => $gv->email,
                'role' => 'teacher',
                'phone' => $gv->so_dien_thoai,
                'gender' => $gv->gioi_tinh,
                'dateOfBirth' => $gv->ngay_sinh,
                'status' => $gv->dang_hoat_dong == 1 ? 'active' : 'inactive',
                'academicDegree' => $gv->hoc_vi,
                'specialization' => $gv->chuyen_mon,
            ];
        }

        return null;
    }

    /**
     * Xóa tài khoản người dùng bất kỳ.
     */
    public function xoaNguoiDung($id)
    {
        $sv = SinhVien::find($id);
        if ($sv) {
            $sv->delete();
            return 'student';
        }

        $gv = GiangVien::find($id);
        if ($gv) {
            $gv->delete();
            return 'teacher';
        }

        return null;
    }

    /**
     * Đặt lại mật khẩu về mặc định.
     */
    public function resetMatKhau($id)
    {
        $sv = SinhVien::find($id);
        if ($sv) {
            return true;
        }

        $gv = GiangVien::find($id);
        if ($gv) {
            return true;
        }

        return false;
    }

    // --- Các hàm cũ giữ lại để tương thích ngược nếu cần ---

    public function locSinhVien(array $filters, $perPage = 20)
    {
        $query = SinhVien::query()->with('lop');
        $searchCriteria = array_filter(array_intersect_key($filters, array_flip(['ho_ten', 'ma_so_sinh_vien', 'lop_id'])));

        if (count($searchCriteria) === 0 && (array_key_exists('ho_ten', $filters) || array_key_exists('ma_so_sinh_vien', $filters) || array_key_exists('lop', $filters))) {
            return $query->whereRaw('1 = 0')->paginate($perPage);
        }

        $query->when(!empty($searchCriteria['ho_ten']), function ($q) use ($searchCriteria) {
            return $q->where('ho_ten', 'LIKE', '%' . trim($searchCriteria['ho_ten']) . '%');
        });
        
        $query->when(!empty($searchCriteria['ma_so_sinh_vien']), function ($q) use ($searchCriteria) {
            return $q->where('ma_so_sinh_vien', 'LIKE', '%' . trim($searchCriteria['ma_so_sinh_vien']) . '%');
        });

        $query->when(!empty($searchCriteria['lop_id']), function ($q) use ($searchCriteria) {
            return $q->where('lop_id', $searchCriteria['lop_id']);
        });
        
        $query->when(!empty($filters['ten_lop']), function ($q) use ($filters) {
            return $q->whereHas('lop', function ($subQuery) use ($filters) {
                $subQuery->where('ten_lop', 'LIKE', '%' . trim($filters['ten_lop']) . '%');
            });
        });

        return $query->paginate($perPage);
    }

    public function locGiangVien(array $filters, $perPage = 20)
    {
        $query = GiangVien::query();
        $searchCriteria = array_filter(array_intersect_key($filters, array_flip(['ho_ten', 'chuyen_mon', 'vai_tro'])));

        if (count($searchCriteria) === 0 && (array_key_exists('ho_ten', $filters) || array_key_exists('chuyen_mon', $filters) || array_key_exists('vai_tro', $filters))) {
            return $query->whereRaw('1 = 0')->paginate($perPage);
        }

        $query->when(!empty($searchCriteria['ho_ten']), function ($q) use ($searchCriteria) {
            return $q->where('ho_ten', 'LIKE', '%' . trim($searchCriteria['ho_ten']) . '%');
        });

        $query->when(!empty($searchCriteria['chuyen_mon']), function ($q) use ($searchCriteria) {
            return $q->where('chuyen_mon', 'LIKE', '%' . trim($searchCriteria['chuyen_mon']) . '%');
        });

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

        $sinhVien->dang_hoat_dong = $trangThaiMoi;
        $sinhVien->save();

        return $sinhVien;
    }

    public function doiTrangThaiGiangVien($id, $trangThaiMoi)
    {
        $giangVien = GiangVien::where('giang_vien_id', $id)->first();
        if (!$giangVien) {
            return null;
        }

        $giangVien->dang_hoat_dong = $trangThaiMoi;
        $giangVien->save();

        return $giangVien;
    }
}