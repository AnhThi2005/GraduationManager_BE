<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoiMoiNhom extends Model
{
    protected $table = 'loimoinhom';

    protected $primaryKey = 'loi_moi_id';

    public $timestamps = false;

    protected $fillable = [
        'nhom_id',
        'sinh_vien_duoc_moi_id',
        'trang_thai_xac_nhan',
        'ngay_tao',
    ];

    public function nhom()
    {
        return $this->belongsTo(Nhom::class, 'nhom_id', 'nhom_id');
    }

    public function sinhVienDuocMoi()
    {
        return $this->belongsTo(SinhVien::class, 'sinh_vien_duoc_moi_id', 'sinh_vien_id');
    }
}
