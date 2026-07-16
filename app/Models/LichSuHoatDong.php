<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LichSuHoatDong extends Model
{
    protected $table = 'lich_su_hoat_dong';

    protected $primaryKey = 'log_id';

    protected $fillable = [
        'sinh_vien_id',
        'ma_so_sinh_vien',
        'nhom_id',
        'role',
        'user_name',
        'action_type',
        'description',
        'details',
    ];

    /**
     * Helper to write history logs
     */
    public static function ghiLog($actionType, $description, $sinhVienId = null, $maSoSinhVien = null, $nhomId = null, $role = null, $userName = null, $details = null)
    {
        return self::create([
            'sinh_vien_id' => $sinhVienId,
            'ma_so_sinh_vien' => $maSoSinhVien,
            'nhom_id' => $nhomId,
            'role' => $role,
            'user_name' => $userName,
            'action_type' => $actionType,
            'description' => $description,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
