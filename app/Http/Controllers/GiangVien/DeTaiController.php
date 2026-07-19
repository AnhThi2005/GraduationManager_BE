<?php

namespace App\Http\Controllers\GiangVien;

use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use App\Http\Requests\GiangVien\CapNhatDeTaiRequest;
use App\Http\Requests\GiangVien\ThemDeTaiRequest;
use App\Models\DeTai;
use App\Models\Dot;
use App\Models\LichSuHoatDong;
use App\Services\DeTaiService;
use App\Services\RealtimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DeTaiController extends Controller
{
    use KiemTraTrangThaiDot;

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
            'teacherId' => $teacherId,
        ];

        $res = $this->deTaiService->getListTopic($filters, $limit);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total'],
                ],
            ],
            'pagination' => [
                'total' => $res['total'],
                'totalPages' => $res['lastPage'],
                'limit' => $res['perPage'],
                'first' => $res['onFirstPage'],
                'last' => ! $res['hasMorePages'],
                'hasNext' => $res['hasMorePages'],
                'hasPrevious' => ! $res['onFirstPage'],
            ],
        ], 200);
    }

    /**
     * API Xem chi tiết đề tài
     */
    public function xemChiTiet(Request $request, $id)
    {
        $teacher = $request->user();
        $topic = $this->deTaiService->getTopicDetail($id);

        if (! $topic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này!',
            ], 404);
        }

        // Verify ownership
        $dbTopic = DeTai::find($id);
        if ($dbTopic && $dbTopic->giang_vien_id !== $teacher->giang_vien_id) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem chi tiết đề tài của giảng viên khác!',
            ], 403);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $topic,
            ],
        ], 200);
    }

    /**
     * API Tạo mới đề tài
     */
    public function themMoi(ThemDeTaiRequest $request)
    {
        $teacher = $request->user();
        $periodId = $request->query('periodId');

        $dot = $periodId
            ? Dot::find($periodId)
            : Dot::where('loai_dot', 'DATN')->orderBy('dot_id', 'desc')->first();
        if ($resp = $this->chanNeuDotDaDong($dot)) {
            return $resp;
        }

        $payload = $request->all();
        // Force the teacher parameter to the logged in teacher's name so lookup matches
        $payload['teacher'] = $teacher->ho_ten;

        $topic = $this->deTaiService->createTopic($payload, $periodId);

        // Ensure correct teacher ID is set
        if ($topic) {
            $dbTopic = DeTai::find($topic['id']);
            if ($dbTopic && $dbTopic->giang_vien_id !== $teacher->giang_vien_id) {
                $dbTopic->giang_vien_id = $teacher->giang_vien_id;
                $dbTopic->save();
                $topic = $this->deTaiService->getTopicDetail($dbTopic->de_tai_id);
            }
        }

        LichSuHoatDong::ghiLog(
            'DE_XUAT_DE_TAI',
            "Giảng viên {$teacher->ho_ten} đã đề xuất đề tài: ".($topic['name'] ?? '').'.',
            null,
            null,
            null,
            'giang_vien',
            $teacher->ho_ten,
            ['topic_id' => $topic['id'] ?? null]
        );

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $topic,
            ],
        ], 200);
    }

    /**
     * API Cập nhật đề tài
     */
    public function capNhat(CapNhatDeTaiRequest $request, $id)
    {
        $teacher = $request->user();

        $dbTopic = DeTai::find($id);
        if (! $dbTopic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này để cập nhật!',
            ], 404);
        }

        // Verify ownership
        if ($dbTopic->giang_vien_id !== $teacher->giang_vien_id) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền chỉnh sửa đề tài của giảng viên khác!',
            ], 403);
        }

        if ($resp = $this->chanNeuDotDaDong(Dot::find($dbTopic->dot_id))) {
            return $resp;
        }

        $payload = $request->all();
        $payload['teacher'] = $teacher->ho_ten;

        $topic = $this->deTaiService->updateTopic($id, $payload);

        RealtimeService::broadcast('slot_updated', [
            'type' => 'topic_updated',
            'topicId' => $id,
            'payload' => $topic,
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $topic,
            ],
        ], 200);
    }

    /**
     * API Xóa đề tài
     */
    public function xoa(Request $request, $id)
    {
        $teacher = $request->user();

        $dbTopic = DeTai::find($id);
        if (! $dbTopic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này để xóa!',
            ], 404);
        }

        // Verify ownership
        if ($dbTopic->giang_vien_id !== $teacher->giang_vien_id) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xóa đề tài của giảng viên khác!',
            ], 403);
        }

        if ($resp = $this->chanNeuDotDaDong(Dot::find($dbTopic->dot_id))) {
            return $resp;
        }

        try {
            $success = $this->deTaiService->deleteTopic($id);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này để xóa!',
            ], 404);
        }

        LichSuHoatDong::ghiLog(
            'XOA_DE_TAI',
            "Giảng viên {$teacher->ho_ten} đã xoá đề tài {$dbTopic->ten_de_tai}",
            null,
            null,
            null,
            'giang_vien',
            $teacher->ho_ten,
            [
                'topic_id' => $id,
                'topic_title' => $dbTopic->ten_de_tai,
                'dot_id' => $dbTopic->dot_id
            ]
        );

        RealtimeService::broadcast('slot_updated', [
            'type' => 'topic_deleted',
            'topicId' => $id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Xóa đề tài thành công!',
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
                'dangkydetai.ly_do_tu_choi as note',
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
                    'thanhviennhom.la_truong_nhom',
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
                'id' => (string) $g->id,
                'topicCode' => null,
                'topicName' => $g->topicName,
                'groupName' => 'Nhóm',
                'leader' => $leader ? $leader->name : '—',
                'members' => $members->count(),
                'membersList' => $members,
                'submittedAt' => $g->submittedAt ? date('d/m/Y H:i', strtotime($g->submittedAt)) : date('d/m/Y H:i'),
                'status' => $statusText,
                'note' => $g->note ?? '',
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
        if (! $dangkydetai) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đăng ký đề tài của nhóm!'], 404);
        }

        $nhomDotId = DB::table('nhomsvda')->where('nhom_id', $groupId)->value('dot_id');
        if ($resp = $this->chanNeuDotDaDong(Dot::find($nhomDotId))) {
            return $resp;
        }

        if ($action === 'accept') {
            $memberCount = DB::table('thanhviennhom')->where('nhom_id', $groupId)->count();

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

            DB::table('dangkydetai')->where('nhom_id', $groupId)->update(['trang_thai_duyet' => 'DA_DUYET']);
            DB::table('nhomsvda')->where('nhom_id', $groupId)->update([
                'de_tai_id' => $dangkydetai->de_tai_id,
                'trang_thai_duyet' => 'DA_DUYET',
            ]);

            $teacher = $request->user();
            // Nhúng thẳng tên đề tài vào mô tả - không dựa vào cơ chế thay "nhóm" bằng tên nhóm
            // thật (HistoryController::getGroupDisplayName) vì nhomsvda.de_tai_id đã được set ở
            // trên nên cơ chế đó cũng sẽ chèn tên đề tài, nhưng chỉ có với sinh viên thì "nhóm"
            // được thay thành "nhóm bạn" (không có tên đề tài) - viết trực tiếp để chắc chắn
            // sinh viên vẫn thấy đúng tên đề tài vừa được duyệt.
            LichSuHoatDong::ghiLog(
                'DUYET_DE_TAI',
                "Giảng viên {$teacher->ho_ten} đã phê duyệt đề tài \"{$topic->ten_de_tai}\" của nhóm.",
                null,
                null,
                $groupId,
                'giang_vien',
                $teacher->ho_ten,
                ['topic_id' => $dangkydetai->de_tai_id]
            );
        } else {
            $topic = DeTai::find($dangkydetai->de_tai_id);

            DB::table('dangkydetai')->where('nhom_id', $groupId)->update(['trang_thai_duyet' => 'TU_CHOI']);
            DB::table('nhomsvda')->where('nhom_id', $groupId)->update([
                'de_tai_id' => null,
                'trang_thai_duyet' => 'TU_CHOI',
            ]);

            $teacher = $request->user();
            $lyDo = $request->input('note', '');
            // Nhúng thẳng tên đề tài (giống nhánh accept) - đặc biệt cần thiết ở đây vì
            // nhomsvda.de_tai_id vừa bị xóa null nên getGroupDisplayName() không còn cách nào
            // lấy lại tên đề tài đã bị từ chối để hiển thị.
            LichSuHoatDong::ghiLog(
                'TU_CHOI_DE_TAI',
                "Giảng viên {$teacher->ho_ten} đã từ chối đề tài \"{$topic->ten_de_tai}\" của nhóm.".($lyDo ? " Lý do: {$lyDo}" : ''),
                null,
                null,
                $groupId,
                'giang_vien',
                $teacher->ho_ten,
                ['topic_id' => $dangkydetai->de_tai_id, 'reason' => $lyDo]
            );
        }

        // Return updated group structure
        $g = DB::table('dangkydetai')
            ->join('detai', 'dangkydetai.de_tai_id', '=', 'detai.de_tai_id')
            ->where('dangkydetai.nhom_id', $groupId)
            ->select([
                'dangkydetai.nhom_id as id',
                'detai.ten_de_tai as topicName',
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
                'thanhviennhom.la_truong_nhom',
            ])
            ->get();

        $leader = $members->firstWhere('la_truong_nhom', 1);

        $groupObj = [
            'id' => (string) $groupId,
            'topicCode' => null,
            'topicName' => $g->topicName ?? '—',
            'groupName' => 'Nhóm',
            'leader' => $leader ? $leader->name : '—',
            'members' => $members->count(),
            'membersList' => $members,
            'submittedAt' => date('d/m/Y H:i'),
            'status' => $action === 'accept' ? 'accepted' : 'rejected',
            'note' => '',
        ];

        // Broadcast the real-time event to sync admin and other clients
        RealtimeService::broadcast('slot_updated', [
            'type' => 'group_status_updated',
            'groupId' => $groupId,
            'status' => $action === 'accept' ? 'accepted' : 'rejected',
            'payload' => $groupObj,
        ]);

        RealtimeService::broadcast('notification', [
            'title' => $action === 'accept' ? 'Đăng ký đề tài được duyệt' : 'Đăng ký đề tài bị từ chối',
            'message' => 'Giảng viên đã '.($action === 'accept' ? 'duyệt' : 'từ chối').' đăng ký đề tài cho nhóm.',
            'type' => 'group_status_updated',
            'payload' => $groupObj,
        ]);

        return response()->json([
            'success' => true,
            'group' => $groupObj,
        ]);
    }

    /**
     * POST /private/v1/teacher/topics/import
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $teacher = $request->user();
        $teacherId = $teacher->giang_vien_id;

        $dotId = $request->query('periodId');
        if (empty($dotId)) {
            $latestPeriod = Dot::where('loai_dot', 'DATN')->orderBy('dot_id', 'desc')->first();
            $dotId = $latestPeriod ? $latestPeriod->dot_id : 1;
        }

        if ($resp = $this->chanNeuDotDaDong(Dot::find($dotId))) {
            return $resp;
        }

        $file = $request->file('file');

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            $errors = [];
            $seenNames = [];

            // Phase 1: Validate all rows for duplicate topic names
            foreach ($rows as $index => $row) {
                if ($index === 0) {
                    continue;
                } // Skip header

                $tenDeTai = trim($row[0] ?? '');
                if (empty($tenDeTai)) {
                    continue;
                }

                $lowerName = mb_strtolower($tenDeTai);

                // Check internal duplicate in Excel file
                if (in_array($lowerName, $seenNames)) {
                    $errors[] = 'Dòng '.($index + 1).": Tên đề tài \"{$tenDeTai}\" bị trùng lặp trong file Excel.";

                    continue;
                }
                $seenNames[] = $lowerName;

                // Check duplicate in database
                if (DeTai::where('ten_de_tai', $tenDeTai)->exists()) {
                    $errors[] = 'Dòng '.($index + 1).": Tên đề tài \"{$tenDeTai}\" đã tồn tại trên hệ thống.";
                }
            }

            if (! empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lỗi dữ liệu import trùng lặp:',
                    'errors' => $errors,
                ], 422);
            }

            // Phase 2: Create topics since validation passed
            $importedCount = 0;

            foreach ($rows as $index => $row) {
                if ($index === 0) {
                    continue;
                } // Skip header

                $tenDeTai = trim($row[0] ?? '');
                $moTa = trim($row[1] ?? '');
                $slotsVal = trim($row[2] ?? '');
                $huongDeTaiVal = trim($row[3] ?? '');

                if (empty($tenDeTai)) {
                    continue;
                }

                $maxSlots = 4;
                if (! empty($slotsVal) && is_numeric($slotsVal)) {
                    $maxSlots = (int) $slotsVal;
                }

                $deTai = DeTai::create([
                    'dot_id' => $dotId,
                    'giang_vien_id' => $teacherId,
                    'ten_de_tai' => $tenDeTai,
                    'mo_ta' => $moTa,
                    'so_luong_sv_toi_da' => $maxSlots,
                    'trang_thai' => 'CHO_DUYET',
                ]);

                $huongInput = ! empty($huongDeTaiVal) ? $huongDeTaiVal : 'Phát triển phần mềm';
                $this->deTaiService->syncDirections($deTai, $huongInput);

                $importedCount++;
            }

            return response()->json([
                'success' => true,
                'message' => "Import thành công $importedCount đề tài.",
                'imported_count' => $importedCount,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi đọc file Excel: '.$e->getMessage(),
            ], 500);
        }
    }
}
