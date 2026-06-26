<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HoiDong;
use App\Models\GiangVien;
use App\Models\Dot;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\Admin\QuanLyHoiDong\ThemHoiDongRequest;

class HoiDongController extends Controller
{
    public function layDanhSach(Request $request)
    {
        $councils = HoiDong::with(['giangViens', 'nhoms.members', 'nhoms.deTai'])->get();

        $rows = $councils->map(function ($hd) {
            return $this->transformCouncil($hd);
        })->all();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $rows
            ]
        ], 200);
    }

    public function xemChiTiet(Request $request, $id)
    {
        $hd = HoiDong::with(['giangViens', 'nhoms.members', 'nhoms.deTai'])->find($id);
        if (!$hd) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hội đồng!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->transformCouncil($hd)
            ]
        ], 200);
    }

    public function themMoi(ThemHoiDongRequest $request)
    {

        $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
        $dotId = $activePeriod ? $activePeriod->dot_id : 1;

        return DB::transaction(function() use ($request, $dotId) {
            $hd = HoiDong::create([
                'dot_id' => $dotId,
                'ten_hoi_dong' => $request->title,
                'ngay_bao_ve' => $request->date ?? date('Y-m-d'),
                'gio_bao_ve' => $request->time ?? '08:00–12:00',
                'phong_bao_ve' => $request->room,
                'trang_thai' => 'NHAP'
            ]);

            // Save members
            $members = $request->input('members', []);
            $topics = $request->input('topics', []);

            foreach ($members as $idx => $gvId) {
                $role = 'UY_VIEN';
                if ($idx === 0) {
                    $role = 'CHU_TICH';
                } else {
                    $isReviewer = collect($topics)->contains('reviewerId', $gvId);
                    if ($isReviewer) {
                        $role = 'PHAN_BIEN';
                    }
                }
                DB::table('thanhvienhoidong')->insert([
                    'hoi_dong_id' => $hd->hoi_dong_id,
                    'giang_vien_id' => $gvId,
                    'vai_tro' => $role
                ]);
            }

            // Save groups & schedule
            foreach ($topics as $idx => $t) {
                $nhomId = $t['nhom_id'] ?? $t['id'] ?? null;
                if (!$nhomId) continue;

                // Update group's council
                DB::table('nhomsvda')->where('nhom_id', $nhomId)->update([
                    'hoi_dong_id' => $hd->hoi_dong_id
                ]);

                // Save schedule
                $time = null;
                if (!empty($t['start_time'] ?? $t['startTime'])) {
                    $time = date('H:i:s', strtotime($t['start_time'] ?? $t['startTime']));
                }

                DB::table('lichbaove')->insert([
                    'hoi_dong_id' => $hd->hoi_dong_id,
                    'nhom_id' => $nhomId,
                    'thoi_gian_bat_dau' => $time,
                    'thu_tu' => $idx + 1,
                    'ghi_chu' => json_encode([
                        'minutes' => $t['minutes'] ?? 40,
                        'reviewer_id' => $t['reviewerId'] ?? null,
                        'examiner_ids' => $t['examinerIds'] ?? [],
                        'external_examiners' => $t['externalExaminers'] ?? []
                    ])
                ]);
            }

            $hdLoad = HoiDong::with(['giangViens', 'nhoms.members', 'nhoms.deTai'])->find($hd->hoi_dong_id);
            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformCouncil($hdLoad)
                ]
            ], 200);
        });
    }

    public function capNhat(Request $request, $id)
    {
        $hd = HoiDong::find($id);
        if (!$hd) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hội đồng!'
            ], 404);
        }

        return DB::transaction(function() use ($request, $hd) {
            $hd->update([
                'ten_hoi_dong' => $request->input('title', $hd->ten_hoi_dong),
                'phong_bao_ve' => $request->input('room', $hd->phong_bao_ve),
                'ngay_bao_ve' => $request->input('date', $hd->ngay_bao_ve),
                'gio_bao_ve' => $request->input('time', $hd->gio_bao_ve),
            ]);

            // Save members if sent
            if ($request->has('members')) {
                DB::table('thanhvienhoidong')->where('hoi_dong_id', $hd->hoi_dong_id)->delete();
                $members = $request->input('members', []);
                $topics = $request->input('topics', []);

                foreach ($members as $idx => $gvId) {
                    $role = 'UY_VIEN';
                    if ($idx === 0) {
                        $role = 'CHU_TICH';
                    } else {
                        $isReviewer = collect($topics)->contains('reviewerId', $gvId);
                        if ($isReviewer) {
                            $role = 'PHAN_BIEN';
                        }
                    }
                    DB::table('thanhvienhoidong')->insert([
                        'hoi_dong_id' => $hd->hoi_dong_id,
                        'giang_vien_id' => $gvId,
                        'vai_tro' => $role
                    ]);
                }
            }

            // Save topics if sent
            if ($request->has('topics')) {
                // Unassign all groups currently in this council
                DB::table('nhomsvda')->where('hoi_dong_id', $hd->hoi_dong_id)->update(['hoi_dong_id' => null]);
                DB::table('lichbaove')->where('hoi_dong_id', $hd->hoi_dong_id)->delete();

                $topics = $request->input('topics', []);
                foreach ($topics as $idx => $t) {
                    $nhomId = $t['nhom_id'] ?? $t['id'] ?? null;
                    if (!$nhomId) continue;

                    // Update group's council
                    DB::table('nhomsvda')->where('nhom_id', $nhomId)->update([
                        'hoi_dong_id' => $hd->hoi_dong_id
                    ]);

                    // Save schedule
                    $time = null;
                    if (!empty($t['start_time'] ?? $t['startTime'])) {
                        $time = date('H:i:s', strtotime($t['start_time'] ?? $t['startTime']));
                    }

                    DB::table('lichbaove')->insert([
                        'hoi_dong_id' => $hd->hoi_dong_id,
                        'nhom_id' => $nhomId,
                        'thoi_gian_bat_dau' => $time,
                        'thu_tu' => $idx + 1,
                        'ghi_chu' => json_encode([
                            'minutes' => $t['minutes'] ?? 40,
                            'reviewer_id' => $t['reviewerId'] ?? null,
                            'examiner_ids' => $t['examinerIds'] ?? [],
                            'external_examiners' => $t['externalExaminers'] ?? []
                        ])
                    ]);
                }
            }

            $hdLoad = HoiDong::with(['giangViens', 'nhoms.members', 'nhoms.deTai'])->find($hd->hoi_dong_id);
            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformCouncil($hdLoad)
                ]
            ], 200);
        });
    }

    public function xoa(Request $request, $id)
    {
        $hd = HoiDong::find($id);
        if (!$hd) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hội đồng!'
            ], 404);
        }

        DB::table('thanhvienhoidong')->where('hoi_dong_id', $id)->delete();
        $hd->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa hội đồng thành công!'
        ], 200);
    }

    private function transformCouncil($hd)
    {
        $chair = [];
        $reviewer = [];
        $member = [];
        foreach ($hd->giangViens as $gv) {
            $role = $gv->pivot->vai_tro;
            $nameWithTitle = ($gv->hoc_vi ? $gv->hoc_vi . ' ' : 'ThS. ') . $gv->ho_ten;
            if ($role === 'CHU_TICH') {
                $chair[] = $nameWithTitle;
            } elseif ($role === 'PHAN_BIEN') {
                $reviewer[] = $nameWithTitle;
            } elseif ($role === 'UY_VIEN') {
                $member[] = $nameWithTitle;
            }
        }

        // Load schedule from lichbaove
        $schedules = DB::table('lichbaove')
            ->where('hoi_dong_id', $hd->hoi_dong_id)
            ->get()
            ->keyBy('nhom_id');

        // Fetch all lecturers to look up names
        $lecturers = GiangVien::all()->keyBy('giang_vien_id');

        $topics = [];
        $topicGroups = [];

        foreach ($hd->nhoms as $nhom) {
            $code = 'NH' . str_pad($nhom->nhom_id, 2, '0', STR_PAD_LEFT);
            $title = $nhom->deTai ? $nhom->deTai->ten_de_tai : 'Nhóm #' . $nhom->nhom_id;
            $membersCount = $nhom->members->count();

            $topicGroups[] = [
                'code' => $code,
                'title' => $title,
                'members' => $membersCount
            ];

            // Reconstruct topic detailed info
            $sched = $schedules->get($nhom->nhom_id);
            $minutes = 40;
            $reviewerId = null;
            $rawEx = [];
            $examinerIds = [];
            $externalExaminers = [];
            $startTime = null;

            if ($sched) {
                if ($sched->thoi_gian_bat_dau) {
                    $startTime = date('H:i', strtotime($sched->thoi_gian_bat_dau));
                }
                if ($sched->ghi_chu) {
                    $decoded = json_decode($sched->ghi_chu, true);
                    if (is_array($decoded)) {
                        $minutes = $decoded['minutes'] ?? 40;
                        $reviewerId = $decoded['reviewer_id'] ?? null;
                        
                        $rawEx = $decoded['examiner_ids'] ?? [];
                        foreach ($rawEx as $eid) {
                            $exGv = $lecturers->get($eid);
                            if ($exGv) {
                                $examinerIds[] = ($exGv->hoc_vi ? $exGv->hoc_vi . ' ' : 'ThS. ') . $exGv->ho_ten;
                            }
                        }

                        // Also map external examiner IDs to names if they represent teacher IDs, or keep them if they are names
                        $rawExt = $decoded['external_examiners'] ?? [];
                        foreach ($rawExt as $eid) {
                            $extGv = $lecturers->get($eid);
                            if ($extGv) {
                                $externalExaminers[] = ($extGv->hoc_vi ? $extGv->hoc_vi . ' ' : 'ThS. ') . $extGv->ho_ten;
                            } else {
                                $externalExaminers[] = $eid;
                            }
                        }
                    }
                }
            }

            $reviewerName = null;
            if ($reviewerId) {
                $revGv = $lecturers->get($reviewerId);
                if ($revGv) {
                    $reviewerName = ($revGv->hoc_vi ? $revGv->hoc_vi . ' ' : 'ThS. ') . $revGv->ho_ten;
                }
            }

            $studentsList = $nhom->members->map(function($m) {
                return $m->ma_so_sinh_vien . ' - ' . $m->ho_ten;
            })->all();

            $topics[] = [
                'id' => (string) $nhom->nhom_id,
                'code' => $code,
                'title' => $title,
                'topicCode' => $code,
                'topicName' => $title,
                'members' => $studentsList,
                'advisorId' => ($nhom->deTai && $nhom->deTai->giangVien) ? $nhom->deTai->giangVien->ho_ten : '—',
                'minutes' => $minutes,
                'reviewerId' => (string) $reviewerId,
                'reviewer' => $reviewerName,
                'examinerIds' => $rawEx,
                'examiners' => $examinerIds,
                'externalExaminers' => $externalExaminers,
                'startTime' => $startTime
            ];
        }

        $achieved = $hd->nhoms->filter(fn($n) => $n->ket_qua_phan_bien === 'DAT' || $n->ket_qua_huong_dan === 'DAT')->count();
        $rejected = $hd->nhoms->filter(fn($n) => $n->ket_qua_phan_bien === 'KHONG_DAT' || $n->ket_qua_huong_dan === 'KHONG_DAT')->count();

        if ($achieved === 0 && $rejected === 0) {
            $achieved = $hd->nhoms->count();
        }

        return [
            'id' => (string) $hd->hoi_dong_id,
            'title' => $hd->ten_hoi_dong,
            'dateTime' => ($hd->ngay_bao_ve ? date('d/m/Y', strtotime($hd->ngay_bao_ve)) : date('d/m/Y')) . ($hd->gio_bao_ve ? ' · ' . $hd->gio_bao_ve : ' · 08:00–12:00'),
            'room' => $hd->phong_bao_ve ?? '—',
            'achieved' => $achieved,
            'rejected' => $rejected,
            'chair' => $chair,
            'reviewer' => $reviewer,
            'member' => $member,
            'topicGroups' => $topicGroups,
            'topics' => $topics,
            'accent' => $hd->hoi_dong_id % 2 === 0 ? 'green' : 'blue'
        ];
    }
}
