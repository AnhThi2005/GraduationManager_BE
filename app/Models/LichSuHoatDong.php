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
        'dot_id',
    ];

    /**
     * Helper to write history logs
     */
    public static function ghiLog($actionType, $description, $sinhVienId = null, $maSoSinhVien = null, $nhomId = null, $role = null, $userName = null, $details = null)
    {
        $dotId = null;

        // 1. If nhomId is provided, get dot_id from group
        if ($nhomId) {
            $nhom = \App\Models\Nhom::find($nhomId);
            if ($nhom) {
                $dotId = $nhom->dot_id;
            }
        }

        // 2. If nhomId is not provided, check details for topic_id
        if (!$dotId && is_array($details)) {
            if (isset($details['topic_id'])) {
                $deTai = \App\Models\DeTai::find($details['topic_id']);
                if ($deTai) {
                    $dotId = $deTai->dot_id;
                }
            }
        }

        // 3. If still no dotId, and it's student related, find their active period/batch
        if (!$dotId && $sinhVienId) {
            $isTttn = in_array($actionType, ['KHAI_BAO_THUC_TAP', 'KHAI_BAO_TTTN', 'DUYET_TTTN', 'XOA_TTTN']);
            $type = $isTttn ? 'TTTN' : 'DATN';
            
            $dot = \App\Models\Dot::where('loai_dot', $type)
                ->where('trang_thai', '!=', 'DA_DONG')
                ->where(function($q) use ($sinhVienId) {
                    $q->whereHas('sinhViens', function($q2) use ($sinhVienId) {
                        $q2->where('sinhvien.sinh_vien_id', $sinhVienId);
                    })
                    ->orWhereHas('lops.sinhViens', function($q2) use ($sinhVienId) {
                        $q2->where('sinhvien.sinh_vien_id', $sinhVienId);
                    });
                })
                ->orderBy('dot_id', 'desc')
                ->first();
            
            if ($dot) {
                $dotId = $dot->dot_id;
            } else {
                // Fallback to any dot this student is in
                $dot = \App\Models\Dot::where('loai_dot', $type)
                    ->where(function($q) use ($sinhVienId) {
                        $q->whereHas('sinhViens', function($q2) use ($sinhVienId) {
                            $q2->where('sinhvien.sinh_vien_id', $sinhVienId);
                        })
                        ->orWhereHas('lops.sinhViens', function($q2) use ($sinhVienId) {
                            $q2->where('sinhvien.sinh_vien_id', $sinhVienId);
                        });
                    })
                    ->orderBy('dot_id', 'desc')
                    ->first();
                if ($dot) {
                    $dotId = $dot->dot_id;
                }
            }
        }

        return self::create([
            'sinh_vien_id' => $sinhVienId,
            'ma_so_sinh_vien' => $maSoSinhVien,
            'nhom_id' => $nhomId,
            'role' => $role,
            'user_name' => $userName,
            'action_type' => $actionType,
            'description' => $description,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'dot_id' => $dotId,
        ]);
    }
}
