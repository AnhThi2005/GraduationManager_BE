<?php

namespace App\Http\Controllers\GiangVien;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Dot;
use App\Models\HoiDong;
use App\Models\Nhom;
use App\Models\SinhVien;

class DiemController extends Controller
{
    /**
     * GET /private/v1/teacher/grading
     */
    public function getGradingData(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $dotId = $request->input('periodId');
        if (empty($dotId)) {
            $latestPeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        // 1. TTTN rows
        $tttnRows = DB::table('phanconghdtt')
            ->where('phanconghdtt.giang_vien_id', $teacherId)
            ->where('phanconghdtt.dot_id', $dotId)
            ->join('sinhvien', 'phanconghdtt.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
            ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
            ->leftJoin('dangkythuctap', function ($join) use ($dotId) {
                $join->on('sinhvien.sinh_vien_id', '=', 'dangkythuctap.sinh_vien_id')
                     ->where('dangkythuctap.dot_id', '=', $dotId);
            })
            ->leftJoin('congty', 'dangkythuctap.cong_ty_id', '=', 'congty.cong_ty_id')
            ->leftJoin('diemthuctap', function ($join) use ($dotId) {
                $join->on('sinhvien.sinh_vien_id', '=', 'diemthuctap.sinh_vien_id')
                     ->where('diemthuctap.dot_id', '=', $dotId);
            })
            ->select([
                'sinhvien.ma_so_sinh_vien as id',
                'sinhvien.ho_ten as name',
                'sinhvien.ngay_sinh as dob',
                'lop.ten_lop as class',
                'congty.ten_cong_ty as company',
                'diemthuctap.diem_so as score',
                'diemthuctap.updated_at as updated_at'
            ])
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (string)$row->id,
                    'name' => $row->name,
                    'dob' => $row->dob ? date('d/m/Y', strtotime($row->dob)) : '—',
                    'class' => $row->class ?? '—',
                    'company' => $row->company ?? 'Chưa có công ty thực tập',
                    'score' => $row->score !== null ? (string)$row->score : '',
                    'updated_at' => $row->updated_at
                ];
            })
            ->all();

        // 2. Councils & Groups under this teacher
        $councils = HoiDong::where('dot_id', $dotId)
            ->whereHas('giangViens', function ($q) use ($teacherId) {
                $q->where('giangvien.giang_vien_id', $teacherId);
            })
            ->with(['giangViens', 'nhoms.members.lop', 'nhoms.deTai'])
            ->get();

        $councilGroups = [];
        $scoreRows = [];

