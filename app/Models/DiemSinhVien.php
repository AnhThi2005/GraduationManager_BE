<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiemSinhVien extends Model
{
    protected $table = 'diemsinhvien';
    protected $primaryKey = 'diem_id';

    protected $fillable = [
        'sinh_vien_id',
        'dot_id',
        'loai',
        'diem_thuyet_trinh',
        'diem_demo',
        'diem_van_dap',
        'diem_bao_cao',
        'diem_tong_ket',
        'trang_thai'
    ];

    public function sinhVien()
    {
        return $this->belongsTo(SinhVien::class, 'sinh_vien_id', 'sinh_vien_id');
    }

    public function dot()
    {
        return $this->belongsTo(Dot::class, 'dot_id', 'dot_id');
    }
}
