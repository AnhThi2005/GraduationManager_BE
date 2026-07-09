<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HoiDong extends Model
{
    use SoftDeletes;

    protected $table = 'hoidong';

    protected $primaryKey = 'hoi_dong_id';

    public $timestamps = false;

    protected $fillable = [
        'dot_id',
        'ten_hoi_dong',
        'ngay_bao_ve',
        'gio_bao_ve',
        'phong_bao_ve',
        'trang_thai',
    ];

    public function dot()
    {
        return $this->belongsTo(Dot::class, 'dot_id', 'dot_id');
    }

    public function giangViens()
    {
        return $this->belongsToMany(GiangVien::class, 'thanhvienhoidong', 'hoi_dong_id', 'giang_vien_id')
            ->withPivot('vai_tro');
    }

    public function nhoms()
    {
        return $this->hasMany(Nhom::class, 'hoi_dong_id', 'hoi_dong_id');
    }
}
