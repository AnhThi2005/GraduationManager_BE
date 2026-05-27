<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class GiangVien extends Model
{
    use HasApiTokens;

    protected $table = 'giangvien';
    protected $primaryKey = 'giang_vien_id';
    
    public $timestamps = true;
    const CREATED_AT = 'thoi_gian_tao';
    const UPDATED_AT = 'ngay_cap_nhat';

    protected $fillable = [
        'ho_ten', 
        'email', 
        'so_dien_thoai', 
        'gioi_tinh', 
        'ngay_sinh', 
        'khoa', 
        'hoc_vi', 
        'chuyen_mon', 
        'vai_tro', 
        'dang_hoat_dong', 
        'google_id'
    ];
    // Khai báo giá trị mặc định ở tầng Model để đồng bộ với DB
    protected $attributes = [
        'dang_hoat_dong' => 1, // Trạng thái hoạt động mặc định là 1 (đang hoạt động) khi tạo mới giảng viên
        'vai_tro' => 'GIANG_VIEN', // Vai trò mặc định là GIANG_VIEN khi tạo mới giảng viên
    ];
}
