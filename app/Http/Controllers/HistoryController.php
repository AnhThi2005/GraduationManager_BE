<?php

namespace App\Http\Controllers;

use App\Models\LichSuHoatDong;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    /**
     * Get history logs for the authenticated student
     */
    public function getStudentHistory(Request $request)
    {
        $sinhVien = $request->user();
        if (! $sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        // Find all groups this student was or is a member of (including disbanded ones if they were logged)
        // Since we want both active group and logs tagged with the student's ID directly:
        $groupIds = DB::table('thanhviennhom')
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->pluck('nhom_id')
            ->all();

        $query = LichSuHoatDong::query()
            ->where(function ($q) use ($sinhVien, $groupIds) {
                $q->where('sinh_vien_id', $sinhVien->sinh_vien_id)
                  ->orWhere('ma_so_sinh_vien', $sinhVien->ma_so_sinh_vien);
                if (!empty($groupIds)) {
                    $q->orWhereIn('nhom_id', $groupIds);
                }
            });

        $logs = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $logs
            ]
        ]);
    }

    /**
     * Get history logs for Admin with optional filters
     */
    public function getAdminHistory(Request $request)
    {
        $query = LichSuHoatDong::query();

        if ($request->filled('sinh_vien_id')) {
            $query->where('sinh_vien_id', $request->query('sinh_vien_id'));
        }

        if ($request->filled('ma_so_sinh_vien')) {
            $query->where('ma_so_sinh_vien', $request->query('ma_so_sinh_vien'));
        }

        if ($request->filled('nhom_id')) {
            $query->where('nhom_id', $request->query('nhom_id'));
        }

        if ($request->filled('action_type')) {
            $query->where('action_type', $request->query('action_type'));
        }

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $logs
            ]
        ]);
    }
}
