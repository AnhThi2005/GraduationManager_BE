<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use App\Models\DeTai;
use App\Models\Dot;
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
        $groups = Nhom::orderBy('nhom_id', 'desc')->with(['deTai.giangVien', 'members.lop', 'dot'])
            ->whereNotNull('de_tai_id')
            ->get();

        $rows = $groups->map(function ($g) {
            return $this->transformGroup($g);
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
        $g = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);
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
        $g = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);
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
                $memberCount = DB::table('thanhviennhom')->where('nhom_id', $id)->count();
                if ($memberCount < 2) {
                    return response()->json(['success' => false, 'message' => 'Nhóm phải có đủ ít nhất 2 thành viên mới được duyệt!'], 400);
                }
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

        // 2. Cập nhật danh sách thành viên nếu có truyền lên
        if (isset($body['members']) && is_array($body['members'])) {
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
                    DB::table('thanhviennhom')->insert([
                        'nhom_id' => $id,
                        'sinh_vien_id' => $student->sinh_vien_id,
                        'la_truong_nhom' => $idx === 0 ? 1 : 0,
                        'dieu_kien_lam_do_an' => 'DAT',
                    ]);
                }
            }
        }

        $updated = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);
        $newGroup = $this->transformGroup($updated);

        // Lưu vết quá trình cập nhật nhóm (Audit Log)
        Log::info(sprintf(
            'AUDIT LOG: [UPDATE GROUP] Group ID: %s, Old Status: %s, New Status: %s, Old Members: %s, New Members: %s, IP: %s',
            $id,
            $oldGroup['status'],
            $newGroup['status'],
            json_encode(collect($oldGroup['members'])->map(fn ($m) => "{$m['code']}-{$m['name']}")->all(), JSON_UNESCAPED_UNICODE),
            json_encode(collect($newGroup['members'])->map(fn ($m) => "{$m['code']}-{$m['name']}")->all(), JSON_UNESCAPED_UNICODE),
            $request->ip()
        ));

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
                    DB::table('thanhviennhom')->insert([
                        'nhom_id' => $g->nhom_id,
                        'sinh_vien_id' => $student->sinh_vien_id,
                        'la_truong_nhom' => $idx === 0 ? 1 : 0,
                        'dieu_kien_lam_do_an' => 'DAT',
                    ]);
                }
            }
        }

        $newGroup = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($g->nhom_id);

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
        $g = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);
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

        $memberCount = DB::table('thanhviennhom')->where('nhom_id', $id)->count();
        if ($memberCount < 2) {
            return response()->json(['success' => false, 'message' => 'Nhóm phải có đủ ít nhất 2 thành viên mới được duyệt!'], 400);
        }

        $dangkydetai = DB::table('dangkydetai')->where('nhom_id', $id)->first();
        if ($dangkydetai) {
            $topic = DeTai::find($dangkydetai->de_tai_id);
            $maxSlots = $topic->so_luong_sv_toi_da ?? 4;
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

        $updated = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);

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

        $updated = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);

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

    public function transformGroup($g)
    {
        $hasIneligible = false;
        $members = $g->members->map(function ($m) use (&$hasIneligible) {
            $eligible = ($m->pivot->dieu_kien_lam_do_an ?? 'DAT') === 'DAT';
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
        })->all();

        $status = 'PENDING';
        if ($g->trang_thai_duyet === 'DA_DUYET') {
            $status = 'APPROVED';
        } elseif ($g->trang_thai_duyet === 'TU_CHOI') {
            $status = 'LOCKED';
        }

        if ($hasIneligible) {
            $status = 'WARNING';
        } elseif (count($members) < 2) {
            $status = 'MISSING';
        }

        return [
            'id' => (string) $g->nhom_id,
            'code' => 'NH'.str_pad($g->nhom_id, 2, '0', STR_PAD_LEFT),
            'title' => $g->deTai ? $g->deTai->ten_de_tai : '—',
            'topicDirection' => $g->deTai ? $g->deTai->huong_de_tai : null,
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
}
