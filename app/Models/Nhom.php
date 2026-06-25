<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Nhom extends Model
{
    protected $table = 'nhomsvda';
    protected $primaryKey = 'nhom_id';
    public $timestamps = false;

    protected $fillable = [
        'de_tai_id',
        'dot_id',
        'hoi_dong_id',
        'trang_thai_nhom',
        'trang_thai_duyet',
        'ket_qua_huong_dan',
        'nhan_xet_phan_bien',
        'ket_qua_phan_bien'
    ];

    public function deTai()
    {
        return $this->belongsTo(DeTai::class, 'de_tai_id', 'de_tai_id');
    }

    public function dot()
    {
        return $this->belongsTo(Dot::class, 'dot_id', 'dot_id');
    }

    public function hoiDong()
    {
        return $this->belongsTo(HoiDong::class, 'hoi_dong_id', 'hoi_dong_id');
    }

    public function members()
    {
        return $this->belongsToMany(SinhVien::class, 'thanhviennhom', 'nhom_id', 'sinh_vien_id')
            ->withPivot('la_truong_nhom', 'dieu_kien_lam_do_an');
    }
}
