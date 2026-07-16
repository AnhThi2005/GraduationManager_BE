<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HuongDeTai extends Model
{
    protected $table = 'huongdetai';

    protected $primaryKey = 'huong_de_tai_id';

    public $timestamps = false;

    protected $fillable = [
        'ten_huong_de_tai',
        'trang_thai_hd',
    ];

    public function deTais()
    {
        return $this->belongsToMany(DeTai::class, 'chitiethuongdetai', 'huong_de_tai_id', 'de_tai_id');
    }
}
