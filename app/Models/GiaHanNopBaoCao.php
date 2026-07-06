<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiaHanNopBaoCao extends Model
{
    protected $table = 'gia_han_nop_bao_cao';
    public $timestamps = false;

    protected $fillable = [
        'sinh_vien_id',
        'dot_id',
        'loai_bao_cao',
        'tuan',
        'han_nop_moi',
        'nguoi_gia_han_id',
        'ngay_gia_han'
    ];

    protected $casts = [
        'han_nop_moi' => 'datetime',
        'ngay_gia_han' => 'datetime'
    ];

    public function sinhVien()
    {
        return $this->belongsTo(SinhVien::class, 'sinh_vien_id', 'sinh_vien_id');
    }

    public function dot()
    {
        return $this->belongsTo(Dot::class, 'dot_id', 'dot_id');
    }

    public function giangVien()
    {
        return $this->belongsTo(GiangVien::class, 'nguoi_gia_han_id', 'giang_vien_id');
    }
}