        foreach ($councils as $hd) {
            $myPivot = $hd->giangViens->firstWhere('giang_vien_id', $teacherId);
            $roleText = 'Ủy viên';
            if ($myPivot) {
                if ($myPivot->pivot->vai_tro === 'CHU_TICH') {
                    $roleText = 'Chủ tịch';
                } elseif ($myPivot->pivot->vai_tro === 'PHAN_BIEN') {
                    $roleText = 'GVPB';
                }
            }

            $members = $hd->giangViens->map(function ($gv) {
                return [
                    'id' => (string)$gv->giang_vien_id,
                    'name' => $gv->ho_ten,
                    'role' => $gv->pivot->vai_tro === 'CHU_TICH' ? 'Chủ tịch' : ($gv->pivot->vai_tro === 'PHAN_BIEN' ? 'Ủy viên phản biên' : 'Ủy viên')
                ];
            })->all();

            $groups = [];
            foreach ($hd->nhoms as $g) {
                $studentsList = $g->members->map(function ($m) {
                    return [
                        'id' => (string)$m->ma_so_sinh_vien,
                        'name' => $m->ho_ten,
                        'class' => $m->lop ? $m->lop->ten_lop : '—'
                    ];
                })->all();

                $groups[] = [
                    'id' => (string)$g->nhom_id,
                    'groupCode' => 'G' . str_pad($g->nhom_id, 2, '0', STR_PAD_LEFT),
                    'topic' => $g->deTai ? $g->deTai->ten_de_tai : 'Nhóm #' . $g->nhom_id,
                    'students' => $studentsList
                ];

                foreach ($g->members as $m) {
                    $scoreRecord = DB::table('diemtongketdatn')->where('sinh_vien_id', $m->sinh_vien_id)->first();
                    $scoreRows[] = [
                        'id' => (string)$m->ma_so_sinh_vien,
                        'name' => $m->ho_ten,
                        'chair' => $scoreRecord ? (string)$scoreRecord->diem_bao_ve_rieng : '',
                        'secretary' => $scoreRecord ? (string)$scoreRecord->diem_bao_ve_rieng : '',
                        'member' => $scoreRecord ? (string)$scoreRecord->diem_bao_ve_rieng : '',
                        'advisor' => $scoreRecord ? (string)$scoreRecord->diem_bao_cao_chung : '',
                        'reviewer' => $scoreRecord ? (string)$scoreRecord->diem_bao_ve_rieng : ''
                    ];
                }
            }

            $done = 0;
            foreach ($hd->nhoms as $g) {
                $hasAllScores = true;
                foreach ($g->members as $m) {
                    $exists = DB::table('diemtongketdatn')->where('sinh_vien_id', $m->sinh_vien_id)->exists();
                    if (!$exists) {
                        $hasAllScores = false;
                        break;
                    }
                }
                if ($hasAllScores && $g->members->count() > 0) {
                    $done++;
                }
            }

            $councilGroups[] = [
                'code' => 'HD' . str_pad($hd->hoi_dong_id, 2, '0', STR_PAD_LEFT),
                'name' => $hd->ten_hoi_dong,
                'date' => $hd->ngay_bao_ve ? date('d/m/Y', strtotime($hd->ngay_bao_ve)) . ' • ' . ($hd->gio_bao_ve ?? '08:00') : '—',
                'room' => $hd->phong_bao_ve ?? '—',
                'role' => $roleText,
                'done' => $done,
                'total' => $hd->nhoms->count(),
                'members' => $members,
                'groups' => $groups
            ];
        }

        $lastUpdatedAt = null;
        foreach ($tttnRows as $row) {
            if (!empty($row['updated_at'])) {
                if ($lastUpdatedAt === null || strcmp($row['updated_at'], $lastUpdatedAt) > 0) {
                    $lastUpdatedAt = $row['updated_at'];
                }
            }
        }

