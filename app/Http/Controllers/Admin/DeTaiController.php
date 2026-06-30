<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\DeTaiService;
use App\Http\Requests\Admin\QuanLyDeTai\ThemDeTaiRequest;
use App\Http\Requests\Admin\QuanLyDeTai\CapNhatDeTaiRequest;

class DeTaiController extends Controller
{
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
        $filters = [
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
            'periodId' => $request->input('periodId')
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
        $topic = $this->deTaiService->getTopicDetail($id);
        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này!'
            ], 404);
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

        $periodId = $request->query('periodId');

        $topic = $this->deTaiService->createTopic($request->all(), $periodId);

        \App\Services\RealtimeService::broadcast('notification', [
            'title' => 'Đề tài mới được đề xuất',
            'message' => 'Giảng viên ' . ($topic['teacher'] ?? 'GV') . ' vừa đề xuất đề tài: ' . ($topic['name'] ?? ''),
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

        $topic = $this->deTaiService->updateTopic($id, $request->all());
        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này để cập nhật!'
            ], 404);
        }

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
        $user = $request->user();
        if ($user->tokenCan('GIANG_VIEN')) {
            $deTai = \App\Models\DeTai::find($id);
            if ($deTai && $deTai->giang_vien_id !== $user->giang_vien_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền xóa đề tài của giảng viên khác!'
                ], 403);
            }
        } elseif (!$user->tokenCan('ADMIN')) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền thực hiện thao tác này!'
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
}
