<?php

namespace App\Http\Controllers\GiangVien;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\DeTaiService;
use App\Http\Requests\GiangVien\ThemDeTaiRequest;
use App\Http\Requests\GiangVien\CapNhatDeTaiRequest;
use Illuminate\Support\Facades\DB;
use App\Models\Dot;
use App\Models\Nhom;

class DeTaiController extends Controller
{
    protected $deTaiService;

    public function __construct(DeTaiService $deTaiService)
    {
        $this->deTaiService = $deTaiService;
    }

    /**
     * API Lấy danh sách đề tài của giảng viên này
     */
    public function layDanhSach(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $limit = $request->input('limit', 10);
        $filters = [
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
            'periodId' => $request->input('periodId'),
            'teacherId' => $teacherId
        ];

        $res = $this->deTaiService->getListTopic($filters, $limit);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total']
                ]
            ],
            'pagination' => [
                'total' => $res['total'],
                'totalPages' => $res['lastPage'],
                'limit' => $res['perPage'],
                'first' => $res['onFirstPage'],
                'last' => !$res['hasMorePages'],
                'hasNext' => $res['hasMorePages'],
                'hasPrevious' => !$res['onFirstPage']
            ]
        ], 200);
    }

    /**
     * API Xem chi tiết đề tài
     */
    public function xemChiTiet(Request $request, $id)
    {
        $teacher = $request->user();
        $topic = $this->deTaiService->getTopicDetail($id);
        
        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này!'
            ], 404);
        }

        // Verify ownership
        $dbTopic = \App\Models\DeTai::find($id);
        if ($dbTopic && $dbTopic->giang_vien_id !== $teacher->giang_vien_id) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem chi tiết đề tài của giảng viên khác!'
            ], 403);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $topic
            ]
        ], 200);
    }

    /**
     * API Tạo mới đề tài
     */
    public function themMoi(ThemDeTaiRequest $request)
    {
        $teacher = $request->user();
        $periodId = $request->query('periodId');

        $payload = $request->all();
        // Force the teacher parameter to the logged in teacher's name so lookup matches
        $payload['teacher'] = $teacher->ho_ten;

        $topic = $this->deTaiService->createTopic($payload, $periodId);

        // Ensure correct teacher ID is set
        if ($topic) {
            $dbTopic = \App\Models\DeTai::find($topic['id']);
            if ($dbTopic && $dbTopic->giang_vien_id !== $teacher->giang_vien_id) {
                $dbTopic->giang_vien_id = $teacher->giang_vien_id;
                $dbTopic->save();
                $topic = $this->deTaiService->getTopicDetail($dbTopic->de_tai_id);
            }
        }

        \App\Services\RealtimeService::broadcast('notification', [
            'title' => 'Đề tài mới được đề xuất',
            'message' => 'Giảng viên ' . ($teacher->ho_ten) . ' vừa đề xuất đề tài: ' . ($topic['name'] ?? ''),
            'type' => 'topic_proposed',
            'payload' => $topic
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $topic
            ]
        ], 200);
    }

    /**
     * API Cập nhật đề tài
     */
    public function capNhat(CapNhatDeTaiRequest $request, $id)
    {
        $teacher = $request->user();
        
        $dbTopic = \App\Models\DeTai::find($id);
        if (!$dbTopic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này để cập nhật!'
            ], 404);
        }

        // Verify ownership
        if ($dbTopic->giang_vien_id !== $teacher->giang_vien_id) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền chỉnh sửa đề tài của giảng viên khác!'
            ], 403);
        }

        $payload = $request->all();
        $payload['teacher'] = $teacher->ho_ten;

        $topic = $this->deTaiService->updateTopic($id, $payload);

        \App\Services\RealtimeService::broadcast('slot_updated', [
            'type' => 'topic_updated',
            'topicId' => $id,
            'payload' => $topic
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $topic
            ]
        ], 200);
    }

    /**
     * API Xóa đề tài
     */
    public function xoa(Request $request, $id)
    {
        $teacher = $request->user();
        
        $dbTopic = \App\Models\DeTai::find($id);
        if (!$dbTopic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này để xóa!'
            ], 404);
        }

        // Verify ownership
        if ($dbTopic->giang_vien_id !== $teacher->giang_vien_id) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xóa đề tài của giảng viên khác!'
            ], 403);
        }

        $success = $this->deTaiService->deleteTopic($id);
        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này để xóa!'
            ], 404);
        }

        \App\Services\RealtimeService::broadcast('slot_updated', [
            'type' => 'topic_deleted',
            'topicId' => $id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Xóa đề tài thành công!'
        ], 200);
    }

    /**
     * GET /private/v1/teacher/groups
     */
    public function getGroups(Request $request)
    {
        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $dotId = $request->input('periodId');
        if (empty($dotId)) {
            $latestPeriod = Dot::orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        $groups = DB::table('dangkydetai')
            ->join('nhomsvda', 'dangkydetai.nhom_id', '=', 'nhomsvda.nhom_id')
            ->join('detai', 'dangkydetai.de_tai_id', '=', 'detai.de_tai_id')
            ->where('detai.giang_vien_id', $teacherId)
            ->where('nhomsvda.dot_id', $dotId)
            ->select([
                'nhomsvda.nhom_id as id',
                'detai.de_tai_id as topic_id',
                'detai.ten_de_tai as topicName',
                'dangkydetai.trang_thai_duyet as status',
                'dangkydetai.ngay_dang_ky as submittedAt',
                'dangkydetai.ly_do_tu_choi as note'
            ])
            ->orderBy('dangkydetai.ngay_dang_ky', 'asc')
            ->get();
 
        $rows = $groups->map(function ($g) {
            $members = DB::table('thanhviennhom')
                ->join('sinhvien', 'thanhviennhom.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
                ->where('thanhviennhom.nhom_id', $g->id)
                ->select([
                    'sinhvien.ho_ten as name',
                    'sinhvien.ma_so_sinh_vien as code',
                    'lop.ten_lop as className',
                    'thanhviennhom.la_truong_nhom'
                ])
                ->get();
 
            $leader = $members->firstWhere('la_truong_nhom', 1);
            $statusText = 'pending';
            if ($g->status === 'DA_DUYET') {
                $statusText = 'accepted';
            } elseif ($g->status === 'TU_CHOI') {
                $statusText = 'rejected';
            }
 
            return [
                'id' => (string)$g->id,
                'topicCode' => 'NH' . str_pad($g->id, 2, '0', STR_PAD_LEFT),
                'topicName' => $g->topicName,
                'groupName' => 'Nhóm #' . $g->id,
                'leader' => $leader ? $leader->name : '—',
                'members' => $members->count(),
                'membersList' => $members,
                'submittedAt' => $g->submittedAt ? date('d/m/Y H:i', strtotime($g->submittedAt)) : date('d/m/Y H:i'),
                'status' => $statusText,
                'note' => $g->note ?? ''
            ];
        });
 
        return response()->json($rows);
    }
 
    /**
     * PATCH /private/v1/teacher/groups/{groupId}
     */
    public function updateGroupStatus(Request $request, $groupId)
    {
        $action = $request->input('action');
 
        $dangkydetai = DB::table('dangkydetai')->where('nhom_id', $groupId)->first();
        if (!$dangkydetai) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đăng ký đề tài của nhóm!'], 404);
        }
 
        if ($action === 'accept') {
            DB::table('dangkydetai')->where('nhom_id', $groupId)->update(['trang_thai_duyet' => 'DA_DUYET']);
            DB::table('nhomsvda')->where('nhom_id', $groupId)->update([
                'de_tai_id' => $dangkydetai->de_tai_id,
                'trang_thai_duyet' => 'DA_DUYET'
            ]);
        } else {
            DB::table('dangkydetai')->where('nhom_id', $groupId)->update(['trang_thai_duyet' => 'TU_CHOI']);
            DB::table('nhomsvda')->where('nhom_id', $groupId)->update([
                'de_tai_id' => null,
                'trang_thai_duyet' => 'TU_CHOI'
            ]);
        }
 
        // Return updated group structure
        $g = DB::table('dangkydetai')
            ->join('detai', 'dangkydetai.de_tai_id', '=', 'detai.de_tai_id')
            ->where('dangkydetai.nhom_id', $groupId)
            ->select([
                'dangkydetai.nhom_id as id',
                'detai.ten_de_tai as topicName'
            ])
            ->first();
 
        $members = DB::table('thanhviennhom')
            ->join('sinhvien', 'thanhviennhom.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
            ->leftJoin('lop', 'sinhvien.lop_id', '=', 'lop.lop_id')
            ->where('thanhviennhom.nhom_id', $groupId)
            ->select([
                'sinhvien.ho_ten as name',
                'sinhvien.ma_so_sinh_vien as code',
                'lop.ten_lop as className',
                'thanhviennhom.la_truong_nhom'
            ])
            ->get();
 
        $leader = $members->firstWhere('la_truong_nhom', 1);
 
        $groupObj = [
            'id' => (string)$groupId,
            'topicCode' => 'NH' . str_pad($groupId, 2, '0', STR_PAD_LEFT),
            'topicName' => $g->topicName ?? '—',
            'groupName' => 'Nhóm #' . $groupId,
            'leader' => $leader ? $leader->name : '—',
            'members' => $members->count(),
            'membersList' => $members,
            'submittedAt' => date('d/m/Y H:i'),
            'status' => $action === 'accept' ? 'accepted' : 'rejected',
            'note' => ''
        ];
 
        // Broadcast the real-time event to sync admin and other clients
        \App\Services\RealtimeService::broadcast('slot_updated', [
            'type' => 'group_status_updated',
            'groupId' => $groupId,
            'status' => $action === 'accept' ? 'accepted' : 'rejected',
            'payload' => $groupObj
        ]);

        \App\Services\RealtimeService::broadcast('notification', [
            'title' => $action === 'accept' ? 'Đăng ký đề tài được duyệt' : 'Đăng ký đề tài bị từ chối',
            'message' => 'Giảng viên đã ' . ($action === 'accept' ? 'duyệt' : 'từ chối') . ' đăng ký đề tài cho ' . ($groupObj['groupName'] ?? "nhóm #{$groupId}"),
            'type' => 'group_status_updated',
            'payload' => $groupObj
        ]);

        return response()->json([
            'success' => true,
            'group' => $groupObj
        ]);
    }

    /**
     * POST /private/v1/teacher/topics/import
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls'
        ]);

        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $dotId = $request->query('periodId');
        if (empty($dotId)) {
            $latestPeriod = Dot::where('loai_dot', 'DATN')->orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        $file = $request->file('file');
        
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            $importedCount = 0;
            
            foreach ($rows as $index => $row) {
                if ($index === 0) continue; // Skip header
                
                $tenDeTai = trim($row[0] ?? '');
                $moTa = trim($row[1] ?? '');
                $slotsVal = trim($row[2] ?? '');
                $huongDeTaiVal = trim($row[3] ?? '');
                
                if (empty($tenDeTai)) {
                    continue;
                }
                
                $maxSlots = 4;
                if (!empty($slotsVal) && is_numeric($slotsVal)) {
                    $maxSlots = (int)$slotsVal;
                }
                
                $huongDeTai = 'PHAN_MEM';
                $huongUpper = mb_strtoupper($huongDeTaiVal);
                if (str_contains($huongUpper, 'MẠNG') || str_contains($huongUpper, 'MANG') || str_contains($huongUpper, 'NETWORK')) {
                    $huongDeTai = 'MANG_MAY_TINH';
                }
                
                \App\Models\DeTai::create([
                    'dot_id' => $dotId,
                    'giang_vien_id' => $teacherId,
                    'ten_de_tai' => $tenDeTai,
                    'mo_ta' => $moTa,
                    'so_luong_sv_toi_da' => $maxSlots,
                    'huong_de_tai' => $huongDeTai,
                    'trang_thai' => 'CHO_DUYET'
                ]);
                
                $importedCount++;
            }
            
            return response()->json([
                'success' => true,
                'message' => "Import thành công $importedCount đề tài.",
                'imported_count' => $importedCount
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi đọc file Excel: ' . $e->getMessage()
            ], 500);
        }
    }
}
