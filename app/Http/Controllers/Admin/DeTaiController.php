<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuanLyDeTai\CapNhatDeTaiRequest;
use App\Http\Requests\Admin\QuanLyDeTai\ThemDeTaiRequest;
use App\Models\DeTai;
use App\Models\Dot;
use App\Models\HuongDeTai;
use App\Services\DeTaiService;
use App\Services\RealtimeService;
use Illuminate\Http\Request;

class DeTaiController extends Controller
{
    use KiemTraTrangThaiDot;

    protected $deTaiService;

    public function __construct(DeTaiService $deTaiService)
    {
        $this->deTaiService = $deTaiService;
    }

    /**
     * API Lấy danh sách đề tài (Có phân trang & lọc)
     */
    public function layDanhSach(Request $request)
    {
        $limit = $request->input('limit', 10);
        $user = $request->user();
        $status = $request->input('status');
        if ($user && $user->tokenCan('SINH_VIEN')) {
            $status = 'approved';
        }

        $filters = [
            'keyword' => $request->input('keyword'),
            'status' => $status,
            'periodId' => $request->input('periodId'),
            'teacher' => $request->input('teacher'),
            'direction' => $request->input('direction'),
            'availableOnly' => $request->boolean('availableOnly'),
            'slotsStatus' => $request->input('slotsStatus'),
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
     * API Lấy danh sách hướng đề tài (dùng cho các bộ lọc "Hướng đề tài")
     */
    public function layDanhSachHuong(Request $request)
    {
        $rows = HuongDeTai::where('trang_thai_hd', 1)
            ->orderBy('ten_huong_de_tai')
            ->get(['huong_de_tai_id', 'ten_huong_de_tai'])
            ->map(function ($h) {
                return [
                    'id' => (string) $h->huong_de_tai_id,
                    'name' => $h->ten_huong_de_tai,
                ];
            });

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $rows,
            ],
        ], 200);
    }

    /**
     * API Xem chi tiết đề tài
     */
    public function xemChiTiet(Request $request, $id)
    {
        $topic = $this->deTaiService->getTopicDetail($id);
        if (! $topic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này!',
            ], 404);
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

        $periodId = $request->query('periodId') ?? $request->input('periodId');
        $dot = $periodId
            ? Dot::find($periodId)
            : Dot::where('loai_dot', 'DATN')->orderBy('dot_id', 'desc')->first();
        if ($resp = $this->chanNeuDotDaDong($dot)) {
            return $resp;
        }

        $topic = $this->deTaiService->createTopic($request->all(), $periodId);

        RealtimeService::broadcast('notification', [
            'title' => 'Đề tài mới được đề xuất',
            'message' => 'Giảng viên '.($topic['teacher'] ?? 'GV').' vừa đề xuất đề tài: '.($topic['name'] ?? ''),
            'type' => 'topic_proposed',
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
     * API Cập nhật đề tài
     */
    public function capNhat(CapNhatDeTaiRequest $request, $id)
    {

        $existing = DeTai::find($id);
        if ($resp = $this->chanNeuDotDaDong($existing?->dot)) {
            return $resp;
        }

        $topic = $this->deTaiService->updateTopic($id, $request->all());
        if (! $topic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này để cập nhật!',
            ], 404);
        }

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
        $user = $request->user();
        $deTai = DeTai::find($id);
        if ($user->tokenCan('ADMIN')) {
            // Admin có toàn quyền, không cần kiểm tra chủ sở hữu đề tài.
        } elseif ($user->tokenCan('GIANG_VIEN')) {
            if ($deTai && $deTai->giang_vien_id !== $user->giang_vien_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền xóa đề tài của giảng viên khác!',
                ], 403);
            }
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền thực hiện thao tác này!',
            ], 403);
        }

        if ($resp = $this->chanNeuDotDaDong($deTai?->dot)) {
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

        RealtimeService::broadcast('slot_updated', [
            'type' => 'topic_deleted',
            'topicId' => $id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Xóa đề tài thành công!',
        ], 200);
    }
}
