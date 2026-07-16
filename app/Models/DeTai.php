<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeTai extends Model
{
    protected $table = 'detai';

    protected $primaryKey = 'de_tai_id';

    public $timestamps = false;

    protected $fillable = [
        'dot_id',
        'giang_vien_id',
        'ten_de_tai',
        'mo_ta',
        'file_mo_ta',
        'so_luong_sv_toi_da',
        'trang_thai',
        'ly_do_tu_choi',
    ];

    public function giangVien()
    {
        return $this->belongsTo(GiangVien::class, 'giang_vien_id', 'giang_vien_id');
    }

    public function dot()
    {
        return $this->belongsTo(Dot::class, 'dot_id', 'dot_id');
    }

    public function huongDeTais()
    {
        return $this->belongsToMany(
            HuongDeTai::class,
            'chitiethuongdetai',
            'de_tai_id',
            'huong_de_tai_id'
        );
    }
}
