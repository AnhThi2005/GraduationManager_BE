<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Dot extends Model
{
    protected $table = 'dot';

    protected $primaryKey = 'dot_id';

    public $timestamps = false;

    protected $fillable = [
        'giang_vien_id',
        'ten_dot',
        'loai_dot',
        'hoc_ky',
        'nam_hoc',
        'trang_thai',
        'ngay_bat_dau',
        'ngay_ket_thuc',
        'ngay_bat_dau_dang_ky',
        'han_dang_ky',
        'han_nop_bao_cao',
        'ngay_bat_dau_cham_diem',
        'ngay_ket_thuc_cham_diem',
        'ngay_bat_dau_phan_bien',
        'ngay_ket_thuc_phan_bien',
        'ngay_bat_dau_bao_ve',
        'ngay_ket_thuc_bao_ve',
        'ngay_bat_dau_nop_bao_cao',
    ];

    public function lops()
    {
        return $this->belongsToMany(Lop::class, 'dot_lop', 'dot_id', 'lop_id');
    }

    public function sinhViens()
    {
        return $this->belongsToMany(SinhVien::class, 'dot_sinhvien', 'dot_id', 'sinh_vien_id');
    }

    /**
     * Sinh viên có thuộc đợt này không: qua lớp được gắn vào đợt (dot_lop),
     * hoặc được thêm thủ công vào đợt (dot_sinhvien, ví dụ sinh viên rớt đợt trước).
     */
    public function hasStudent($sinhVienId): bool
    {
        $sinhVien = SinhVien::find($sinhVienId);
        if (! $sinhVien) {
            return false;
        }

        if ($sinhVien->lop_id && $this->lops()->where('lop.lop_id', $sinhVien->lop_id)->exists()) {
            return true;
        }

        return $this->sinhViens()->where('sinhvien.sinh_vien_id', $sinhVienId)->exists();
    }

    /**
     * Số tuần thực tập/đồ án của đợt, tính từ mốc ngày bắt đầu - kết thúc thực tế lúc tạo đợt,
     * thay vì mặc định cứng "8 tuần" (sai với cả 2 hệ: Cao đẳng nghề 14 tuần, Cao đẳng 12 tuần —
     * admin đã tự cấu hình đúng số tuần thật qua ngày bắt đầu/kết thúc nên tính lại từ đó là đúng
     * cho mọi hệ, không cần biết riêng hệ đào tạo là gì).
     */
    public function tinhSoTuan(): int
    {
        if (! $this->ngay_bat_dau || ! $this->ngay_ket_thuc) {
            return 8;
        }

        $start = Carbon::parse($this->ngay_bat_dau, 'Asia/Ho_Chi_Minh');
        $end = Carbon::parse($this->ngay_ket_thuc, 'Asia/Ho_Chi_Minh');

        return max(1, (int) ceil($start->diffInDays($end) / 7));
    }

    public function moTaThoiGianThucTap(): string
    {
        return $this->tinhSoTuan().' tuần';
    }

    // ==========================================================
    // TRẠNG THÁI ĐỢT → QUYỀN CHỈNH SỬA
    // Nguồn duy nhất cho quy tắc khóa/mở theo trạng thái đợt — mọi controller phải gọi
    // qua đây (qua trait KiemTraTrangThaiDot), không tự so sánh trang_thai rải rác.
    // ==========================================================

    /**
     * Đợt đã đóng: KHÔNG AI được sửa gì nữa — kể cả admin. Chỉ xem.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($dot) {
            $dot->trang_thai = $dot->tinhTrangThaiTheoThoiGian();
        });
    }

    public function tinhTrangThaiTheoThoiGian(): string
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh')->format('Y-m-d');
        
        $regOpen = $this->ngay_bat_dau_dang_ky;
        $gradingStart = $this->ngay_bat_dau_cham_diem;
        $endDate = $this->ngay_ket_thuc;

        if ($regOpen && $now < $regOpen) {
            return 'DA_CONG_BO';
        }
        if ($regOpen && $gradingStart && $now >= $regOpen && $now < $gradingStart) {
            return 'DANG_MO';
        }
        if ($gradingStart && $endDate && $now >= $gradingStart && $now < $endDate) {
            return 'CHAM_DIEM';
        }
        if ($endDate && $now >= $endDate) {
            return 'DA_DONG';
        }

        return 'DA_DONG';
    }

    public function getTrangThaiAttribute()
    {
        return $this->tinhTrangThaiTheoThoiGian();
    }

    /**
     * Đợt đã đóng: KHÔNG AI được sửa gì nữa — kể cả admin. Chỉ xem.
     */
    public function daKhoaHoanToan(): bool
    {
        return $this->trang_thai === 'DA_DONG';
    }

    /**
     * Đợt đã bắt đầu chấm điểm trở đi (Chấm điểm / Đã công bố / Đã đóng):
     * sinh viên không được tự sửa dữ liệu của mình trong đợt này nữa
     * (báo cáo, khai báo thực tập, đăng ký/rời nhóm đề tài...).
     */
    public function daKhoaThaoTacSinhVien(): bool
    {
        // 'CHO_MO' là tên cũ của 'DA_CONG_BO', giữ lại để tương thích nếu còn sót dữ liệu cũ.
        return in_array($this->trang_thai, ['CHAM_DIEM', 'DA_CONG_BO', 'CHO_MO', 'DA_DONG'], true);
    }

    /**
     * Đợt đã công bố hoặc đã đóng: giảng viên không được sửa điểm nữa (điểm đã chốt).
     * Trong giai đoạn "Chấm điểm" (CHAM_DIEM) thì vẫn được sửa bình thường.
     */
    public function daKhoaSuaDiem(): bool
    {
        // 'CHO_MO' là tên cũ của 'DA_CONG_BO', giữ lại để tương thích nếu còn sót dữ liệu cũ.
        return in_array($this->trang_thai, ['DA_CONG_BO', 'CHO_MO', 'DA_DONG'], true);
    }
}
