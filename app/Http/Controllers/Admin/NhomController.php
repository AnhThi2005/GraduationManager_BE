<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use App\Models\DeTai;
use App\Models\Dot;
use App\Models\LichSuHoatDong;
use App\Models\Nhom;
use App\Models\SinhVien;
use App\Services\RealtimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NhomController extends Controller
{
    use KiemTraTrangThaiDot;

    public function layDanhSach(Request $request)
    {
        $groups = Nhom::orderBy('nhom_id', 'desc')->with(['deTai.giangVien', 'deTai.huongDeTais', 'members.lop', 'dot'])
            ->whereNotNull('de_tai_id')
            ->get();

        // Gộp tra cứu dot_sinhvien (điều kiện làm đồ án theo từng đợt) của TOÀN BỘ nhóm
        // trong danh sách thành 1 query duy nhất, thay vì 1 query/nhóm trong transformGroup
        // — tránh N+1 khi số nhóm tăng.
        $dotStudentPairs = $groups->flatMap(function ($g) {
            return $g->members->map(fn ($m) => ['dot_id' => $g->dot_id, 'sinh_vien_id' => $m->sinh_vien_id]);
        });
        $dotStudentsAll = null;
        if ($dotStudentPairs->isNotEmpty()) {
            $dotStudentsAll = DB::table('dot_sinhvien')
                ->whereIn('dot_id', $dotStudentPairs->pluck('dot_id')->unique()->values())
                ->whereIn('sinh_vien_id', $dotStudentPairs->pluck('sinh_vien_id')->unique()->values())
                ->get()
                ->keyBy(fn ($row) => $row->dot_id.'-'.$row->sinh_vien_id);
        }

        $rows = $groups->map(function ($g) use ($dotStudentsAll) {
            return $this->transformGroup($g, $dotStudentsAll);
        })->all();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $rows,
                    'total' => count($rows),
                ],
            ],
        ], 200);
    }

    public function xemChiTiet(Request $request, $id)
    {
        $g = Nhom::with(['deTai.giangVien', 'deTai.huongDeTais', 'members.lop', 'dot'])->find($id);
        if (! $g) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm này!',
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->transformGroup($g),
            ],
        ], 200);
    }

    public function capNhat(Request $request, $id)
    {
        $g = Nhom::with(['deTai.giangVien', 'deTai.huongDeTais', 'members.lop', 'dot'])->find($id);
        if (! $g) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm!',
            ], 404);
        }

        if ($resp = $this->chanNeuDotDaDong($g->dot)) {
            return $resp;
        }

        $oldGroup = $this->transformGroup($g);
        $body = $request->all();

        // 1. Cập nhật trạng thái duyệt nếu có truyền lên
        if (isset($body['status'])) {
            $status = $body['status'];
            $dbStatus = 'CHO_DUYET';
            if ($status === 'APPROVED') {
                $g->trang_thai_duyet = 'DA_DUYET';
                $dbStatus = 'DA_DUYET';

                $dangkydetai = DB::table('dangkydetai')->where('nhom_id', $id)->first();
                if ($dangkydetai) {
                    $g->de_tai_id = $dangkydetai->de_tai_id;
                }
            } elseif ($status === 'LOCKED' || $status === 'DISSOLVED') {
                $g->trang_thai_duyet = 'TU_CHOI';
                $dbStatus = 'TU_CHOI';
                $g->de_tai_id = null;
            } elseif ($status === 'PENDING') {
                $g->trang_thai_duyet = $g->de_tai_id ? 'CHO_DUYET' : 'CHUA_DANG_KY';
                $dbStatus = $g->de_tai_id ? 'CHO_DUYET' : 'CHUA_DANG_KY';
            }
            $g->save();
            DB::table('dangkydetai')->where('nhom_id', $id)->update(['trang_thai_duyet' => $dbStatus]);
        }

        // 3. Cập nhật đề tài nếu có truyền lên (gán đề tài)
        if (isset($body['de_tai_id'])) {
            $g->de_tai_id = $body['de_tai_id'];

            $topicStatus = null;
            if ($g->de_tai_id) {
                $dt = DB::table('detai')->where('de_tai_id', $g->de_tai_id)->first();
                $topicStatus = $dt ? $dt->trang_thai : 'CHO_DUYET';
            }

            if ($g->de_tai_id) {
                if ($topicStatus === 'DA_DUYET') {
                    $g->trang_thai_duyet = 'DA_DUYET';
                } elseif ($topicStatus === 'TU_CHOI') {
                    $g->trang_thai_duyet = 'TU_CHOI';
                } else {
                    $g->trang_thai_duyet = 'CHO_DUYET';
                }
            } else {
                $g->trang_thai_duyet = 'CHUA_DANG_KY';
            }

            $g->save();

            DB::table('dangkydetai')->updateOrInsert(
                ['nhom_id' => $id],
                [
                    'de_tai_id' => $body['de_tai_id'],
                    'trang_thai_duyet' => $g->trang_thai_duyet,
                    'ngay_dang_ky' => date('Y-m-d H:i:s'),
                ]
            );
        }

        // 2a. Hoán đổi thành viên (nếu FE gửi kèm swapStudentIdA/B lúc bấm "Cập nhật" của
        // form hoán đổi xem-trước) - tách riêng khỏi khối "members" bên dưới vì hoán đổi cần
        // cập nhật CẢ 2 nhóm (nhóm này + nhóm đối phương), không chỉ ghi đè members của 1 nhóm.
        if (! empty($body['swapStudentIdA']) && ! empty($body['swapStudentIdB'])) {
            $swapResult = $this->performMemberSwap($body['swapStudentIdA'], $body['swapStudentIdB'], $request->user());
            if (! $swapResult['success']) {
                return response()->json(['success' => false, 'message' => $swapResult['message']], $swapResult['status'] ?? 400);
            }
        } elseif (isset($body['members']) && is_array($body['members'])) {
            // Xóa thành viên cũ
            DB::table('thanhviennhom')->where('nhom_id', $id)->delete();

            // Thêm thành viên mới
            foreach ($body['members'] as $idx => $m) {
                // $m có thể là sinh_vien_id (id) hoặc MSSV
                $studentId = $m['id'] ?? $m;
                $student = SinhVien::where('sinh_vien_id', $studentId)
                    ->orWhere('ma_so_sinh_vien', $studentId)
                    ->first();

                if ($student) {
                    $otherGroupIdsInPeriod = DB::table('nhomsvda')
                        ->where('dot_id', $g->dot_id)
                        ->pluck('nhom_id');

                    DB::table('thanhviennhom')
                        ->whereIn('nhom_id', $otherGroupIdsInPeriod)
                        ->where('sinh_vien_id', $student->sinh_vien_id)
                        ->delete();

                    DB::table('thanhviennhom')->insert([
                        'nhom_id' => $id,
                        'sinh_vien_id' => $student->sinh_vien_id,
                        'la_truong_nhom' => $idx === 0 ? 1 : 0,
                        'dieu_kien_lam_do_an' => 'DAT',
                    ]);
                }
            }
        }

        $updated = Nhom::with(['deTai.giangVien', 'deTai.huongDeTais', 'members.lop', 'dot'])->find($id);
        $newGroup = $this->transformGroup($updated);

        Log::info(sprintf(
            'AUDIT LOG: [UPDATE GROUP] Group ID: %s, Old Status: %s, New Status: %s, Old Members: %s, New Members: %s, IP: %s',
            $id,
            $oldGroup['status'],
            $newGroup['status'],
            json_encode(collect($oldGroup['members'])->map(fn ($m) => "{$m['code']}-{$m['name']}")->all(), JSON_UNESCAPED_UNICODE),
            json_encode(collect($newGroup['members'])->map(fn ($m) => "{$m['code']}-{$m['name']}")->all(), JSON_UNESCAPED_UNICODE),
            $request->ip()
        ));

        $admin = $request->user();
        LichSuHoatDong::ghiLog(
            'CAP_NHAT_NHOM',
            'Admin '.($admin ? $admin->ho_ten : 'Hệ thống').' đã cập nhật thông tin nhóm.',
            null,
            null,
            $id,
            'admin',
            $admin ? $admin->ho_ten : 'Hệ thống',
            ['old' => $oldGroup, 'new' => $newGroup]
        );

        RealtimeService::broadcast('slot_updated', [
            'type' => 'group_updated',
            'groupId' => $id,
            'payload' => $newGroup,
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $newGroup,
            ],
        ], 200);
    }

    public function themMoi(Request $request)
    {
        $body = $request->all();

        $activePeriod = Dot::orderBy('dot_id', 'desc')->first();
        $dotId = $body['dot_id'] ?? ($activePeriod ? $activePeriod->dot_id : 1);

        if ($resp = $this->chanNeuDotDaDong(Dot::find($dotId))) {
            return $resp;
        }

        $g = new Nhom;
        $g->dot_id = $dotId;
        $g->de_tai_id = $body['de_tai_id'] ?? null;

        $topicStatus = null;
        if ($g->de_tai_id) {
            $dt = DB::table('detai')->where('de_tai_id', $g->de_tai_id)->first();
            $topicStatus = $dt ? $dt->trang_thai : 'CHO_DUYET';
        }

        if ($g->de_tai_id) {
            if ($topicStatus === 'DA_DUYET') {
                $g->trang_thai_duyet = 'DA_DUYET';
            } elseif ($topicStatus === 'TU_CHOI') {
                $g->trang_thai_duyet = 'TU_CHOI';
            } else {
                $g->trang_thai_duyet = 'CHO_DUYET';
            }
        } else {
            $g->trang_thai_duyet = 'CHUA_DANG_KY';
        }

        $g->trang_thai_nhom = 'DU_THANH_VIEN';
        $g->ngay_dang_ky = now();
        $g->save();

        if ($g->de_tai_id) {
            DB::table('dangkydetai')->insert([
                'nhom_id' => $g->nhom_id,
                'de_tai_id' => $g->de_tai_id,
                'trang_thai_duyet' => $g->trang_thai_duyet,
                'ngay_dang_ky' => date('Y-m-d H:i:s'),
                'ly_do_tu_choi' => null,
            ]);
        }

        if (isset($body['members']) && is_array($body['members'])) {
            foreach ($body['members'] as $idx => $m) {
                $studentId = $m['id'] ?? $m;
                $student = SinhVien::where('sinh_vien_id', $studentId)
                    ->orWhere('ma_so_sinh_vien', $studentId)
                    ->first();

                if ($student) {
                    $otherGroupIdsInPeriod = DB::table('nhomsvda')
                        ->where('dot_id', $g->dot_id)
                        ->pluck('nhom_id');

                    DB::table('thanhviennhom')
                        ->whereIn('nhom_id', $otherGroupIdsInPeriod)
                        ->where('sinh_vien_id', $student->sinh_vien_id)
                        ->delete();

                    DB::table('thanhviennhom')->insert([
                        'nhom_id' => $g->nhom_id,
                        'sinh_vien_id' => $student->sinh_vien_id,
                        'la_truong_nhom' => $idx === 0 ? 1 : 0,
                        'dieu_kien_lam_do_an' => 'DAT',
                    ]);
                }
            }
        }

        $newGroup = Nhom::with(['deTai.giangVien', 'deTai.huongDeTais', 'members.lop', 'dot'])->find($g->nhom_id);

        RealtimeService::broadcast('slot_updated', [
            'type' => 'group_created',
            'groupId' => $g->nhom_id,
            'payload' => $this->transformGroup($newGroup),
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->transformGroup($newGroup),
            ],
        ], 200);
    }

    public function xoa(Request $request, $id)
    {
        $g = Nhom::with(['deTai.giangVien', 'deTai.huongDeTais', 'members.lop', 'dot'])->find($id);
        if (! $g) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm!',
            ], 404);
        }

        if ($resp = $this->chanNeuDotDaDong($g->dot)) {
            return $resp;
        }

        $groupData = $this->transformGroup($g);

        // Lưu vết trước khi xóa nhóm (Audit Log)
        Log::info(sprintf(
            "AUDIT LOG: [DELETE GROUP] Group ID: %s, Code: %s, Topic: '%s', Supervisor: '%s', Members: %s, IP: %s",
            $g->nhom_id,
            $groupData['code'],
            $groupData['title'],
            $groupData['supervisor'],
            json_encode(collect($groupData['members'])->map(fn ($m) => "{$m['code']}-{$m['name']}")->all(), JSON_UNESCAPED_UNICODE),
            $request->ip()
        ));

        DB::table('thanhviennhom')->where('nhom_id', $id)->delete();
        DB::table('dangkydetai')->where('nhom_id', $id)->delete();
        DB::table('lichbaove')->where('nhom_id', $id)->delete();
        DB::table('diembaocao')->where('nhom_id', $id)->delete();
        DB::table('diemtongketdatn')->where('nhom_id', $id)->delete();
        $g->delete();

        $admin = $request->user();
        LichSuHoatDong::ghiLog(
            'XOA_NHOM',
            'Admin '.($admin ? $admin->ho_ten : 'Hệ thống').' đã xóa nhóm.',
            null,
            null,
            $id,
            'admin',
            $admin ? $admin->ho_ten : 'Hệ thống',
            ['group_id' => $id]
        );

        RealtimeService::broadcast('slot_updated', [
            'type' => 'group_deleted',
            'groupId' => $id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Xóa nhóm thành công!',
        ], 200);
    }

    public function approveGroup(Request $request, $id)
    {
        $g = Nhom::find($id);
        if (! $g) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm!',
            ], 404);
        }

        if ($resp = $this->chanNeuDotDaDong($g->dot)) {
            return $resp;
        }

        $dangkydetai = DB::table('dangkydetai')->where('nhom_id', $id)->first();
        if ($dangkydetai) {
            $topic = DeTai::find($dangkydetai->de_tai_id);
            $maxSlots = $topic->so_luong_sv_toi_da ?? 4;
            $memberCount = DB::table('thanhviennhom')->where('nhom_id', $id)->count();
            $approvedSlots = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.de_tai_id', $dangkydetai->de_tai_id)
                ->where('nhomsvda.trang_thai_duyet', 'DA_DUYET')
                ->count();
            if ($approvedSlots + $memberCount > $maxSlots) {
                return response()->json([
                    'success' => false,
                    'message' => 'Đề tài chỉ còn '.max(0, $maxSlots - $approvedSlots).' chỗ trống, không đủ cho nhóm '.$memberCount.' thành viên này!',
                ], 400);
            }
            $g->de_tai_id = $dangkydetai->de_tai_id;
        }
        $g->trang_thai_duyet = 'DA_DUYET';
        $g->save();
        DB::table('dangkydetai')->where('nhom_id', $id)->update(['trang_thai_duyet' => 'DA_DUYET']);

        $admin = $request->user();
        LichSuHoatDong::ghiLog(
            'DUYET_DE_TAI',
            'Admin '.($admin ? $admin->ho_ten : 'Hệ thống').' đã phê duyệt đề tài đăng ký của nhóm.',
            null,
            null,
            $id,
            'admin',
            $admin ? $admin->ho_ten : 'Hệ thống',
            ['group_id' => $id]
        );

        $updated = Nhom::with(['deTai.giangVien', 'deTai.huongDeTais', 'members.lop', 'dot'])->find($id);

        RealtimeService::broadcast('slot_updated', [
            'type' => 'group_approved',
            'groupId' => $id,
            'payload' => $this->transformGroup($updated),
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->transformGroup($updated),
            ],
        ], 200);
    }

    public function rejectGroup(Request $request, $id)
    {
        $g = Nhom::find($id);
        if (! $g) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm!',
            ], 404);
        }

        if ($resp = $this->chanNeuDotDaDong($g->dot)) {
            return $resp;
        }

        $g->trang_thai_duyet = 'TU_CHOI';
        $g->save();
        DB::table('dangkydetai')->where('nhom_id', $id)->update(['trang_thai_duyet' => 'TU_CHOI']);

        $admin = $request->user();
        LichSuHoatDong::ghiLog(
            'TU_CHOI_DE_TAI',
            'Admin '.($admin ? $admin->ho_ten : 'Hệ thống').' đã từ chối đề tài đăng ký của nhóm.',
            null,
            null,
            $id,
            'admin',
            $admin ? $admin->ho_ten : 'Hệ thống',
            ['group_id' => $id]
        );

        $updated = Nhom::with(['deTai.giangVien', 'deTai.huongDeTais', 'members.lop', 'dot'])->find($id);

        RealtimeService::broadcast('slot_updated', [
            'type' => 'group_rejected',
            'groupId' => $id,
            'payload' => $this->transformGroup($updated),
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->transformGroup($updated),
            ],
        ], 200);
    }

    /**
     * $dotStudentsAll (tuỳ chọn): lookup dot_sinhvien đã gộp sẵn cho CẢ TRANG (key
     * "dot_id-sinh_vien_id"), truyền từ layDanhSach() để tránh N+1. Không truyền thì tự
     * query riêng cho nhóm này (dùng ở các chỗ gọi transformGroup() cho 1 nhóm đơn lẻ).
     */
    public function transformGroup($g, $dotStudentsAll = null)
    {
        $hasIneligible = false;
        $dotId = $g->dot_id;

        if ($dotStudentsAll !== null) {
            $dotStudents = null;
        } else {
            // Lấy danh sách ghi đè từ dot_sinhvien
            $dotStudents = \DB::table('dot_sinhvien')
                ->where('dot_id', $dotId)
                ->whereIn('sinh_vien_id', $g->members->pluck('sinh_vien_id'))
                ->get()
                ->keyBy('sinh_vien_id');
        }

        $members = $g->members
            ->map(function ($m) use (&$hasIneligible, $dotStudents, $dotStudentsAll, $dotId) {
                $eRecord = $dotStudentsAll !== null
                    ? $dotStudentsAll->get($dotId.'-'.$m->sinh_vien_id)
                    : $dotStudents->get($m->sinh_vien_id);
                $eligible = ($eRecord ? ($eRecord->dieu_kien_lam_do_an ?? 'DAT') : 'DAT') === 'DAT';
                if (! $eligible) {
                    $hasIneligible = true;
                }

                return [
                    'id' => (string) $m->sinh_vien_id,
                    'name' => $m->ho_ten,
                    'code' => $m->ma_so_sinh_vien,
                    'class' => $m->lop ? $m->lop->ten_lop : '',
                    'eligible' => $eligible,
                    'reason' => $eligible ? '' : 'Chưa đủ điều kiện làm đồ án',
                ];
            })
            ->values()
            ->all();

        $status = 'PENDING';
        if ($g->trang_thai_duyet === 'DA_DUYET') {
            $status = 'APPROVED';
        } elseif ($g->trang_thai_duyet === 'TU_CHOI') {
            $status = 'LOCKED';
        }

        if ($g->trang_thai_duyet !== 'DA_DUYET') {
            if ($hasIneligible) {
                $status = 'WARNING';
            } elseif (count($members) < 2) {
                $status = 'MISSING';
            }
        }

        return [
            'id' => (string) $g->nhom_id,
            'code' => null,
            'title' => $g->deTai ? $g->deTai->ten_de_tai : '—',
            'topicDirection' => $g->deTai ? $g->deTai->huongDeTais->pluck('ten_huong_de_tai')->implode(', ') : null,
            'supervisor' => ($g->deTai && $g->deTai->giangVien) ? $g->deTai->giangVien->ho_ten : '—',
            'members' => $members,
            'maxMembers' => $g->deTai ? ($g->deTai->so_luong_sv_toi_da ?? 2) : 2,
            'status' => $status,
            'registrationBatch' => $g->dot ? $g->dot->ten_dot : '',
            'ket_qua_huong_dan' => $g->ket_qua_huong_dan,
            'ket_qua_phan_bien' => $g->ket_qua_phan_bien,
            'hoi_dong_id' => $g->hoi_dong_id,
        ];
    }

    public function swapMembers(Request $request)
    {
        $request->validate([
            'studentIdA' => 'required',
            'studentIdB' => 'required',
        ]);

        $result = $this->performMemberSwap($request->input('studentIdA'), $request->input('studentIdB'), $request->user());
        $status = $result['success'] ? 200 : ($result['status'] ?? 400);
        unset($result['status']);

        return response()->json($result, $status);
    }

    /**
     * Hoán đổi chéo nhom_id của 2 sinh viên đang ở 2 nhóm khác nhau. Dùng chung cho cả
     * endpoint swap-members (gọi ngay lập tức) và capNhat() (khi FE gửi kèm swapAction để
     * hoán đổi chỉ chính thức lưu lúc bấm "Cập nhật").
     *
     * @return array{success: bool, message: string, status?: int, groupAId?: int, groupBId?: int}
     */
    private function performMemberSwap($studentIdA, $studentIdB, $admin = null)
    {
        $svA = SinhVien::where('ma_so_sinh_vien', $studentIdA)->orWhere('sinh_vien_id', $studentIdA)->first();
        $svB = SinhVien::where('ma_so_sinh_vien', $studentIdB)->orWhere('sinh_vien_id', $studentIdB)->first();

        if (! $svA || ! $svB) {
            return ['success' => false, 'message' => 'Không tìm thấy sinh viên!', 'status' => 400];
        }

        // Tìm nhóm của hai sinh viên trong đợt học hiện tại (chưa đóng hoặc đợt mới nhất)
        $tvA = DB::table('thanhviennhom')
            ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
            ->join('dot', 'nhomsvda.dot_id', '=', 'dot.dot_id')
            ->where('thanhviennhom.sinh_vien_id', $svA->sinh_vien_id)
            ->where('dot.trang_thai', '!=', 'DA_DONG')
            ->select('thanhviennhom.*', 'nhomsvda.dot_id')
            ->orderBy('dot.dot_id', 'desc')
            ->first();

        if (! $tvA) {
            $tvA = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('thanhviennhom.sinh_vien_id', $svA->sinh_vien_id)
                ->select('thanhviennhom.*', 'nhomsvda.dot_id')
                ->orderBy('nhomsvda.dot_id', 'desc')
                ->first();
        }

        $tvB = DB::table('thanhviennhom')
            ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
            ->join('dot', 'nhomsvda.dot_id', '=', 'dot.dot_id')
            ->where('thanhviennhom.sinh_vien_id', $svB->sinh_vien_id)
            ->where('dot.trang_thai', '!=', 'DA_DONG')
            ->select('thanhviennhom.*', 'nhomsvda.dot_id')
            ->orderBy('dot.dot_id', 'desc')
            ->first();

        if (! $tvB) {
            $tvB = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('thanhviennhom.sinh_vien_id', $svB->sinh_vien_id)
                ->select('thanhviennhom.*', 'nhomsvda.dot_id')
                ->orderBy('nhomsvda.dot_id', 'desc')
                ->first();
        }

        // Kiểm tra điều kiện làm đồ án của svB trong đợt hiện tại
        if ($tvA) {
            $nhomA = DB::table('nhomsvda')->where('nhom_id', $tvA->nhom_id)->first();
            $dotId = $nhomA ? $nhomA->dot_id : null;
            if ($dotId) {
                $eRecord = DB::table('dot_sinhvien')
                    ->where('dot_id', $dotId)
                    ->where('sinh_vien_id', $svB->sinh_vien_id)
                    ->first();
                $eligible = ($eRecord ? ($eRecord->dieu_kien_lam_do_an ?? 'DAT') : 'DAT') === 'DAT';
                if (! $eligible) {
                    return ['success' => false, 'message' => "Sinh viên {$svB->ho_ten} không đủ điều kiện làm đồ án!", 'status' => 400];
                }
            }
        }

        if (! $tvA || ! $tvB) {
            return ['success' => false, 'message' => 'Cả hai sinh viên phải đang ở trong một nhóm nào đó!', 'status' => 400];
        }

        $groupAId = $tvA->nhom_id;
        $groupBId = $tvB->nhom_id;

        $nhomA = DB::table('nhomsvda')->where('nhom_id', $groupAId)->first();
        $nhomB = DB::table('nhomsvda')->where('nhom_id', $groupBId)->first();

        if (($nhomA && $nhomA->trang_thai_duyet === 'TU_CHOI') || ($nhomB && $nhomB->trang_thai_duyet === 'TU_CHOI')) {
            return ['success' => false, 'message' => 'Không thể hoán đổi thành viên của nhóm đã bị từ chối hoặc khóa!', 'status' => 400];
        }

        if ($groupAId === $groupBId) {
            return ['success' => false, 'message' => 'Hai sinh viên phải thuộc hai nhóm khác nhau!', 'status' => 400];
        }

        DB::beginTransaction();
        try {
            // Xóa sạch các dòng trùng lặp ngoài ý muốn trong 2 nhóm đích để tránh lỗi Duplicate Key
            DB::table('thanhviennhom')->where('sinh_vien_id', $svA->sinh_vien_id)->where('nhom_id', $groupBId)->delete();
            DB::table('thanhviennhom')->where('sinh_vien_id', $svB->sinh_vien_id)->where('nhom_id', $groupAId)->delete();

            // Hoán đổi nhom_id của 2 thành viên
            DB::table('thanhviennhom')->where('sinh_vien_id', $svA->sinh_vien_id)->where('nhom_id', $groupAId)->update(['nhom_id' => $groupBId]);
            DB::table('thanhviennhom')->where('sinh_vien_id', $svB->sinh_vien_id)->where('nhom_id', $groupBId)->update(['nhom_id' => $groupAId]);

            LichSuHoatDong::ghiLog(
                'CAP_NHAT_NHOM',
                'Admin '.($admin ? $admin->ho_ten : 'Hệ thống')." đã hoán đổi vị trí nhóm của sinh viên {$svA->ho_ten} và sinh viên {$svB->ho_ten}.",
                null,
                null,
                $groupAId,
                'admin',
                $admin ? $admin->ho_ten : 'Hệ thống',
                ['studentIdA' => $svA->sinh_vien_id, 'studentIdB' => $svB->sinh_vien_id, 'groupAId' => $groupAId, 'groupBId' => $groupBId]
            );

            // Ghi log cho nhóm B nữa
            LichSuHoatDong::ghiLog(
                'CAP_NHAT_NHOM',
                'Admin '.($admin ? $admin->ho_ten : 'Hệ thống')." đã hoán đổi vị trí nhóm của sinh viên {$svA->ho_ten} và sinh viên {$svB->ho_ten}.",
                null,
                null,
                $groupBId,
                'admin',
                $admin ? $admin->ho_ten : 'Hệ thống',
                ['studentIdA' => $svA->sinh_vien_id, 'studentIdB' => $svB->sinh_vien_id, 'groupAId' => $groupAId, 'groupBId' => $groupBId]
            );

            DB::commit();

            RealtimeService::broadcast('slot_updated', ['type' => 'group_updated', 'groupId' => $groupAId]);
            RealtimeService::broadcast('slot_updated', ['type' => 'group_updated', 'groupId' => $groupBId]);

            return ['success' => true, 'message' => 'Hoán đổi thành viên thành công!', 'groupAId' => $groupAId, 'groupBId' => $groupBId];
        } catch (\Exception $e) {
            DB::rollBack();

            return ['success' => false, 'message' => 'Lỗi hệ thống: '.$e->getMessage(), 'status' => 500];
        }
    }
}
