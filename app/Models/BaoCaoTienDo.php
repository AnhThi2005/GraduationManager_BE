<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BaoCaoTienDo extends Model
{
    protected $table = 'baocaotiendo';

    protected $primaryKey = 'bao_cao_id';

    public $timestamps = false;

    protected $fillable = [
        'sinh_vien_id',
        'dot_id',
        'tuan_so',
        'noi_dung',
        'duong_dan_file',
        'ten_file_goc',
        'trang_thai',
        'loai_bao_cao',
        'thoi_gian_nop',
        'thoi_gian_huy',
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
