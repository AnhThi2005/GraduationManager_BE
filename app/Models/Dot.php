<?php

namespace App\Models;

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
        'ngay_ket_thuc_cham_diem'
    ];

    public function lops()
    {
        return $this->belongsToMany(Lop::class, 'dot_lop', 'dot_id', 'lop_id');
    }

    public function sinhViens()
    {
        return $this->belongsToMany(SinhVien::class, 'dot_sinhvien', 'dot_id', 'sinh_vien_id')
            ->withPivot('ly_do');
    }

    /**
     * Sinh viên có thuộc đợt này không: qua lớp được gắn vào đợt (dot_lop),
     * hoặc được thêm thủ công vào đợt (dot_sinhvien, ví dụ sinh viên rớt đợt trước).
     */
    public function hasStudent($sinhVienId): bool
    {
        $sinhVien = SinhVien::find($sinhVienId);
        if (!$sinhVien) {
            return false;
        }

        if ($sinhVien->lop_id && $this->lops()->where('lop.lop_id', $sinhVien->lop_id)->exists()) {
            return true;
        }

        return $this->sinhViens()->where('sinhvien.sinh_vien_id', $sinhVienId)->exists();
    }
}
