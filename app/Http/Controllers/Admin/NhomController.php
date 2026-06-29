<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Nhom;
use App\Models\SinhVien;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NhomController extends Controller
{
    public function layDanhSach(Request $request)
    {
        $groups = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->get();

        $rows = $groups->map(function ($g) {
            return $this->transformGroup($g);
        })->all();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $rows,
                    'total' => count($rows)
                ]
            ]
        ], 200);
    }

    public function xemChiTiet(Request $request, $id)
    {
        $g = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);
        if (!$g) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm này!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->transformGroup($g)
            ]
        ], 200);
    }

    public function capNhat(Request $request, $id)
    {
        $g = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);
        if (!$g) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm!'
            ], 404);
        }

        $oldGroup = $this->transformGroup($g);
        $body = $request->all();

        // 1. Cập nhật trạng thái duyệt nếu có truyền lên
        if (isset($body['status'])) {
            $status = $body['status'];
            if ($status === 'APPROVED') {
                $g->trang_thai_duyet = 'DA_DUYET';
            } elseif ($status === 'LOCKED' || $status === 'DISSOLVED') {
                $g->trang_thai_duyet = 'TU_CHOI';
            } elseif ($status === 'PENDING') {
                $g->trang_thai_duyet = 'CHO_DUYET';
            }
            $g->save();
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
                        'dieu_kien_lam_do_an' => 'DAT'
                    ]);
                }
            }
        }

        $updated = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);
        $newGroup = $this->transformGroup($updated);

        // Lưu vết quá trình cập nhật nhóm (Audit Log)
        Log::info(sprintf(
            "AUDIT LOG: [UPDATE GROUP] Group ID: %s, Old Status: %s, New Status: %s, Old Members: %s, New Members: %s, IP: %s",
            $id,
            $oldGroup['status'],
            $newGroup['status'],
            json_encode(collect($oldGroup['members'])->map(fn($m) => "{$m['code']}-{$m['name']}")->all(), JSON_UNESCAPED_UNICODE),
            json_encode(collect($newGroup['members'])->map(fn($m) => "{$m['code']}-{$m['name']}")->all(), JSON_UNESCAPED_UNICODE),
            $request->ip()
        ));

        \App\Services\RealtimeService::broadcast('slot_updated', [
            'type' => 'group_updated',
            'groupId' => $id,
            'payload' => $newGroup
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $newGroup
            ]
        ], 200);
    }

    public function xoa(Request $request, $id)
    {
        $g = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);
        if (!$g) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm!'
            ], 404);
        }

        $groupData = $this->transformGroup($g);

        // Lưu vết trước khi xóa nhóm (Audit Log)
        Log::info(sprintf(
            "AUDIT LOG: [DELETE GROUP] Group ID: %s, Code: %s, Topic: '%s', Supervisor: '%s', Members: %s, IP: %s",
            $g->nhom_id,
            $groupData['code'],
            $groupData['title'],
            $groupData['supervisor'],
            json_encode(collect($groupData['members'])->map(fn($m) => "{$m['code']}-{$m['name']}")->all(), JSON_UNESCAPED_UNICODE),
            $request->ip()
        ));

        DB::table('thanhviennhom')->where('nhom_id', $id)->delete();
        $g->delete();

        \App\Services\RealtimeService::broadcast('slot_updated', [
            'type' => 'group_deleted',
            'groupId' => $id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Xóa nhóm thành công!'
        ], 200);
    }

    public function approveGroup(Request $request, $id)
    {
        $g = Nhom::find($id);
        if (!$g) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm!'
            ], 404);
        }

        $g->trang_thai_duyet = 'DA_DUYET';
        $g->save();

        $updated = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);

        \App\Services\RealtimeService::broadcast('slot_updated', [
            'type' => 'group_approved',
            'groupId' => $id,
            'payload' => $this->transformGroup($updated)
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->transformGroup($updated)
            ]
        ], 200);
    }

    public function rejectGroup(Request $request, $id)
    {
        $g = Nhom::find($id);
        if (!$g) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nhóm!'
            ], 404);
        }

        $g->trang_thai_duyet = 'TU_CHOI';
        $g->save();

        $updated = Nhom::with(['deTai.giangVien', 'members.lop', 'dot'])->find($id);

        \App\Services\RealtimeService::broadcast('slot_updated', [
            'type' => 'group_rejected',
            'groupId' => $id,
            'payload' => $this->transformGroup($updated)
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->transformGroup($updated)
            ]
        ], 200);
    }

    private function transformGroup($g)
    {
        $hasIneligible = false;
        $members = $g->members->map(function ($m) use (&$hasIneligible) {
            $eligible = ($m->pivot->dieu_kien_lam_do_an ?? 'DAT') === 'DAT';
            if (!$eligible) {
                $hasIneligible = true;
            }
            return [
                'id' => (string) $m->sinh_vien_id,
                'name' => $m->ho_ten,
                'code' => $m->ma_so_sinh_vien,
                'class' => $m->lop ? $m->lop->ten_lop : '',
                'eligible' => $eligible,
                'reason' => $eligible ? '' : 'Chưa đủ điều kiện làm đồ án'
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
            'code' => 'NH' . str_pad($g->nhom_id, 2, '0', STR_PAD_LEFT),
            'title' => $g->deTai ? $g->deTai->ten_de_tai : '—',
            'topicDirection' => $g->deTai ? $g->deTai->huong_de_tai : null,
            'supervisor' => ($g->deTai && $g->deTai->giangVien) ? $g->deTai->giangVien->ho_ten : '—',
            'members' => $members,
            'maxMembers' => $g->deTai ? ($g->deTai->so_luong_sv_toi_da ?? 2) : 2,
            'status' => $status,
            'registrationBatch' => $g->dot ? $g->dot->ten_dot : ''
        ];
    }
}
