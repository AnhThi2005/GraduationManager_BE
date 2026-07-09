<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens; // Dùng để cấp Token Sanctum

class SinhVien extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'sinhvien';

    protected $primaryKey = 'sinh_vien_id';

    public $timestamps = false;

    protected $fillable = [
        'ma_so_sinh_vien',
        'ho_ten',
        'email',
        'so_dien_thoai',
        'gioi_tinh',
        'ngay_sinh',
        'lop_id',
        'dang_hoat_dong',
        'google_id',
    ];

    // Khai báo giá trị mặc định ở tầng Model để đồng bộ với DB
    // trang thái hoạt động mặc định là 1 (đang hoạt động) khi tạo mới sinh viên
    protected $attributes = [
        'dang_hoat_dong' => 1,
    ];

    // Mối quan hệ kết nối ngược sang bảng Lớp học
    public function lop()
    {
        return $this->belongsTo(Lop::class, 'lop_id', 'lop_id');
    }
}
