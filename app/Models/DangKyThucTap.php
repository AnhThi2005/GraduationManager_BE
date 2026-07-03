<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DangKyThucTap extends Model
{
    protected $table = 'dangkythuctap';
    protected $primaryKey = 'dang_ky_id';

    public $timestamps = false;

    protected $fillable = [
        'sinh_vien_id',
        'dot_id',
        'cong_ty_id',
        'nguoi_huong_dan',
        'sdt_huong_dan',
        'vi_tri_thuc_tap',
        'vi_tri_cong_viec',
        'thoi_gian_thuc_tap',
        'dia_chi_thuc_tap',
        'trang_thai',
        'ngay_dang_ky'
    ];

    protected $casts = [
        'ngay_dang_ky' => 'datetime',
    ];

    public function sinhVien()
    {
        return $this->belongsTo(SinhVien::class, 'sinh_vien_id', 'sinh_vien_id');
    }

    public function dot()
    {
        return $this->belongsTo(Dot::class, 'dot_id', 'dot_id');
    }

    public function congTy()
    {
        return $this->belongsTo(CongTy::class, 'cong_ty_id', 'cong_ty_id');
    }
}
