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
}
