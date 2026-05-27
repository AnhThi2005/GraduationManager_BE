<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens; // Dùng để cấp Token Sanctum

class SinhVien extends Model
{
    use HasApiTokens;

    protected $table = 'sinhvien';
    protected $primaryKey = 'sinh_vien_id';
    
    public $timestamps = true;
    const CREATED_AT = 'thoi_gian_tao';
    const UPDATED_AT = 'ngay_cap_nhat';

    protected $fillable = [
        'ma_so_sinh_vien',
        'ho_ten', 
        'email', 
        'so_dien_thoai', 
        'gioi_tinh', 
        'ngay_sinh', 
        'lop', 
        'bac_dao_tao', 
        'khoa_hoc', 
        'chuyen_nganh', 
        'dang_hoat_dong', 
        'google_id'
    ];
    // Khai báo giá trị mặc định ở tầng Model để đồng bộ với DB
    // trang thái hoạt động mặc định là 1 (đang hoạt động) khi tạo mới sinh viên
    protected $attributes = [
        'dang_hoat_dong' => 1, 
    ];
}
