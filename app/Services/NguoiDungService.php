<?php

namespace App\Services;

use App\Models\Dot;
use App\Models\GiangVien;
use App\Models\Lop;
use App\Models\Nhom;
use App\Models\PhanCongHdtt;
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
                $q->where('ma_so_sinh_vien', 'LIKE', '%'.$keyword.'%')
                    ->orWhere(function ($sub) use ($keyword) {
                        if (!str_contains($keyword, ' ')) {
                            $sub->where('ho_ten', 'LIKE', '% '.$keyword)
                                ->orWhere('ho_ten', '=', $keyword);
                        } else {
                            $sub->where('ho_ten', 'LIKE', '%'.$keyword.'%');
                        }
                    });
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
                $q->where('giang_vien_id', 'LIKE', '%'.$keyword.'%')
                    ->orWhere(function ($sub) use ($keyword) {
                        if (!str_contains($keyword, ' ')) {
                            $sub->where('ho_ten', 'LIKE', '% '.$keyword)
                                ->orWhere('ho_ten', '=', $keyword);
                        } else {
                            $sub->where('ho_ten', 'LIKE', '%'.$keyword.'%');
                        }
                    });
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

    /**
     * Sinh viên đang tham gia (qua lớp hoặc gắn thủ công) một đợt TTTN/ĐATN
     * chưa đóng (trang_thai != DA_DONG) thì không được khóa tài khoản.
     */
    public function sinhVienDangThamGiaDotMo($sinhVienId): bool
    {
        $sinhVien = SinhVien::where('sinh_vien_id', $sinhVienId)->first();
        if (! $sinhVien) {
            return false;
        }

        return Dot::where('trang_thai', '!=', 'DA_DONG')
            ->where(function ($query) use ($sinhVien, $sinhVienId) {
                $query->whereHas('lops', function ($q) use ($sinhVien) {
                    $q->where('lop.lop_id', $sinhVien->lop_id);
                })->orWhereHas('sinhViens', function ($q) use ($sinhVienId) {
                    $q->where('sinhvien.sinh_vien_id', $sinhVienId);
                });
            })
            ->exists();
    }

    /**
     * Giảng viên đang được phân công hướng dẫn thực tập (phanconghdtt) trong
     * một đợt TTTN chưa đóng, hoặc có đề tài ĐATN (thuộc đợt ĐATN chưa đóng)
     * đã có nhóm sinh viên đăng ký, thì không được khóa tài khoản.
     */
    public function giangVienDangHuongDanDotMo($giangVienId): bool
    {
        $dangHuongDanTttn = PhanCongHdtt::where('giang_vien_id', $giangVienId)
            ->whereHas('dot', function ($q) {
                $q->where('loai_dot', 'TTTN')->where('trang_thai', '!=', 'DA_DONG');
            })
            ->exists();

        if ($dangHuongDanTttn) {
            return true;
        }

        return Nhom::whereHas('deTai', function ($q) use ($giangVienId) {
            $q->where('giang_vien_id', $giangVienId);
        })->whereHas('dot', function ($q) {
            $q->where('loai_dot', 'DATN')->where('trang_thai', '!=', 'DA_DONG');
        })->exists();
    }

    /**
     * Phạm vi (lop_id, sinh_vien_id thủ công) của các đợt TTTN/ĐATN chưa đóng —
     * dùng để đánh dấu hàng loạt trong danh sách người dùng (tránh chạy lại
     * truy vấn kiểm tra cho từng dòng như sinhVienDangThamGiaDotMo()).
     */
    public function phamViDotMoChoSinhVien(): array
    {
        $openDots = Dot::where('trang_thai', '!=', 'DA_DONG')->with('lops:lop_id')->get();
        $openDotIds = $openDots->pluck('dot_id')->all();

        return [
            'lopIds' => $openDots->pluck('lops')->flatten()->pluck('lop_id')->unique()->all(),
            'sinhVienIds' => empty($openDotIds) ? [] : \DB::table('dot_sinhvien')
                ->whereIn('dot_id', $openDotIds)
                ->pluck('sinh_vien_id')
                ->all(),
        ];
    }

    /**
     * Danh sách giang_vien_id đang hướng dẫn TTTN/ĐATN ở đợt chưa đóng —
     * dùng để đánh dấu hàng loạt trong danh sách người dùng (tránh chạy lại
     * truy vấn kiểm tra cho từng dòng như giangVienDangHuongDanDotMo()).
     */
    public function danhSachGiangVienDangHuongDanDotMo(): array
    {
        $tttnIds = PhanCongHdtt::whereHas('dot', function ($q) {
            $q->where('loai_dot', 'TTTN')->where('trang_thai', '!=', 'DA_DONG');
        })->pluck('giang_vien_id')->all();

        $datnIds = Nhom::whereHas('dot', function ($q) {
            $q->where('loai_dot', 'DATN')->where('trang_thai', '!=', 'DA_DONG');
        })->with('deTai:de_tai_id,giang_vien_id')->get()
            ->pluck('deTai.giang_vien_id')
            ->filter()
            ->all();

        return array_values(array_unique(array_merge($tttnIds, $datnIds)));
    }
}
