<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuanLyHoiDong\ThemHoiDongRequest;
use App\Models\Dot;
use App\Models\GiangVien;
use App\Models\HoiDong;
use App\Models\Nhom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HoiDongController extends Controller
{
    use KiemTraTrangThaiDot;

    public function layDanhSach(Request $request)
    {
        $councils = HoiDong::with(['giangViens', 'nhoms.members', 'nhoms.deTai'])->get();

        $rows = $councils->map(function ($hd) {
            return $this->transformCouncil($hd);
        })->all();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $rows,
            ],
        ], 200);
    }

    public function xemChiTiet(Request $request, $id)
    {
        $hd = HoiDong::with(['giangViens', 'nhoms.members', 'nhoms.deTai'])->find($id);
        if (! $hd) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hội đồng!',
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->transformCouncil($hd),
            ],
        ], 200);
    }

    public function themMoi(ThemHoiDongRequest $request)
    {

        $dotId = $request->input('dot_id') ?? $request->input('dotId');
        if (empty($dotId)) {
            $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : 1;
        }

        $dot = Dot::find($dotId);
        if ($resp = $this->chanNeuDotDaDong($dot)) {
            return $resp;
        }

        // Validate defense date range constraint
        if ($dot) {
            $ngayBaoVe = $request->date ?? $request->input('date');
            if ($ngayBaoVe) {
                $ngayBatDauBaoVe = $dot->ngay_bat_dau_bao_ve;
                $ngayKetThucBaoVe = $dot->ngay_ket_thuc_bao_ve;

                if ($ngayBatDauBaoVe && $ngayKetThucBaoVe) {
                    if ($ngayBaoVe < $ngayBatDauBaoVe || $ngayBaoVe > $ngayKetThucBaoVe) {
                        $startFormatted = date('d/m/Y', strtotime($ngayBatDauBaoVe));
                        $endFormatted = date('d/m/Y', strtotime($ngayKetThucBaoVe));
                        return response()->json([
                            'success' => false,
                            'message' => "Ngày bảo vệ phải nằm trong thời gian quy định {$startFormatted} - {$endFormatted}",
                        ], 422);
                    }
                }
            }
        }

        // Validate at least 5 members
        $members = $request->input('members', []);
        if (count($members) < 5) {
            return response()->json([
                'success' => false,
                'message' => 'Không đủ thành viên hội đồng ít nhất 5 thành viên',
            ], 422);
        }

        $chairId = $request->input('chair_id') ?? $request->input('chairId');
        $secretaryId = $request->input('secretary_id') ?? $request->input('secretaryId');

        if (empty($chairId)) {
            return response()->json([
                'success' => false,
                'message' => 'Chưa phân công Chủ tịch hội đồng.',
            ], 422);
        }

        if (empty($secretaryId)) {
            return response()->json([
                'success' => false,
                'message' => 'Chưa phân công Thư ký hội đồng.',
            ], 422);
        }

        if ($chairId === $secretaryId) {
            return response()->json([
                'success' => false,
                'message' => 'Chủ tịch và Thư ký không được trùng nhau.',
            ], 422);
        }

        if ($chairId) {
            $otherCouncilChair = DB::table('thanhvienhoidong')
                ->join('hoidong', 'thanhvienhoidong.hoi_dong_id', '=', 'hoidong.hoi_dong_id')
                ->join('giangvien', 'thanhvienhoidong.giang_vien_id', '=', 'giangvien.giang_vien_id')
                ->where('thanhvienhoidong.vai_tro', 'CHU_TICH')
                ->where('thanhvienhoidong.giang_vien_id', $chairId)
                ->select('hoidong.ten_hoi_dong', 'giangvien.ho_ten')
                ->first();

            if ($otherCouncilChair) {
                return response()->json([
                    'success' => false,
                    'message' => "giảng viên {$otherCouncilChair->ho_ten} đã làm chủ tịch tại {$otherCouncilChair->ten_hoi_dong}",
                ], 422);
            }
        }

        // Auto-generate name: "Hội đồng <số thứ tự>"
        $title = $request->title;
        if (empty($title) || !preg_match('/^Hội\s+đồng\s+(\d+)$/ui', $title)) {
            $maxNum = 0;
            $existingCouncils = HoiDong::all();
            foreach ($existingCouncils as $exHd) {
                if (preg_match('/^Hội\s+đồng\s+(\d+)$/ui', $exHd->ten_hoi_dong, $matches)) {
                    $num = (int)$matches[1];
                    if ($num > $maxNum) {
                        $maxNum = $num;
                    }
                }
            }
            $title = 'Hội đồng ' . ($maxNum + 1);
        }

        return DB::transaction(function () use ($request, $dotId, $title, $members, $chairId, $secretaryId) {
            $hd = HoiDong::create([
                'dot_id' => $dotId,
                'ten_hoi_dong' => $title,
                'ngay_bao_ve' => $request->date ?? date('Y-m-d'),
                'gio_bao_ve' => $request->time ?? '08:00–12:00',
                'phong_bao_ve' => $request->room,
                'trang_thai' => 'NHAP',
            ]);

            // Save members
            $topics = $request->input('topics', []);

            foreach ($members as $gvId) {
                $role = 'UY_VIEN';
                if ((string)$gvId === (string)$chairId) {
                    $role = 'CHU_TICH';
                } elseif ((string)$gvId === (string)$secretaryId) {
                    $role = 'THU_KY';
                } else {
                    $isReviewer = collect($topics)->contains('reviewerId', $gvId);
                    if ($isReviewer) {
                        $role = 'PHAN_BIEN';
                    }
                }
                DB::table('thanhvienhoidong')->insert([
                    'hoi_dong_id' => $hd->hoi_dong_id,
                    'giang_vien_id' => $gvId,
                    'vai_tro' => $role,
                ]);
            }

            // Save groups & schedule
            $nhomIds = collect($topics)->map(function($t) {
                return $t['nhom_id'] ?? $t['id'] ?? null;
            })->filter()->toArray();

            if (!empty($nhomIds)) {
                // Delete existing schedules for these groups from other councils
                DB::table('lichbaove')->whereIn('nhom_id', $nhomIds)->delete();
                // Update their old council to null
                DB::table('nhomsvda')->whereIn('nhom_id', $nhomIds)->update([
                    'hoi_dong_id' => null,
                ]);
            }

            foreach ($topics as $idx => $t) {
                if (empty($t)) {
                    continue;
                }
                $nhomId = $t['nhom_id'] ?? $t['id'] ?? null;
                if (! $nhomId) {
                    continue;
                }

                $status = $request->input('status', 'NHAP');
                $nhom = Nhom::find($nhomId);
                if ($status === 'DA_CONG_BO' && $nhom && $nhom->ket_qua_huong_dan !== 'DAT') {
                    throw new \Exception("Nhóm ID {$nhomId} chưa đạt đánh giá hướng dẫn (GVHD), không thể xếp vào hội đồng đã công bố!");
                }

                // Update group's council
                DB::table('nhomsvda')->where('nhom_id', $nhomId)->update([
                    'hoi_dong_id' => $hd->hoi_dong_id,
                ]);

                // Save schedule
                $time = null;
                if (! empty($t['start_time'] ?? $t['startTime'])) {
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
                        'external_examiners' => $t['externalExaminers'] ?? [],
                    ]),
                ]);
            }

            $hdLoad = HoiDong::with(['giangViens', 'nhoms.members', 'nhoms.deTai'])->find($hd->hoi_dong_id);

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformCouncil($hdLoad),
                ],
            ], 200);
        });
    }

    public function capNhat(Request $request, $id)
    {
        $hd = HoiDong::find($id);
        if (! $hd) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hội đồng!',
            ], 404);
        }

        $dot = $hd->dot;
        if ($resp = $this->chanNeuDotDaDong($dot)) {
            return $resp;
        }

        // Validate defense date range constraint
        if ($dot) {
            $ngayBaoVe = $request->input('date');
            if ($ngayBaoVe) {
                $ngayBatDauBaoVe = $dot->ngay_bat_dau_bao_ve;
                $ngayKetThucBaoVe = $dot->ngay_ket_thuc_bao_ve;

                if ($ngayBatDauBaoVe && $ngayKetThucBaoVe) {
                    if ($ngayBaoVe < $ngayBatDauBaoVe || $ngayBaoVe > $ngayKetThucBaoVe) {
                        $startFormatted = date('d/m/Y', strtotime($ngayBatDauBaoVe));
                        $endFormatted = date('d/m/Y', strtotime($ngayKetThucBaoVe));
                        return response()->json([
                            'success' => false,
                            'message' => "Ngày bảo vệ phải nằm trong thời gian quy định {$startFormatted} - {$endFormatted}",
                        ], 422);
                    }
                }
            }
        }

        // Validate at least 5 members if sent
        if ($request->has('members')) {
            $members = $request->input('members', []);
            if (count($members) < 5) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không đủ thành viên hội đồng ít nhất 5 thành viên',
                ], 422);
            }

            $chairId = $request->input('chair_id') ?? $request->input('chairId');
            $secretaryId = $request->input('secretary_id') ?? $request->input('secretaryId');

            if (empty($chairId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chưa phân công Chủ tịch hội đồng.',
                ], 422);
            }

            if (empty($secretaryId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chưa phân công Thư ký hội đồng.',
                ], 422);
            }

            if ($chairId === $secretaryId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chủ tịch và Thư ký không được trùng nhau.',
                ], 422);
            }

            if ($chairId) {
                $otherCouncilChair = DB::table('thanhvienhoidong')
                    ->join('hoidong', 'thanhvienhoidong.hoi_dong_id', '=', 'hoidong.hoi_dong_id')
                    ->join('giangvien', 'thanhvienhoidong.giang_vien_id', '=', 'giangvien.giang_vien_id')
                    ->where('thanhvienhoidong.vai_tro', 'CHU_TICH')
                    ->where('thanhvienhoidong.giang_vien_id', $chairId)
                    ->where('thanhvienhoidong.hoi_dong_id', '!=', $id)
                    ->select('hoidong.ten_hoi_dong', 'giangvien.ho_ten')
                    ->first();

                if ($otherCouncilChair) {
                    return response()->json([
                        'success' => false,
                        'message' => "giảng viên {$otherCouncilChair->ho_ten} đã làm chủ tịch tại {$otherCouncilChair->ten_hoi_dong}",
                    ], 422);
                }
            }
        }

        // Enforce/validate title format if updated
        $title = $request->input('title');
        if ($title !== null && (empty($title) || !preg_match('/^Hội\s+đồng\s+(\d+)$/ui', $title))) {
            return response()->json([
                'success' => false,
                'message' => 'Tên hội đồng phải có định dạng là Hội đồng <số thứ tự>',
            ], 422);
        }

        return DB::transaction(function () use ($request, $hd) {
            $hd->update([
                'ten_hoi_dong' => $request->input('title', $hd->ten_hoi_dong),
                'phong_bao_ve' => $request->input('room', $hd->phong_bao_ve),
                'ngay_bao_ve' => $request->input('date', $hd->ngay_bao_ve),
                'gio_bao_ve' => $request->input('time', $hd->gio_bao_ve),
                'trang_thai' => $request->input('status', $hd->trang_thai),
            ]);

            // Save members if sent
            if ($request->has('members')) {
                DB::table('thanhvienhoidong')->where('hoi_dong_id', $hd->hoi_dong_id)->delete();
                $members = $request->input('members', []);
                $topics = $request->input('topics', []);
                $chairId = $request->input('chair_id') ?? $request->input('chairId');
                $secretaryId = $request->input('secretary_id') ?? $request->input('secretaryId');

                foreach ($members as $gvId) {
                    $role = 'UY_VIEN';
                    if ((string)$gvId === (string)$chairId) {
                        $role = 'CHU_TICH';
                    } elseif ((string)$gvId === (string)$secretaryId) {
                        $role = 'THU_KY';
                    } else {
                        $isReviewer = collect($topics)->contains('reviewerId', $gvId);
                        if ($isReviewer) {
                            $role = 'PHAN_BIEN';
                        }
                    }
                    DB::table('thanhvienhoidong')->insert([
                        'hoi_dong_id' => $hd->hoi_dong_id,
                        'giang_vien_id' => $gvId,
                        'vai_tro' => $role,
                    ]);
                }
            }

            // Save topics if sent
            if ($request->has('topics')) {
                // Unassign all groups currently in this council
                DB::table('nhomsvda')->where('hoi_dong_id', $hd->hoi_dong_id)->update(['hoi_dong_id' => null]);
                DB::table('lichbaove')->where('hoi_dong_id', $hd->hoi_dong_id)->delete();

                $topics = $request->input('topics', []);
                $nhomIds = collect($topics)->map(function($t) {
                    return $t['nhom_id'] ?? $t['id'] ?? null;
                })->filter()->toArray();

                if (!empty($nhomIds)) {
                    // Delete existing schedules for these groups from other councils
                    DB::table('lichbaove')->whereIn('nhom_id', $nhomIds)->delete();
                }

                foreach ($topics as $idx => $t) {
                    if (empty($t)) {
                        continue;
                    }
                    $nhomId = $t['nhom_id'] ?? $t['id'] ?? null;
                    if (! $nhomId) {
                        continue;
                    }

                    $newStatus = $request->input('status', $hd->trang_thai);
                    $nhom = Nhom::find($nhomId);
                    if ($newStatus === 'DA_CONG_BO' && $nhom && $nhom->ket_qua_huong_dan !== 'DAT') {
                        throw new \Exception("Nhóm ID {$nhomId} chưa đạt đánh giá hướng dẫn (GVHD), không thể công bố hội đồng!");
                    }

                    // Update group's council
                    DB::table('nhomsvda')->where('nhom_id', $nhomId)->update([
                        'hoi_dong_id' => $hd->hoi_dong_id,
                    ]);

                    // Save schedule
                    $time = null;
                    if (! empty($t['start_time'] ?? $t['startTime'])) {
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
                            'external_examiners' => $t['externalExaminers'] ?? [],
                        ]),
                    ]);
                }
            }

            $hdLoad = HoiDong::with(['giangViens', 'nhoms.members', 'nhoms.deTai'])->find($hd->hoi_dong_id);

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformCouncil($hdLoad),
                ],
            ], 200);
        });
    }

    public function xoa(Request $request, $id)
    {
        $hd = HoiDong::find($id);
        if (! $hd) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hội đồng!',
            ], 404);
        }

        if ($resp = $this->chanNeuDotDaDong($hd->dot)) {
            return $resp;
        }

        DB::table('nhomsvda')->where('hoi_dong_id', $id)->update(['hoi_dong_id' => null]);
        DB::table('lichbaove')->where('hoi_dong_id', $id)->delete();
        DB::table('thanhvienhoidong')->where('hoi_dong_id', $id)->delete();
        $hd->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa hội đồng thành công!',
        ], 200);
    }

    private function transformCouncil($hd)
    {
        $chair = [];
        $reviewer = [];
        $member = [];
        $secretary = [];
        foreach ($hd->giangViens as $gv) {
            $role = $gv->pivot->vai_tro;
            $nameWithTitle = ($gv->hoc_vi ? $gv->hoc_vi.' ' : 'ThS. ').$gv->ho_ten;
            if ($role === 'CHU_TICH') {
                $chair[] = $nameWithTitle;
            } elseif ($role === 'PHAN_BIEN') {
                $reviewer[] = $nameWithTitle;
            } elseif ($role === 'TH' || $role === 'THU_KY') {
                $secretary[] = $nameWithTitle;
            } else {
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

        $nhoms = $hd->nhoms;
        if ($hd->trang_thai === 'DA_CONG_BO') {
            $nhoms = $nhoms->filter(function ($n) {
                return $n->ket_qua_huong_dan === 'DAT' && $n->ket_qua_phan_bien === 'DAT';
            });
        }

        foreach ($nhoms as $nhom) {
            $code = 'NH'.str_pad($nhom->nhom_id, 2, '0', STR_PAD_LEFT);
            $title = $nhom->deTai ? $nhom->deTai->ten_de_tai : 'Nhóm #'.$nhom->nhom_id;
            $membersCount = $nhom->members->count();

            $topicGroups[] = [
                'code' => $code,
                'title' => $title,
                'members' => $membersCount,
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
                                $examinerIds[] = ($exGv->hoc_vi ? $exGv->hoc_vi.' ' : 'ThS. ').$exGv->ho_ten;
                            }
                        }

                        // Also map external examiner IDs to names if they represent teacher IDs, or keep them if they are names
                        $rawExt = $decoded['external_examiners'] ?? [];
                        foreach ($rawExt as $eid) {
                            $extGv = $lecturers->get($eid);
                            if ($extGv) {
                                $externalExaminers[] = ($extGv->hoc_vi ? $extGv->hoc_vi.' ' : 'ThS. ').$extGv->ho_ten;
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
                    $reviewerName = ($revGv->hoc_vi ? $revGv->hoc_vi.' ' : 'ThS. ').$revGv->ho_ten;
                }
            }

            $studentsList = $nhom->members->map(function ($m) {
                return $m->ma_so_sinh_vien.' - '.$m->ho_ten;
            })->all();

            $topics[] = [
                'id' => (string) $nhom->nhom_id,
                'code' => $code,
                'title' => $title,
                'topicCode' => $code,
                'topicName' => $title,
                'members' => $studentsList,
                'advisorId' => ($nhom->deTai && $nhom->deTai->giangVien) ? (string) $nhom->deTai->giangVien->giang_vien_id : '—',
                'advisorName' => ($nhom->deTai && $nhom->deTai->giangVien) ? (($nhom->deTai->giangVien->hoc_vi ? $nhom->deTai->giangVien->hoc_vi.' ' : 'ThS. ').$nhom->deTai->giangVien->ho_ten) : '—',
                'minutes' => $minutes,
                'reviewerId' => (string) $reviewerId,
                'reviewer' => $reviewerName,
                'examinerIds' => $rawEx,
                'examiners' => $examinerIds,
                'externalExaminers' => $externalExaminers,
                'startTime' => $startTime,
            ];
        }

        $achieved = $hd->nhoms->filter(fn ($n) => $n->ket_qua_phan_bien === 'DAT' || $n->ket_qua_huong_dan === 'DAT')->count();
        $rejected = $hd->nhoms->filter(fn ($n) => $n->ket_qua_phan_bien === 'KHONG_DAT' || $n->ket_qua_huong_dan === 'KHONG_DAT')->count();

        if ($achieved === 0 && $rejected === 0) {
            $achieved = $hd->nhoms->count();
        }

        return [
            'id' => (string) $hd->hoi_dong_id,
            'status' => $hd->trang_thai ?? 'NHAP',
            'dot_id' => (string) $hd->dot_id,
            'batch' => $hd->dot ? $hd->dot->ten_dot : '—',
            'title' => $hd->ten_hoi_dong,
            'dateTime' => ($hd->ngay_bao_ve ? date('d/m/Y', strtotime($hd->ngay_bao_ve)) : date('d/m/Y')).($hd->gio_bao_ve ? ' · '.$hd->gio_bao_ve : ' · 08:00–12:00'),
            'room' => $hd->phong_bao_ve ?? '—',
            'achieved' => $achieved,
            'rejected' => $rejected,
            'chair' => $chair,
            'reviewer' => $reviewer,
            'member' => $member,
            'secretary' => $secretary,
            'topicGroups' => $topicGroups,
            'topics' => $topics,
            'accent' => $hd->hoi_dong_id % 2 === 0 ? 'green' : 'blue',
        ];
    }
}
