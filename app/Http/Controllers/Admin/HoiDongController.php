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
        $councils = HoiDong::orderBy('hoi_dong_id', 'desc')->with(['giangViens', 'nhoms.members', 'nhoms.deTai'])->get();

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
        }

        try {
            $this->validateNameAndRoomConflicts(
                $title,
                $request->input('room'),
                $request->input('date'),
                $request->input('time')
            );
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return DB::transaction(function () use ($request, $dotId, $title, $members, $chairId, $secretaryId, $dot) {
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

                $nhom = Nhom::find($nhomId);
                $this->validateNhomDotConstraint($nhom, $dot);

                // Update group's council
                DB::table('nhomsvda')->where('nhom_id', $nhomId)->update([
                    'hoi_dong_id' => $hd->hoi_dong_id,
                ]);

                // Save schedule
                $time = null;
                if (! empty($t['start_time'] ?? $t['startTime'])) {
                    $time = date('H:i:s', strtotime($t['start_time'] ?? $t['startTime']));
                }

                $examinerId = $t['examinerId'] ?? (isset($t['examinerIds']) && is_array($t['examinerIds']) ? (reset($t['examinerIds']) ?: null) : null);
                DB::table('lichbaove')->insert([
                    'hoi_dong_id' => $hd->hoi_dong_id,
                    'nhom_id' => $nhomId,
                    'giang_vien_pb_id' => $t['reviewerId'] ?? null,
                    'giang_vien_cham_id' => json_encode($t['examinerIds'] ?? []),
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

        try {
            $this->validateNameAndRoomConflicts(
                $request->input('title', $hd->ten_hoi_dong),
                $request->input('room', $hd->phong_bao_ve),
                $request->input('date', $hd->ngay_bao_ve),
                $request->input('time', $hd->gio_bao_ve),
                $hd->hoi_dong_id
            );
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return DB::transaction(function () use ($request, $hd, $dot) {
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

                    $nhom = Nhom::find($nhomId);
                    $this->validateNhomDotConstraint($nhom, $dot);

                    // Update group's council
                    DB::table('nhomsvda')->where('nhom_id', $nhomId)->update([
                        'hoi_dong_id' => $hd->hoi_dong_id,
                    ]);

                    // Save schedule
                    $time = null;
                    if (! empty($t['start_time'] ?? $t['startTime'])) {
                        $time = date('H:i:s', strtotime($t['start_time'] ?? $t['startTime']));
                    }

                    $examinerId = $t['examinerId'] ?? (isset($t['examinerIds']) && is_array($t['examinerIds']) ? (reset($t['examinerIds']) ?: null) : null);
                    DB::table('lichbaove')->insert([
                        'hoi_dong_id' => $hd->hoi_dong_id,
                        'nhom_id' => $nhomId,
                        'giang_vien_pb_id' => $t['reviewerId'] ?? null,
                        'giang_vien_cham_id' => json_encode($t['examinerIds'] ?? []),
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

        $dot = $hd->dot;
        if ($dot) {
            $hasGroups = DB::table('nhomsvda')->where('hoi_dong_id', $id)->exists();
            if ($hasGroups) {
                $now = date('Y-m-d');
                $ngayBatDauPhanBien = $dot->ngay_bat_dau_phan_bien;
                $ngayKetThuc = $dot->ngay_ket_thuc;

                if ($ngayBatDauPhanBien && $ngayKetThuc && $now >= $ngayBatDauPhanBien && $now <= $ngayKetThuc) {
                    $phase = 'chấm điểm/phản biện';

                    $ngayBatDauBaoVe = $dot->ngay_bat_dau_bao_ve;
                    $ngayKetThucBaoVe = $dot->ngay_ket_thuc_bao_ve;
                    $ngayBatDauChamDiem = $dot->ngay_bat_dau_cham_diem;
                    $ngayKetThucChamDiem = $dot->ngay_ket_thuc_cham_diem;

                    if ($ngayBatDauChamDiem && $ngayKetThucChamDiem && $now >= $ngayBatDauChamDiem && $now <= $ngayKetThucChamDiem) {
                        $phase = 'chấm điểm/tổng kết';
                    } elseif ($ngayBatDauBaoVe && $ngayKetThucBaoVe && $now >= $ngayBatDauBaoVe && $now <= $ngayKetThucBaoVe) {
                        $phase = 'bảo vệ hội đồng';
                    } elseif ($ngayBatDauPhanBien && $dot->ngay_ket_thuc_phan_bien && $now >= $ngayBatDauPhanBien && $now <= $dot->ngay_ket_thuc_phan_bien) {
                        $phase = 'giao nhận/đọc phản biện';
                    }

                    $startFormatted = date('d/m/Y', strtotime($ngayBatDauPhanBien));
                    $endFormatted = date('d/m/Y', strtotime($ngayKetThuc));

                    return response()->json([
                        'success' => false,
                        'message' => "Hội đồng đang trong quá trình {$phase} (từ {$startFormatted} đến {$endFormatted}) không thể xóa.",
                    ], 422);
                }
            }
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

    private function validateNhomDotConstraint($nhom, $dot)
    {
        if (!$nhom || !$dot) {
            return;
        }

        $now = date('Y-m-d');
        $ngayBatDau = $dot->ngay_bat_dau;
        $ngayBatDauPhanBien = $dot->ngay_bat_dau_phan_bien;

        $kqHd = $nhom->ket_qua_huong_dan;
        $kqPb = $nhom->ket_qua_phan_bien;

        $topicName = $nhom->deTai ? $nhom->deTai->ten_de_tai : 'Nhóm #' . $nhom->nhom_id;

        if ($ngayBatDauPhanBien && $now >= $ngayBatDauPhanBien) {
            // Từ ngày bắt đầu phản biện trở đi: Bắt buộc cả hai phải là DAT
            if ($kqHd !== 'DAT' || $kqPb !== 'DAT') {
                throw new \Exception("Nhóm đề tài '{$topicName}' phải có kết quả Hướng dẫn và Phản biện đạt (DAT) kể từ giai đoạn phản biện!");
            }
        } else {
            // Trước ngày bắt đầu phản biện: Hướng dẫn và Phản biện phải là null hoặc DAT (không được KHONG_DAT)
            $isHdValid = is_null($kqHd) || $kqHd === 'DAT';
            $isPbValid = is_null($kqPb) || $kqPb === 'DAT';
            if (!$isHdValid || !$isPbValid) {
                throw new \Exception("Nhóm đề tài '{$topicName}' có kết quả không đạt, không thể xếp vào hội đồng!");
            }
        }
    }

    private function parseTimeRange($timeStr)
    {
        if (empty($timeStr)) {
            return [0, 86400]; // Default to all day if empty
        }
        // Replace different dashes with a standard dash
        $timeStr = str_replace(['–', '—', 'to', '-'], '-', $timeStr);
        $parts = explode('-', $timeStr);
        if (count($parts) === 2) {
            $start = strtotime(trim($parts[0]));
            $end = strtotime(trim($parts[1]));
            if ($start !== false && $end !== false) {
                $startSec = date('H', $start) * 3600 + date('i', $start) * 60;
                $endSec = date('H', $end) * 3600 + date('i', $end) * 60;
                return [$startSec, $endSec];
            }
        } else {
            $start = strtotime(trim($timeStr));
            if ($start !== false) {
                $startSec = date('H', $start) * 3600 + date('i', $start) * 60;
                return [$startSec, $startSec + 4 * 3600];
            }
        }
        return [0, 86400];
    }

    private function isTimeOverlapping($timeStr1, $timeStr2)
    {
        list($start1, $end1) = $this->parseTimeRange($timeStr1);
        list($start2, $end2) = $this->parseTimeRange($timeStr2);
        return ($start1 < $end2 && $start2 < $end1);
    }

    private function validateNameAndRoomConflicts($title, $room, $date, $time, $excludeId = null)
    {
        // 1. Check duplicate name (case-insensitive)
        if ($title) {
            $query = HoiDong::whereRaw('LOWER(ten_hoi_dong) = ?', [strtolower($title)]);
            if ($excludeId) {
                $query->where('hoi_dong_id', '!=', $excludeId);
            }
            if ($query->exists()) {
                throw new \Exception("Tên hội đồng '{$title}' đã tồn tại trong hệ thống (không phân biệt hoa thường)!");
            }
        }

        // 2. Check room scheduling conflict
        if ($room && $date && $time) {
            $query = HoiDong::where('phong_bao_ve', $room)
                ->where('ngay_bao_ve', $date);
            if ($excludeId) {
                $query->where('hoi_dong_id', '!=', $excludeId);
            }
            $otherCouncils = $query->get();

            foreach ($otherCouncils as $otherHd) {
                if ($this->isTimeOverlapping($time, $otherHd->gio_bao_ve)) {
                    $hasScheduledGroups = DB::table('lichbaove')
                        ->where('hoi_dong_id', $otherHd->hoi_dong_id)
                        ->exists();

                    if ($hasScheduledGroups) {
                        $dateFormatted = date('d/m/Y', strtotime($date));
                        throw new \Exception("Phòng '{$room}' vào thời gian {$time} ngày {$dateFormatted} đã trùng lịch bảo vệ với hội đồng '{$otherHd->ten_hoi_dong}'!");
                    }
                }
            }
        }
    }

    private function transformCouncil($hd)
    {
        $chair = [];
        $reviewer = [];
        $member = [];
        $secretary = [];
        foreach ($hd->giangViens as $gv) {
            $role = $gv->pivot->vai_tro;
            $nameWithTitle = $gv->ho_ten;
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
        $dot = $hd->dot;

        if ($dot) {
            $now = date('Y-m-d');
            $ngayBatDau = $dot->ngay_bat_dau;
            $ngayBatDauPhanBien = $dot->ngay_bat_dau_phan_bien;

            $nhoms = $nhoms->filter(function ($n) use ($now, $ngayBatDau, $ngayBatDauPhanBien) {
                $kqHd = $n->ket_qua_huong_dan;
                $kqPb = $n->ket_qua_phan_bien;

                if ($ngayBatDauPhanBien && $now >= $ngayBatDauPhanBien) {
                    return $kqHd === 'DAT' && $kqPb === 'DAT';
                } else {
                    $isHdValid = is_null($kqHd) || $kqHd === 'DAT';
                    $isPbValid = is_null($kqPb) || $kqPb === 'DAT';
                    return $isHdValid && $isPbValid;
                }
            });
        }

        foreach ($nhoms as $nhom) {
            $code = null;
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
            $examinerId = null;
            $rawEx = [];
            $examinerIds = [];
            $externalExaminers = [];
            $startTime = null;

            if ($sched) {
                if ($sched->thoi_gian_bat_dau) {
                    $startTime = date('H:i', strtotime($sched->thoi_gian_bat_dau));
                }
                $reviewerId = $sched->giang_vien_pb_id;
                $examinerIdVal = $sched->giang_vien_cham_id;
                $examinerId = null;
                if ($examinerIdVal) {
                    $decodedEx = json_decode($examinerIdVal, true);
                    if (is_array($decodedEx)) {
                        $rawEx = $decodedEx;
                        $examinerId = reset($decodedEx) ?: null;
                    } else {
                        $examinerId = $examinerIdVal;
                    }
                }

                if ($sched->ghi_chu) {
                    $decoded = json_decode($sched->ghi_chu, true);
                    if (is_array($decoded)) {
                        $minutes = $decoded['minutes'] ?? 40;
                        if (empty($reviewerId)) {
                            $reviewerId = $decoded['reviewer_id'] ?? null;
                        }

                        if (empty($rawEx)) {
                            $rawEx = $decoded['examiner_ids'] ?? [];
                        }
                        if (empty($examinerId) && !empty($rawEx)) {
                            $examinerId = reset($rawEx) ?: null;
                        }
                    }
                }

                // Resolve name strings to numeric IDs for backward compatibility
                if ($reviewerId && !is_numeric($reviewerId)) {
                    $foundGv = GiangVien::where('ho_ten', $reviewerId)->first();
                    if ($foundGv) {
                        $reviewerId = (string) $foundGv->giang_vien_id;
                    }
                }
                if ($examinerId && !is_numeric($examinerId)) {
                    $foundGv = GiangVien::where('ho_ten', $examinerId)->first();
                    if ($foundGv) {
                        $examinerId = (string) $foundGv->giang_vien_id;
                    }
                }
                $rawExMapped = [];
                foreach ($rawEx as $exIdOrName) {
                    if (is_numeric($exIdOrName)) {
                        $rawExMapped[] = (string) $exIdOrName;
                    } else {
                        $foundGv = GiangVien::where('ho_ten', $exIdOrName)->first();
                        if ($foundGv) {
                            $rawExMapped[] = (string) $foundGv->giang_vien_id;
                        } else {
                            $rawExMapped[] = $exIdOrName;
                        }
                    }
                }
                $rawEx = $rawExMapped;
            }

            // Map examiner names and lists
            if (!empty($rawEx)) {
                $rawExMapped = [];
                foreach ($rawEx as $eid) {
                    if ($eid && !is_numeric($eid)) {
                        $foundGv = GiangVien::where('ho_ten', $eid)->first();
                        $eid = $foundGv ? (string) $foundGv->giang_vien_id : $eid;
                    }
                    $exGv = $lecturers->get($eid);
                    if ($exGv) {
                        $examinerIds[] = $exGv->ho_ten;
                        $rawExMapped[] = (string) $eid;
                    } else {
                        $examinerIds[] = $eid;
                        $rawExMapped[] = (string) $eid;
                    }
                }
                $rawEx = $rawExMapped;
            } elseif ($examinerId) {
                if ($examinerId && !is_numeric($examinerId)) {
                    $foundGv = GiangVien::where('ho_ten', $examinerId)->first();
                    $examinerId = $foundGv ? (string) $foundGv->giang_vien_id : $examinerId;
                }
                $rawEx = [$examinerId];
                $exGv = $lecturers->get($examinerId);
                if ($exGv) {
                    $examinerIds[] = $exGv->ho_ten;
                } else {
                    $examinerIds[] = $examinerId;
                }
            }

            // High-quality auto-fill: if rawEx has less than 2 elements (e.g. old data stored only 1 examiner),
            // we can automatically fill it with the other eligible members of the council.
            $advisorId = ($nhom->deTai && $nhom->deTai->giangVien) ? (string) $nhom->deTai->giangVien->giang_vien_id : null;
            if (count($rawEx) < 2) {
                // Find all council members
                $councilMemberIds = $hd->giangViens->map(fn($gv) => (string)$gv->giang_vien_id)->all();
                
                // Eligible members are those who are not the advisor and not the reviewer
                $eligibleMemberIds = array_filter($councilMemberIds, function($mid) use ($advisorId, $reviewerId) {
                    return $mid !== $advisorId && $mid !== (string)$reviewerId;
                });
                $eligibleMemberIds = array_values($eligibleMemberIds);
                
                if (count($rawEx) === 1) {
                    $savedId = $rawEx[0];
                    if (in_array($savedId, $eligibleMemberIds)) {
                        $rawEx = $eligibleMemberIds;
                    } else {
                        $rawEx = array_merge([$savedId], array_slice($eligibleMemberIds, 1));
                    }
                } else {
                    $rawEx = $eligibleMemberIds;
                }
                
                // Re-map the names
                $examinerIds = [];
                foreach ($rawEx as $eid) {
                    $exGv = $lecturers->get($eid);
                    if ($exGv) {
                        $examinerIds[] = $exGv->ho_ten;
                    } else {
                        $examinerIds[] = $eid;
                    }
                }
            }

            if ($sched && $sched->ghi_chu) {
                $decoded = json_decode($sched->ghi_chu, true);
                if (is_array($decoded)) {
                    // Also map external examiner IDs to names if they represent teacher IDs, or keep them if they are names
                    $rawExt = $decoded['external_examiners'] ?? [];
                    foreach ($rawExt as $eid) {
                        $extGv = $lecturers->get($eid);
                        if ($extGv) {
                            $externalExaminers[] = $extGv->ho_ten;
                        } else {
                            $externalExaminers[] = $eid;
                        }
                    }
                }
            }

            $reviewerName = null;
            if ($reviewerId) {
                $revGv = $lecturers->get($reviewerId);
                if ($revGv) {
                    $reviewerName = $revGv->ho_ten;
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
                'advisorName' => ($nhom->deTai && $nhom->deTai->giangVien) ? $nhom->deTai->giangVien->ho_ten : '—',
                'minutes' => $minutes,
                'reviewerId' => (string) $reviewerId,
                'reviewer' => $reviewerName,
                'examinerId' => $examinerId ? (string) $examinerId : null,
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
