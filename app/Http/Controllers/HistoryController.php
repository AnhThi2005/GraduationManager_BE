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

        if ($request->filled('keyword')) {
            $keyword = trim($request->query('keyword'));
            $query->where(function ($q) use ($keyword) {
                $q->where('ma_so_sinh_vien', 'like', '%' . $keyword . '%')
                  ->orWhere('user_name', 'like', '%' . $keyword . '%');
            });
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

    /**
     * Get history logs for the authenticated teacher
     */
    public function getTeacherHistory(Request $request)
    {
        $teacher = $request->user();
        if (! $teacher) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        // Get all topic IDs proposed by this lecturer
        $topicIds = DB::table('detai')
            ->where('giang_vien_id', $teacher->giang_vien_id)
            ->pluck('de_tai_id')
            ->all();

        // Get all group IDs registered for these topics
        $groupIdsFromTopics = [];
        if (!empty($topicIds)) {
            $groupIdsFromTopics = DB::table('nhomsvda')
                ->whereIn('de_tai_id', $topicIds)
                ->pluck('nhom_id')
                ->all();
        }

        $query = LichSuHoatDong::query()
            ->where(function ($q) use ($teacher, $groupIdsFromTopics) {
                // Actor is the teacher
                $q->where(function ($sub) use ($teacher) {
                    $sub->where('role', 'giang_vien')
                        ->where('user_name', $teacher->ho_ten);
                });
                
                // Or actions related to the teacher's groups
                if (!empty($groupIdsFromTopics)) {
                    $q->orWhereIn('nhom_id', $groupIdsFromTopics);
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
}
