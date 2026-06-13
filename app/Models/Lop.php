<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lop extends Model
{
    protected $table = 'lop';

    protected $primaryKey = 'lop_id';
    
    public $timestamps = false; 

    protected $fillable = [
        'ten_lop',
        'khoa_hoc',
        'bac_dao_tao',
        'chuyen_nganh',
    ];

    // Định nghĩa mối quan hệ 1-n với SinhVien
    public function sinhViens()
    {
        return $this->hasMany(SinhVien::class, 'lop_id', 'lop_id');
    }
}
