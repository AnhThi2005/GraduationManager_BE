<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhanCongHdtt extends Model
{
    protected $table = 'phanconghdtt';
    protected $primaryKey = 'phan_cong_hd_id';
    public $timestamps = false;

    protected $fillable = [
        'giang_vien_id',
        'sinh_vien_id',
        'dot_id',
        'da_cong_bo'
    ];

    public function giangVien()
    {
        return $this->belongsTo(GiangVien::class, 'giang_vien_id', 'giang_vien_id');
    }

    public function sinhVien()
    {
        return $this->belongsTo(SinhVien::class, 'sinh_vien_id', 'sinh_vien_id');
    }

    public function dot()
    {
        return $this->belongsTo(Dot::class, 'dot_id', 'dot_id');
    }
}
