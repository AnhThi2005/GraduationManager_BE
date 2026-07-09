<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CongTy extends Model
{
    protected $table = 'congty';

    protected $primaryKey = 'cong_ty_id';

    public $timestamps = false;

    protected $fillable = [
        'ten_cong_ty',
        'dia_chi',
        'ma_so_thue',
        'nguoi_lien_he',
        'email_lien_he',
        'so_dien_thoai_lh',
        'trang_thai',
        'da_cong_bo',
    ];
}