        return response()->json([
            'success' => true,
            'tttnRows' => $tttnRows,
            'councilGroups' => $councilGroups,
            'scoreRows' => $scoreRows,
            'lastUpdatedAt' => $lastUpdatedAt ? date('Y-m-d H:i:s', strtotime($lastUpdatedAt)) : null
        ]);
    }

    /**
     * GET /private/v1/teacher/scores?group=groupId
     */
    public function getScores(Request $request)
    {
        $groupId = $request->input('group');
        if (empty($groupId)) {
            return response()->json(['success' => false, 'message' => 'GroupId is required.'], 400);
        }

        $group = Nhom::with('members')->find($groupId);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found.'], 404);
        }

        $rows = [];
        foreach ($group->members as $m) {
            $scoreRecord = DB::table('diemtongketdatn')->where('sinh_vien_id', $m->sinh_vien_id)->first();
            
            $presentation = null;
            $demo = null;
            $qna = null;
            $report = null;

            if ($scoreRecord) {
                $split = $this->splitDefenseScore($scoreRecord->diem_bao_ve_rieng);
                $presentation = $split['presentation'];
                $demo = $split['demo'];
                $qna = $split['qna'];
                $report = $scoreRecord->diem_bao_cao_chung !== null ? floatval($scoreRecord->diem_bao_cao_chung) : null;
            }

            $rows[] = [
                'id' => (string)$m->ma_so_sinh_vien,
                'name' => $m->ho_ten,
                'class' => $m->lop ? $m->lop->ten_lop : '—',
                'presentation' => $presentation,
                'demo' => $demo,
                'qna' => $qna,
                'report' => $report
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'rows' => $rows
            ]
        ]);
    }

    /**
     * POST /private/v1/teacher/scores
     */
    public function saveScores(Request $request)
    {
        $groupId = $request->input('group');
        $rows = $request->input('rows', []);

        if (empty($groupId)) {
            return response()->json(['success' => false, 'message' => 'GroupId is required.'], 400);
        }

        foreach ($rows as $row) {
            $studentCode = $row['id'] ?? '';
            $sv = SinhVien::where('ma_so_sinh_vien', $studentCode)->first();
            if (!$sv) continue;

            $presentation = isset($row['presentation']) ? floatval($row['presentation']) : 0;
            $demo = isset($row['demo']) ? floatval($row['demo']) : 0;
            $qna = isset($row['qna']) ? floatval($row['qna']) : 0;
            $report = isset($row['report']) ? floatval($row['report']) : 0;

            $defenseTotal = $presentation + $demo + $qna;
            $finalScore = round(($defenseTotal * 0.8) + ($report * 0.2), 1);
            $statusVal = $finalScore >= 5 ? 'DAT' : 'KHONG_DAT';

            DB::table('diemtongketdatn')->updateOrInsert(
                ['sinh_vien_id' => $sv->sinh_vien_id],
                [
                    'nhom_id' => $groupId,
                    'diem_bao_ve_rieng' => $defenseTotal,
                    'diem_bao_cao_chung' => $report,
                    'diem_tong_ket' => $finalScore,
                    'trang_thai' => $statusVal,
                    'updated_at' => now()
                ]
            );
        }

        \App\Services\RealtimeService::broadcast('notification', [
            'type' => 'score_updated',
            'groupId' => $groupId,
            'message' => 'Điểm hội đồng đồ án tốt nghiệp đã được cập nhật'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lưu điểm thành công.'
        ]);
    }

    /**
     * Helper to split defense score out of 10 into 3 parts (presentation: 3, demo: 5, qna: 2)
     */
    private function splitDefenseScore($totalScore)
    {
        $totalScore = floatval($totalScore);
        $defense = round($totalScore * 0.3, 2);
        $demo = round($totalScore * 0.5, 2);
        $qa = round($totalScore - $defense - $demo, 2);
        
        if ($defense > 3) {
            $diff = $defense - 3;
            $defense = 3;
            $demo += $diff;
        }
        if ($demo > 5) {
            $diff = $demo - 5;
            $demo = 5;
            $qa += $diff;
        }
        if ($qa > 2) {
            $qa = 2;
        }
        if ($qa < 0) {
            $qa = 0;
        }
        
        return [
            'presentation' => $defense,
            'demo' => $demo,
            'qna' => $qa
        ];
    }

    /**
     * POST /private/v1/teacher/tttn-scores
     */
    public function saveTttnScores(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;
        $dotId = $request->input('periodId');
        if (empty($dotId)) {
            $latestPeriod = \App\Models\Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        $scores = $request->input('scores', []);

        foreach ($scores as $s) {
            $studentCode = $s['id'] ?? '';
            $scoreVal = $s['score'] !== '' && $s['score'] !== null ? round(floatval($s['score']), 1) : null;

            $sv = \App\Models\SinhVien::where('ma_so_sinh_vien', $studentCode)->first();
            if (!$sv) continue;

            // Verify teacher is assigned to this student in this period
            $assigned = \Illuminate\Support\Facades\DB::table('phanconghdtt')
                ->where('giang_vien_id', $teacherId)
                ->where('sinh_vien_id', $sv->sinh_vien_id)
                ->where('dot_id', $dotId)
                ->exists();

            if (!$assigned) continue;

            if ($scoreVal === null) {
                \Illuminate\Support\Facades\DB::table('diemthuctap')
                    ->where('sinh_vien_id', $sv->sinh_vien_id)
                    ->where('dot_id', $dotId)
                    ->delete();
            } else {
                \Illuminate\Support\Facades\DB::table('diemthuctap')->updateOrInsert(
                    [
                        'sinh_vien_id' => $sv->sinh_vien_id,
                        'dot_id' => $dotId
                    ],
                    [
                        'giang_vien_id' => $teacherId,
                        'diem_so' => $scoreVal,
                        'updated_at' => now()
                    ]
                );
            }
        }

        \App\Services\RealtimeService::broadcast('notification', [
            'type' => 'tttn_score_updated',
            'periodId' => $dotId,
            'message' => 'Điểm thực tập tốt nghiệp đã được cập nhật'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lưu điểm thực tập thành công.'
        ]);
    }
}
